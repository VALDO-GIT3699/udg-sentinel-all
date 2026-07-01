<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\BrokenLink;
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
use Modules\Monitoring\Services\EvaluateSiteStatusService;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;

final class RunHeadCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $siteId)
    {
        $this->onQueue((string) env('SENTINEL_QUEUE_UPTIME', 'monitoring-uptime'));
    }

    public function handle(
        SiteRepositoryInterface $siteRepository,
        EvaluateSiteStatusService $evaluateSiteStatusService,
        MonitoringHttpClientFactory $httpClientFactory,
    ): void {
        $site = $siteRepository->findById($this->siteId);

        if (! $site instanceof Site || ! $site->is_active || ! $site->is_monitored) {
            return;
        }

        $timeout = (int) env('SENTINEL_HTTP_TIMEOUT', 10);
        $lockTtlSeconds = max(15, $timeout + 5);
        $lock = Cache::lock('monitoring:head-check:site:' . $site->id, $lockTtlSeconds);

        if (! $lock->get()) {
            // Evita que checks concurrentes del mismo sitio alternen estados en el mismo instante.
            return;
        }

        $started = microtime(true);
        $checkedAt = now()->startOfSecond();

        try {
            $http = $httpClientFactory->make();
            $headResponse = $http->head($site->url);
            $resolvedResponse = $this->resolveResponseForSite($site, $headResponse, $httpClientFactory);

            $latencyMs = (int) round((microtime(true) - $started) * 1000);
            $status = $this->statusFromHttpCode($resolvedResponse->status());

            DB::transaction(function () use ($site, $siteRepository, $evaluateSiteStatusService, $status, $resolvedResponse, $latencyMs, $checkedAt): void {
                $lockedSite = Site::query()->whereKey($site->id)->lockForUpdate()->first();

                if (! $lockedSite instanceof Site || ! $lockedSite->is_active || ! $lockedSite->is_monitored) {
                    return;
                }

                $this->upsertSecondBucketCheck(
                    siteId: (int) $lockedSite->id,
                    checkedAt: $checkedAt,
                    status: $status,
                    httpCode: $resolvedResponse->status(),
                    responseTimeMs: $latencyMs,
                    errorMessage: null,
                );

                $siteRepository->update($lockedSite, [
                    'last_checked_at' => $checkedAt,
                ]);

                $evaluateSiteStatusService->apply($lockedSite, $status, null, $checkedAt);
            });

            $this->recordTrafficSample($site, $latencyMs, $status, $resolvedResponse->status());
            $this->recordBrokenLinksFromSite($site, $resolvedResponse, $httpClientFactory);
        } catch (\Throwable $exception) {
            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            DB::transaction(function () use ($site, $siteRepository, $evaluateSiteStatusService, $latencyMs, $checkedAt, $exception): void {
                $lockedSite = Site::query()->whereKey($site->id)->lockForUpdate()->first();

                if (! $lockedSite instanceof Site || ! $lockedSite->is_active || ! $lockedSite->is_monitored) {
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
        } finally {
            $lock->release();
        }
    }

    private function statusFromHttpCode(int $httpCode): string
    {
        if ($httpCode >= 200 && $httpCode < 400) {
            return 'up';
        }

        if (in_array($httpCode, [401, 403, 405, 429], true)) {
            return 'degraded';
        }

        return 'down';
    }

    private function resolveResponseForSite(Site $site, Response $headResponse, MonitoringHttpClientFactory $httpClientFactory): Response
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

        if (! in_array($headStatus, [400, 401, 403, 405, 429, 500, 502, 503, 504], true)) {
            return $headResponse;
        }

        $fallbackResponse = $httpClientFactory
            ->make([
                'Accept' => 'text/html,*/*;q=0.8',
                'Range' => 'bytes=0-0',
            ])
            ->get($site->url);

        return $fallbackResponse;
    }

    private function upsertSecondBucketCheck(
        int $siteId,
        CarbonInterface $checkedAt,
        string $status,
        ?int $httpCode,
        ?int $responseTimeMs,
        ?string $errorMessage,
    ): void {
        $nextSecond = $checkedAt->copy()->addSecond();

        $existing = SiteCheck::query()
            ->where('site_id', $siteId)
            ->where('checked_at', '>=', $checkedAt)
            ->where('checked_at', '<', $nextSecond)
            ->where('checked_from', 'sentinel-head')
            ->lockForUpdate()
            ->first();

        $payload = [
            'site_id' => $siteId,
            'checked_at' => $checkedAt,
            'status' => $status,
            'http_code' => $httpCode,
            'response_time_ms' => $responseTimeMs,
            'response_size_bytes' => null,
            'ip_resolved' => null,
            'redirect_url' => null,
            'error_message' => $errorMessage,
            'checked_from' => 'sentinel-head',
            'created_at' => now(),
        ];

        if ($existing instanceof SiteCheck) {
            $existing->fill($payload);
            $existing->save();

            return;
        }

        SiteCheck::query()->create($payload);
    }

    private function recordTrafficSample(Site $site, int $latencyMs, string $status, ?int $httpCode): void
    {
        try {
            $errorRate = $status === 'down' ? 100.0 : ($status === 'degraded' ? 35.0 : 0.0);

            TrafficMetric::query()->create([
                'site_id' => (int) $site->id,
                'recorded_at' => now(),
                'requests_per_min' => max(1, (int) round(60000 / max(1, $latencyMs))),
                'unique_visitors' => null,
                'bandwidth_bytes' => null,
                'error_rate_pct' => $errorRate,
                'avg_response_time_ms' => max(1, $latencyMs),
            ]);
        } catch (\Throwable) {
            // Telemetria de trafico no debe interrumpir el check de disponibilidad.
        }
    }

    private function recordBrokenLinksFromSite(Site $site, Response $response, MonitoringHttpClientFactory $httpClientFactory): void
    {
        try {
            if ($response->status() === 404) {
                $this->upsertBrokenLink($site->id, $site->url, '/');
            }

            $html = '';
            if ($response->header('content-type') !== null && str_contains(strtolower((string) $response->header('content-type')), 'text/html')) {
                $html = (string) $response->body();
            }

            if ($html === '') {
                $html = (string) $httpClientFactory
                    ->make(['Accept' => 'text/html,*/*;q=0.8'])
                    ->get($site->url)
                    ->body();
            }

            if ($html === '') {
                return;
            }

            preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches);

            $links = isset($matches[1]) && is_array($matches[1]) ? $matches[1] : [];
            $links = array_values(array_unique($links));

            $checked = 0;
            foreach ($links as $link) {
                if ($checked >= 8) {
                    break;
                }

                if (! str_starts_with($link, '/') && ! str_contains($link, $site->domain)) {
                    continue;
                }

                $absoluteUrl = str_starts_with($link, 'http') ? $link : rtrim($site->url, '/') . '/' . ltrim($link, '/');

                $linkResponse = $httpClientFactory
                    ->make(['Accept' => 'text/html,*/*;q=0.8'])
                    ->get($absoluteUrl);

                $checked++;

                if ($linkResponse->status() !== 404) {
                    continue;
                }

                $path = parse_url($absoluteUrl, PHP_URL_PATH);
                $this->upsertBrokenLink(
                    siteId: (int) $site->id,
                    url: $absoluteUrl,
                    foundOn: is_string($path) && $path !== '' ? $path : '/'
                );
            }
        } catch (\Throwable) {
            // El rastreador de 404 no debe romper el pipeline principal.
        }
    }

    private function upsertBrokenLink(int $siteId, string $url, string $foundOn): void
    {
        $record = BrokenLink::query()->firstOrNew([
            'site_id' => $siteId,
            'url' => $url,
        ]);

        $record->fill([
            'found_on' => $foundOn,
            'http_code' => 404,
            'first_detected_at' => $record->exists ? $record->first_detected_at : now(),
            'last_checked_at' => now(),
            'is_resolved' => false,
            'resolved_at' => null,
        ]);

        $record->save();
    }
}
