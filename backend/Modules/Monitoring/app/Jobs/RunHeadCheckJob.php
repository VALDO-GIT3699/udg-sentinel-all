<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\TrafficMetric;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Monitoring\Events\MonitoringCompleted;
use Modules\Monitoring\Services\EvaluateSiteStatusService;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;
use Modules\Monitoring\Support\HttpFailureClassifier;
use Modules\Monitoring\Support\MassScanProgress;

final class RunHeadCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $siteId,
        private readonly ?string $massScanRunId = null,
        private readonly bool $forceScan = false,
    ) {
        $this->onQueue((string) env('SENTINEL_QUEUE_UPTIME', 'monitoring-uptime'));
    }

    public function handle(
        SiteRepositoryInterface $siteRepository,
        EvaluateSiteStatusService $evaluateSiteStatusService,
        MonitoringHttpClientFactory $httpClientFactory,
    ): void {
        $shouldCompleteTask = true;
        $lockAcquired = false;

        try {
            $site = $siteRepository->findById($this->siteId);

            if (! $site instanceof Site || (! $this->forceScan && (! $site->is_active || ! $site->is_monitored))) {
                return;
            }

            $timeout = (int) env('SENTINEL_HTTP_TIMEOUT', 10);
            $lockTtlSeconds = max(15, $timeout + 5);
            $lock = Cache::lock('monitoring:head-check:site:'.$site->id, $lockTtlSeconds);
            $lockAcquired = (bool) $lock->get();

            if (! $lockAcquired) {
                // Evita que checks concurrentes del mismo sitio alternen estados en el mismo instante.
                // En escaneo masivo forzado no debemos saltar el sitio: reintentamos breve y si no,
                // se reencola para evitar falsos "sin actualizar".
                if (! $this->forceScan) {
                    return;
                }

                try {
                    $lockAcquired = (bool) $lock->block(8);
                } catch (\Throwable) {
                    $lockAcquired = false;
                }

                if (! $lockAcquired) {
                    $shouldCompleteTask = false;

                    self::dispatch($this->siteId, $this->massScanRunId, $this->forceScan)
                        ->delay(now()->addSeconds(20));

                    return;
                }
            }

            $started = microtime(true);
            $checkedAt = now();

            try {
                $tlsIssueDetected = false;

                try {
                    $headResponse = $httpClientFactory->make()->head($site->url);
                } catch (\Throwable $exception) {
                    if (! HttpFailureClassifier::isTlsFailure($exception)) {
                        throw $exception;
                    }

                    // Certificado invalido/expirado/no confiable: el sitio puede seguir respondiendo,
                    // asi que reintentamos solo esta peticion con verificacion TLS relajada.
                    $tlsIssueDetected = true;
                    $headResponse = $httpClientFactory->make([], 'default', true)->head($site->url);
                }

                $resolvedResponse = $this->resolveResponseForSite($site, $headResponse, $httpClientFactory, $tlsIssueDetected);
                $redirectContext = $this->extractRedirectContext($site, $resolvedResponse, $httpClientFactory, $tlsIssueDetected);

                $latencyMs = (int) round((microtime(true) - $started) * 1000);
                $status = $this->statusFromOutcome($resolvedResponse->status(), $redirectContext);
                $statusMessage = $this->statusMessageFromRedirectContext($redirectContext, $status);

                if ($tlsIssueDetected) {
                    $statusMessage = trim(($statusMessage !== null ? $statusMessage.' ' : '').'Advertencia: certificado TLS invalido, no confiable o expirado (el sitio si responde).');
                }

                DB::transaction(function () use ($site, $siteRepository, $evaluateSiteStatusService, $status, $resolvedResponse, $latencyMs, $checkedAt, $statusMessage, $redirectContext): void {
                    $lockedSite = Site::query()->whereKey($site->id)->lockForUpdate()->first();

                    if (! $lockedSite instanceof Site || (! $this->forceScan && (! $lockedSite->is_active || ! $lockedSite->is_monitored))) {
                        return;
                    }

                    $this->upsertSecondBucketCheck(
                        siteId: (int) $lockedSite->id,
                        checkedAt: $checkedAt,
                        status: $status,
                        httpCode: $resolvedResponse->status(),
                        responseTimeMs: $latencyMs,
                        errorMessage: $statusMessage,
                        redirectUrl: (bool) ($redirectContext['is_redirect'] ?? false)
                            ? (string) ($redirectContext['final_url'] ?? '')
                            : null,
                    );

                    $siteRepository->update($lockedSite, [
                        'last_checked_at' => $checkedAt,
                    ]);

                    $evaluateSiteStatusService->apply($lockedSite, $status, $statusMessage, $checkedAt);
                });

                $this->recordTrafficSample($site, $latencyMs, $status, $resolvedResponse->status());

                MonitoringCompleted::dispatch(
                    siteId: (int) $site->id,
                    status: $status,
                    httpCode: $resolvedResponse->status(),
                    responseTimeMs: $latencyMs,
                    checkedAt: $checkedAt->toIso8601String(),
                );
            } catch (\Throwable $exception) {
                $latencyMs = (int) round((microtime(true) - $started) * 1000);

                if (is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                    MassScanProgress::recordFailure(
                        $this->massScanRunId,
                        'uptime',
                        $this->siteId,
                        mb_substr($exception->getMessage(), 0, 1000),
                    );
                }

                DB::transaction(function () use ($site, $siteRepository, $evaluateSiteStatusService, $latencyMs, $checkedAt, $exception): void {
                    $lockedSite = Site::query()->whereKey($site->id)->lockForUpdate()->first();

                    if (! $lockedSite instanceof Site || (! $this->forceScan && (! $lockedSite->is_active || ! $lockedSite->is_monitored))) {
                        return;
                    }

                    $this->upsertSecondBucketCheck(
                        siteId: (int) $lockedSite->id,
                        checkedAt: $checkedAt,
                        status: 'down',
                        httpCode: null,
                        responseTimeMs: $latencyMs,
                        errorMessage: mb_substr($exception->getMessage(), 0, 1000),
                    );

                    $siteRepository->update($lockedSite, [
                        'last_checked_at' => $checkedAt,
                    ]);

                    $evaluateSiteStatusService->apply($lockedSite, 'down', $exception->getMessage(), $checkedAt);
                });

                $this->recordTrafficSample($site, $latencyMs, 'down', null);

                MonitoringCompleted::dispatch(
                    siteId: (int) $site->id,
                    status: 'down',
                    httpCode: null,
                    responseTimeMs: $latencyMs,
                    checkedAt: $checkedAt->toIso8601String(),
                );
            } finally {
                if ($lockAcquired) {
                    $lock->release();
                }
            }
        } finally {
            if ($shouldCompleteTask && is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                MassScanProgress::completeTask($this->massScanRunId, 'uptime', $this->siteId);
            }
        }
    }

    private function statusFromHttpCode(int $httpCode): string
    {
        if ($httpCode >= 200 && $httpCode < 400) {
            return 'up';
        }

        if ($httpCode >= 400 && $httpCode < 500) {
            // Error de cliente (403/404/429/etc.): el servidor si responde, es una
            // incidencia, no una caida.
            return 'degraded';
        }

        // 5xx (o cualquier otro codigo fuera de 2xx/3xx/4xx): el origen fallo al
        // atender la peticion, eso si es una caida real.
        return 'down';
    }

    /**
     * @param  array<string, mixed>  $redirectContext
     */
    private function statusFromOutcome(int $httpCode, array $redirectContext): string
    {
        $baseStatus = $this->statusFromHttpCode($httpCode);

        if (! (bool) ($redirectContext['is_redirect'] ?? false)) {
            return $baseStatus;
        }

        if ((bool) ($redirectContext['is_external'] ?? false)) {
            return (bool) ($redirectContext['final_accessible'] ?? false) ? 'degraded' : 'down';
        }

        return $baseStatus;
    }

    /**
     * @param  array<string, mixed>  $redirectContext
     */
    private function statusMessageFromRedirectContext(array $redirectContext, string $status): ?string
    {
        if (! (bool) ($redirectContext['is_redirect'] ?? false)) {
            return null;
        }

        if ((bool) ($redirectContext['is_external'] ?? false)) {
            $finalUrl = (string) ($redirectContext['final_url'] ?? '');
            $finalStatus = (int) ($redirectContext['final_status'] ?? 0);

            if ($status === 'down') {
                return sprintf('Redireccion externa no accesible hacia %s (HTTP %d).', $finalUrl !== '' ? $finalUrl : 'destino desconocido', $finalStatus);
            }

            return sprintf('Redireccion externa detectada hacia %s (HTTP %d). Estado degradado por cambio de portal.', $finalUrl !== '' ? $finalUrl : 'destino desconocido', $finalStatus);
        }

        return null;
    }

    private function resolveResponseForSite(Site $site, Response $headResponse, MonitoringHttpClientFactory $httpClientFactory, bool $relaxTls = false): Response
    {
        $domain = mb_strtolower((string) $site->domain);
        $isUdgDomain = $domain === 'udg.mx' || str_ends_with($domain, '.udg.mx');
        $headStatus = $headResponse->status();

        if ($headStatus >= 200 && $headStatus < 400) {
            return $headResponse;
        }

        if (! $isUdgDomain) {
            return $headResponse;
        }

        $response = $headResponse;

        if (in_array($headStatus, [400, 401, 403, 405, 429, 500, 502, 503, 504], true)) {
            $response = $httpClientFactory
                ->make([
                    'Accept' => 'text/html,*/*;q=0.8',
                    'Range' => 'bytes=0-0',
                ], 'default', $relaxTls)
                ->get($site->url);

            if ($response->status() >= 200 && $response->status() < 400) {
                return $response;
            }
        }

        return $this->resolveAlternateHostResponse($site, $response, $httpClientFactory, $relaxTls);
    }

    private function resolveAlternateHostResponse(Site $site, Response $currentResponse, MonitoringHttpClientFactory $httpClientFactory, bool $relaxTls = false): Response
    {
        if ($currentResponse->status() >= 200 && $currentResponse->status() < 400) {
            return $currentResponse;
        }

        $siteUrl = (string) $site->url;
        $parts = parse_url($siteUrl);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return $currentResponse;
        }

        $host = mb_strtolower((string) $parts['host']);

        if ($host !== 'udg.mx' && ! str_ends_with($host, '.udg.mx')) {
            return $currentResponse;
        }

        $alternateHost = str_starts_with($host, 'www.')
            ? mb_substr($host, 4)
            : 'www.'.$host;

        if ($alternateHost === '' || $alternateHost === $host) {
            return $currentResponse;
        }

        $alternateUrl = $this->replaceHostInUrl($siteUrl, $alternateHost);

        if ($alternateUrl === null) {
            return $currentResponse;
        }

        try {
            $alternateResponse = $httpClientFactory
                ->make(['Accept' => 'text/html,*/*;q=0.8'], 'default', $relaxTls)
                ->head($alternateUrl);

            if ($alternateResponse->status() >= 200 && $alternateResponse->status() < 400) {
                return $alternateResponse;
            }

            if (in_array($alternateResponse->status(), [400, 401, 403, 405, 429, 500, 502, 503, 504], true)) {
                $alternateGet = $httpClientFactory
                    ->make([
                        'Accept' => 'text/html,*/*;q=0.8',
                        'Range' => 'bytes=0-0',
                    ], 'default', $relaxTls)
                    ->get($alternateUrl);

                if ($alternateGet->status() >= 200 && $alternateGet->status() < 400) {
                    return $alternateGet;
                }

                return $alternateGet;
            }

            return $alternateResponse;
        } catch (\Throwable) {
            return $currentResponse;
        }
    }

    private function replaceHostInUrl(string $url, string $newHost): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'])) {
            return null;
        }

        $scheme = (string) $parts['scheme'];
        $user = isset($parts['user']) ? (string) $parts['user'] : null;
        $pass = isset($parts['pass']) ? (string) $parts['pass'] : null;
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $query = isset($parts['query']) ? '?'.(string) $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.(string) $parts['fragment'] : '';

        $credentials = '';

        if ($user !== null && $user !== '') {
            $credentials = $user;

            if ($pass !== null && $pass !== '') {
                $credentials .= ':'.$pass;
            }
            $credentials .= '@';
        }

        $portSuffix = $port !== null ? ':'.$port : '';

        return sprintf('%s://%s%s%s%s%s', $scheme, $credentials, $newHost, $portSuffix, $path, $query.$fragment);
    }

    private function upsertSecondBucketCheck(
        int $siteId,
        CarbonInterface $checkedAt,
        string $status,
        ?int $httpCode,
        ?int $responseTimeMs,
        ?string $errorMessage,
        ?string $redirectUrl = null,
    ): void {
        $payload = [
            'site_id' => $siteId,
            'checked_at' => $checkedAt,
            'status' => $status,
            'http_code' => $httpCode,
            'response_time_ms' => $responseTimeMs,
            'response_size_bytes' => null,
            'ip_resolved' => null,
            'redirect_url' => $redirectUrl,
            'error_message' => $errorMessage,
            'checked_from' => 'sentinel-head',
            'created_at' => now(),
        ];

        SiteCheck::query()->create($payload);
    }

    private function recordTrafficSample(Site $site, int $latencyMs, string $status, ?int $httpCode): void
    {
        try {
            TrafficMetric::query()->create([
                'site_id' => (int) $site->id,
                'recorded_at' => now(),
                'requests_per_min' => null,
                'unique_visitors' => null,
                'bandwidth_bytes' => null,
                'error_rate_pct' => $status === 'down' ? 100.0 : ($status === 'degraded' ? 35.0 : 0.0),
                'avg_response_time_ms' => max(1, $latencyMs),
            ]);
        } catch (\Throwable) {
            // Telemetria de trafico no debe interrumpir el check de disponibilidad.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRedirectContext(Site $site, Response $response, MonitoringHttpClientFactory $httpClientFactory, bool $relaxTls = false): array
    {
        $siteUrl = (string) $site->url;
        $initialStatus = 0;
        $initialLocation = null;

        try {
            $initialResponse = $httpClientFactory
                ->make(['Accept' => 'text/html,*/*;q=0.8'], 'default', $relaxTls)
                ->withoutRedirecting()
                ->head($siteUrl);

            $initialStatus = (int) $initialResponse->status();
            $initialLocation = $initialResponse->header('location');
        } catch (\Throwable) {
            // Seguimos con la respuesta final si no se pudo obtener sonda inicial.
        }

        $finalStatus = (int) $response->status();
        $finalUrl = $this->resolveFinalUrl($response, $siteUrl);
        $initialHost = parse_url($siteUrl, PHP_URL_HOST);
        $finalHost = parse_url($finalUrl, PHP_URL_HOST);
        $isExternal = is_string($initialHost)
            && is_string($finalHost)
            && $initialHost !== ''
            && $finalHost !== ''
            && mb_strtolower($initialHost) !== mb_strtolower($finalHost);

        return [
            'initial_url' => $siteUrl,
            'initial_status' => $initialStatus,
            'initial_location' => $initialLocation,
            'final_url' => $finalUrl,
            'final_status' => $finalStatus,
            'is_redirect' => in_array($initialStatus, [301, 302, 307, 308], true),
            'is_external' => $isExternal,
            'final_accessible' => $finalStatus >= 200 && $finalStatus < 400,
            'redirect_history' => $this->extractRedirectHistory($response),
        ];
    }

    private function resolveFinalUrl(Response $response, string $fallbackUrl): string
    {
        $effectiveUrl = method_exists($response, 'effectiveUri') ? (string) $response->effectiveUri() : '';

        if ($effectiveUrl !== '') {
            return $effectiveUrl;
        }

        $history = $this->extractRedirectHistory($response);

        if ($history !== []) {
            $last = end($history);

            return is_string($last) && $last !== '' ? $last : $fallbackUrl;
        }

        return $fallbackUrl;
    }

    /**
     * @return array<int, string>
     */
    private function extractRedirectHistory(Response $response): array
    {
        $value = $response->header('X-Guzzle-Redirect-History');

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(static fn (string $part): string => trim($part), explode(',', $value))));
    }
}
