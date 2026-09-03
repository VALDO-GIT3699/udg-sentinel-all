<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\CmsDetail;
use App\Models\DrupalModule;
use App\Models\OfficialBaselineSite;
use App\Models\OfficialBaselineSnapshot;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SiteTechnology;
use App\Models\Technology;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Monitoring\Events\TechnologyChanged;
use Modules\Monitoring\Events\TechnologyStackChanged;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;
use Modules\Monitoring\Support\DrupalFingerprint;
use Modules\Monitoring\Support\HttpFailureClassifier;
use Modules\Monitoring\Support\MassScanProgress;

final class RunTechnologyScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        private readonly int $siteId,
        private readonly ?string $massScanRunId = null,
        private readonly bool $forceScan = false,
    ) {
        $this->onQueue((string) env('SENTINEL_QUEUE_TECH', 'default'));
    }

    public function handle(SiteRepositoryInterface $siteRepository, MonitoringHttpClientFactory $httpClientFactory): void
    {
        try {
            $site = $siteRepository->findById($this->siteId);

            if (! $site instanceof Site || (! $this->forceScan && ! $site->is_monitored)) {
                return;
            }

            try {
                $tlsIssueDetected = false;

                try {
                    $response = $httpClientFactory
                        ->make(['Accept' => 'text/html,*/*;q=0.8'])
                        ->get($site->url);
                } catch (ConnectionException $exception) {
                    if (! HttpFailureClassifier::isTlsFailure($exception)) {
                        Log::warning('Monitoring: no fue posible conectar para detectar tecnologias.', [
                            'site_id' => $this->siteId,
                            'run_id' => $this->massScanRunId,
                            'error' => $exception->getMessage(),
                        ]);

                        if (is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                            MassScanProgress::recordFailure(
                                $this->massScanRunId,
                                'technology',
                                $this->siteId,
                                mb_substr($exception->getMessage(), 0, 1000),
                            );
                        }

                        return;
                    }

                    // Certificado invalido/expirado/no confiable: reintentamos solo el fingerprint
                    // con verificacion TLS relajada, sin afectar el resto del pipeline.
                    $tlsIssueDetected = true;

                    try {
                        $response = $httpClientFactory
                            ->make(['Accept' => 'text/html,*/*;q=0.8'], 'default', true)
                            ->get($site->url);
                    } catch (\Throwable $relaxedException) {
                        Log::warning('Monitoring: certificado TLS invalido y tampoco fue posible conectar en modo relajado.', [
                            'site_id' => $this->siteId,
                            'run_id' => $this->massScanRunId,
                            'error' => $relaxedException->getMessage(),
                        ]);

                        if (is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                            MassScanProgress::recordFailure(
                                $this->massScanRunId,
                                'technology',
                                $this->siteId,
                                mb_substr($relaxedException->getMessage(), 0, 1000),
                            );
                        }

                        return;
                    }
                }

                $fingerprint = $this->buildFingerprint($site, $response, $httpClientFactory, $tlsIssueDetected);
                $classification = $this->classifyPrimaryTechnology($fingerprint);
                $detected = $this->detectTechnologies($fingerprint, $classification);

                $previousSlugs = [];
                $detectedSlugs = [];

                DB::transaction(function () use ($site, $fingerprint, $detected, $classification, &$previousSlugs, &$detectedSlugs): void {
                    /** @var string[] $existingSlugs */
                    $existingSlugs = SiteTechnology::where('site_id', $site->id)
                        ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
                        ->pluck('technologies.slug')
                        ->unique()
                        ->values()
                        ->all();

                    $previousSlugs = $existingSlugs;

                    $existingEntries = SiteTechnology::query()
                        ->where('site_id', $site->id)
                        ->get()
                        ->keyBy('technology_id');

                    $retainedTechnologyIds = [];

                    foreach ($detected as $item) {
                        $technologyLabel = trim((string) ($item['name'] ?? '').' '.(string) ($item['version'] ?? ''));

                        Log::info(sprintf('Escribiendo tecnología para %s: %s', (string) $site->domain, $technologyLabel !== '' ? $technologyLabel : (string) ($item['name'] ?? 'unknown')), [
                            'site_id' => $site->id,
                            'technology_slug' => $item['slug'] ?? null,
                            'technology_version' => $item['version'] ?? null,
                            'confidence_pct' => $item['confidence_pct'] ?? null,
                        ]);

                        $technology = Technology::firstOrCreate(
                            ['slug' => $item['slug']],
                            [
                                'name' => $item['name'],
                                'category' => $item['category'],
                                'vendor' => $item['vendor'],
                            ],
                        );

                        $retainedTechnologyIds[] = (int) $technology->id;

                        $siteTechnology = $existingEntries->get((int) $technology->id) ?? new SiteTechnology([
                            'site_id' => $site->id,
                            'technology_id' => $technology->id,
                        ]);

                        $siteTechnology->forceFill([
                            'site_id' => $site->id,
                            'technology_id' => $technology->id,
                            'version' => $item['version'],
                            'confidence_pct' => $item['confidence_pct'],
                            'is_primary' => $item['is_primary'],
                            'detected_at' => now(),
                            'detection_method' => $item['detection_method'],
                            'metadata' => $item['metadata'],
                        ])->save();
                    }

                    SiteTechnology::query()
                        ->where('site_id', $site->id)
                        ->when($retainedTechnologyIds !== [], static function ($query) use ($retainedTechnologyIds): void {
                            $query->whereNotIn('technology_id', $retainedTechnologyIds);
                        }, static function ($query): void {
                            $query->whereRaw('1 = 1');
                        })
                        ->delete();

                    $detectedSlugs = array_values(array_unique(array_column($detected, 'slug')));

                    $this->persistCmsDetail((int) $site->id, $fingerprint, $classification);
                });

                $added = array_values(array_diff($detectedSlugs, $previousSlugs));
                $removed = array_values(array_diff($previousSlugs, $detectedSlugs));

                if ($added !== [] || $removed !== []) {
                    TechnologyStackChanged::dispatch(
                        (int) $site->id,
                        $added,
                        $removed,
                        $detected,
                        now()->toIso8601String(),
                    );

                    TechnologyChanged::dispatch(
                        siteId: (int) $site->id,
                        added: $added,
                        removed: $removed,
                        detectedAt: now()->toIso8601String(),
                    );
                }

                $this->recordBaselineDriftIfNeeded(
                    $site,
                    $detected,
                    is_string($fingerprint['official_baseline_cms'] ?? null) ? $fingerprint['official_baseline_cms'] : null,
                );
            } catch (\Throwable $exception) {
                // El scanner de tecnologia no debe romper el pipeline de monitoreo.

                if (is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                    MassScanProgress::recordFailure(
                        $this->massScanRunId,
                        'technology',
                        $this->siteId,
                        $exception->getMessage(),
                    );
                }
            }
        } finally {
            if (is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                MassScanProgress::completeTask($this->massScanRunId, 'technology', $this->siteId);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array<int, array<string, mixed>>
     */
    private function detectTechnologies(array $fingerprint, array $classification): array
    {
        $detected = [];
        $redirectContext = $fingerprint['redirect_context'] ?? [];
        $redirectMetadata = [
            'redirect' => [
                'is_redirect' => (bool) ($redirectContext['is_redirect'] ?? false),
                'is_external' => (bool) ($redirectContext['is_external'] ?? false),
                'initial_url' => $redirectContext['initial_url'] ?? null,
                'final_url' => $redirectContext['final_url'] ?? null,
                'final_status' => $redirectContext['final_status'] ?? null,
                'final_accessible' => (bool) ($redirectContext['final_accessible'] ?? false),
            ],
        ];

        if (($classification['slug'] ?? null) === 'inactivo') {
            return [];
        }

        if (($classification['slug'] ?? null) === 'dominio-no-configurado') {
            return [];
        }

        if (is_string($classification['slug'] ?? null) && ($classification['slug'] ?? '') !== '') {
            $detected[] = [
                'slug' => (string) $classification['slug'],
                'name' => (string) ($classification['label'] ?? 'No determinado'),
                'category' => (string) ($classification['category'] ?? 'cms'),
                'vendor' => (string) ($classification['vendor'] ?? 'unknown'),
                'version' => $classification['version'] ?? null,
                'confidence_pct' => (int) ($classification['confidence'] ?? 0),
                'is_primary' => true,
                'detection_method' => 'conservative-evidence-model',
                'metadata' => [
                    'matched' => $classification['evidence'] ?? [],
                    'audit' => $classification['audit'] ?? [],
                ] + $redirectMetadata,
            ];

            if (($classification['cms_type'] ?? null) === 'drupal' && str_starts_with((string) ($classification['slug'] ?? ''), 'drupal-')) {
                $detected[] = [
                    'slug' => 'drupal',
                    'name' => 'Drupal',
                    'category' => 'cms',
                    'vendor' => 'Drupal Association',
                    'version' => $classification['version'] ?? null,
                    'confidence_pct' => max(1, ((int) ($classification['confidence'] ?? 0)) - 4),
                    'is_primary' => false,
                    'detection_method' => 'conservative-evidence-model',
                    'metadata' => [
                        'matched' => $classification['evidence'] ?? [],
                        'alias_of' => $classification['slug'] ?? null,
                    ] + $redirectMetadata,
                ];
            }
        }

        $phpTechnology = $this->classifyPhpTechnology($fingerprint);

        if (is_array($phpTechnology) && ($classification['slug'] ?? null) !== 'php') {
            $detected[] = [
                'slug' => $phpTechnology['slug'],
                'name' => $phpTechnology['label'],
                'category' => 'language',
                'vendor' => 'PHP Group',
                'version' => $phpTechnology['version'],
                'confidence_pct' => $phpTechnology['confidence'],
                'is_primary' => false,
                'detection_method' => 'conservative-evidence-model',
                'metadata' => [
                    'matched' => $phpTechnology['evidence'],
                ] + $redirectMetadata,
            ];
        }

        $server = is_array($fingerprint['server'] ?? null) ? $fingerprint['server'] : null;

        if (is_array($server) && is_string($server['slug'] ?? null) && trim((string) $server['slug']) !== '') {
            $detected[] = [
                'slug' => (string) $server['slug'],
                'name' => (string) ($server['name'] ?? 'Servidor Web'),
                'category' => 'web-server',
                'vendor' => (string) ($server['vendor'] ?? 'unknown'),
                'version' => $server['version'] ?? null,
                'confidence_pct' => 82,
                'is_primary' => false,
                'detection_method' => 'server-header',
                'metadata' => ['server' => $server['header'] ?? null] + $redirectMetadata,
            ];
        }

        $database = is_array($fingerprint['database'] ?? null) ? $fingerprint['database'] : null;

        if (is_array($database) && is_string($database['slug'] ?? null) && trim((string) $database['slug']) !== '') {
            $detected[] = [
                'slug' => (string) $database['slug'],
                'name' => (string) ($database['name'] ?? 'Base de datos'),
                'category' => 'database',
                'vendor' => (string) ($database['vendor'] ?? 'unknown'),
                'version' => $database['version'] ?? null,
                'confidence_pct' => (int) ($database['confidence'] ?? 70),
                'is_primary' => false,
                'detection_method' => 'error-signature-fingerprint',
                'metadata' => ['matched' => $database['evidence'] ?? []] + $redirectMetadata,
            ];
        }

        $cmsType = is_string($classification['cms_type'] ?? null) ? mb_strtolower((string) $classification['cms_type']) : null;

        foreach ((array) ($fingerprint['custom_themes'] ?? []) as $themeName) {
            $normalizedThemeName = trim((string) $themeName);

            if ($normalizedThemeName === '') {
                continue;
            }

            $detected[] = [
                'slug' => $this->scopedSlug(($cmsType ?? 'web').'-theme', $normalizedThemeName),
                'name' => sprintf('Theme %s', $normalizedThemeName),
                'category' => 'theme',
                'vendor' => 'custom',
                'version' => null,
                'confidence_pct' => 79,
                'is_primary' => false,
                'detection_method' => 'asset-path-fingerprint',
                'metadata' => ['theme' => $normalizedThemeName, 'cms' => $cmsType] + $redirectMetadata,
            ];
        }

        foreach ((array) ($fingerprint['custom_modules'] ?? []) as $moduleName) {
            $normalizedModuleName = trim((string) $moduleName);

            if ($normalizedModuleName === '') {
                continue;
            }

            $detected[] = [
                'slug' => $this->scopedSlug(($cmsType ?? 'web').'-module', $normalizedModuleName),
                'name' => sprintf('Module %s', $normalizedModuleName),
                'category' => 'module',
                'vendor' => 'custom',
                'version' => null,
                'confidence_pct' => 78,
                'is_primary' => false,
                'detection_method' => 'asset-path-fingerprint',
                'metadata' => ['module' => $normalizedModuleName, 'cms' => $cmsType] + $redirectMetadata,
            ];
        }

        return array_values($this->uniqueDetectedTechnologies($detected));
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>
     */
    private function classifyPrimaryTechnology(array $fingerprint): array
    {
        $availability = $this->assessAvailability($fingerprint);

        if (! (bool) ($availability['is_available'] ?? false)) {
            return [
                'slug' => 'inactivo',
                'label' => 'Inactivo',
                'category' => 'status',
                'vendor' => 'n/a',
                'version' => null,
                'confidence' => 99,
                'evidence' => $availability['failed_checks'] ?? [],
                'audit' => ['availability' => $availability],
                'cms_type' => 'inactive',
                'cms_version' => null,
            ];
        }

        if ($this->looksLikeUnconfiguredVhostPlaceholder((string) ($fingerprint['body_raw'] ?? ''))) {
            return [
                'slug' => 'dominio-no-configurado',
                'label' => 'Dominio no configurado',
                'category' => 'status',
                'vendor' => 'n/a',
                'version' => null,
                'confidence' => 97,
                'evidence' => ['vhost-placeholder-page'],
                'audit' => ['availability' => $availability],
                'cms_type' => 'unconfigured',
                'cms_version' => null,
            ];
        }

        /** @var array<string, array<int, string>> $headers */
        $headers = is_array($fingerprint['headers'] ?? null) ? $fingerprint['headers'] : [];
        $bodyRaw = (string) ($fingerprint['body_raw'] ?? '');
        $body = mb_strtolower($bodyRaw);
        /** @var array<int, array<string, mixed>> $probes */
        $probes = is_array($fingerprint['probes'] ?? null) ? $fingerprint['probes'] : [];
        $combinedProbeBodies = implode("\n", array_map(static fn (array $probe): string => (string) ($probe['body_raw'] ?? ''), $probes));
        $redirectContext = is_array($fingerprint['redirect_context'] ?? null) ? $fingerprint['redirect_context'] : [];
        $finalHost = mb_strtolower((string) (parse_url((string) ($redirectContext['final_url'] ?? ''), PHP_URL_HOST) ?? ''));
        $setCookie = isset($headers['set-cookie']) ? mb_strtolower(implode(' ', (array) $headers['set-cookie'])) : '';
        $poweredBy = isset($headers['x-powered-by']) ? mb_strtolower(implode(' ', (array) $headers['x-powered-by'])) : '';

        $candidates = [];

        $wixEvidence = [];

        if ($finalHost !== '' && (str_contains($finalHost, 'wixsite.com') || str_contains($finalHost, 'wix.com'))) {
            $wixEvidence[] = 'redirect-host-wix';
        }

        if (isset($headers['x-wix-request-id']) || isset($headers['x-wix-punisher'])) {
            $wixEvidence[] = 'x-wix-header';
        }

        if (str_contains($body."\n".mb_strtolower($combinedProbeBodies), 'wixstatic.com')
            || str_contains($body."\n".mb_strtolower($combinedProbeBodies), 'static.parastorage.com')
            || str_contains($body, 'wixsite')) {
            $wixEvidence[] = 'wix-static';
        }

        if (count(array_unique($wixEvidence)) >= 2) {
            $candidates[] = [
                'slug' => 'wix',
                'label' => 'Wix',
                'category' => 'cms',
                'vendor' => 'Wix.com Ltd.',
                'version' => null,
                'confidence' => min(99, 48 + count(array_unique($wixEvidence)) * 16),
                'evidence' => array_values(array_unique($wixEvidence)),
                'audit' => ['availability' => $availability],
                'cms_type' => 'wix',
                'cms_version' => null,
            ];
        }

        $wordpressEvidence = [];

        if (str_contains($body, 'wp-content')) {
            $wordpressEvidence[] = 'wp-content';
        }

        if (str_contains($body, 'wp-includes')) {
            $wordpressEvidence[] = 'wp-includes';
        }

        if (str_contains($body, '/wp-json/') || str_contains($body, 'api.w.org')) {
            $wordpressEvidence[] = 'wp-json';
        }

        if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']\s*wordpress\b/i', $bodyRaw) === 1) {
            $wordpressEvidence[] = 'meta-generator-wordpress';
        }

        if (isset($headers['x-pingback']) && str_contains(mb_strtolower(implode(' ', (array) $headers['x-pingback'])), 'xmlrpc.php')) {
            $wordpressEvidence[] = 'x-pingback-xmlrpc';
        }

        foreach ($probes as $probe) {
            $path = mb_strtolower((string) ($probe['path'] ?? ''));
            $status = (int) ($probe['status'] ?? 0);

            if ($status === 200
                && in_array($path, ['/wp-json/', '/wp-login.php', '/wp-content/', '/wp-includes/'], true)) {
                $wordpressEvidence[] = 'probe-'.ltrim($path, '/');
            }
        }
        $uniqueWordpressEvidence = array_values(array_unique($wordpressEvidence));
        $wordpressStrongSignals = array_values(array_intersect($uniqueWordpressEvidence, [
            'meta-generator-wordpress',
            'x-pingback-xmlrpc',
            'wp-json',
            'probe-wp-json/',
            'probe-wp-login.php',
        ]));

        if (count($uniqueWordpressEvidence) >= 2 && (count($wordpressStrongSignals) >= 1 || count($uniqueWordpressEvidence) >= 3)) {
            $candidates[] = [
                'slug' => 'wordpress',
                'label' => 'WordPress',
                'category' => 'cms',
                'vendor' => 'WordPress Foundation',
                'version' => null,
                'confidence' => min(98, 40 + count($uniqueWordpressEvidence) * 10 + count($wordpressStrongSignals) * 8),
                'evidence' => $uniqueWordpressEvidence,
                'audit' => ['availability' => $availability],
                'cms_type' => 'wordpress',
                'cms_version' => null,
            ];
        }

        $drupalEvidence = [];
        $xGenerator = isset($headers['x-generator']) ? mb_strtolower(implode(' ', (array) $headers['x-generator'])) : '';
        $xDrupalCache = isset($headers['x-drupal-cache']) ? mb_strtolower(implode(' ', (array) $headers['x-drupal-cache'])) : '';
        $generatorMeta = mb_strtolower($this->extractGeneratorMeta($bodyRaw));

        if (str_contains($generatorMeta, 'drupal')) {
            $drupalEvidence[] = 'meta-generator-drupal';
        }

        if (str_contains($xGenerator, 'drupal')) {
            $drupalEvidence[] = 'x-generator-drupal';
        }

        if ($xDrupalCache !== '') {
            $drupalEvidence[] = 'x-drupal-cache';
        }

        if (str_contains($body, 'drupal-settings-json') || str_contains($body, '/misc/drupal.js')) {
            $drupalEvidence[] = 'drupal-js-signature';
        }

        if (str_contains($body, '/sites/default/files') || str_contains($body, '/sites/all/modules/')) {
            $drupalEvidence[] = 'drupal-sites-path';
        }

        foreach ($probes as $probe) {
            $path = mb_strtolower((string) ($probe['path'] ?? ''));
            $status = (int) ($probe['status'] ?? 0);

            if ($status === 200 && in_array($path, ['/core/lib/drupal.php', '/core/changelog.txt'], true)) {
                $drupalEvidence[] = 'probe-'.ltrim($path, '/');
            }
        }
        $drupalEvidence = array_values(array_unique($drupalEvidence));

        if (count($drupalEvidence) >= 2) {
            $drupalFingerprint = DrupalFingerprint::detect($headers, $bodyRaw, $probes);
            $drupalVersion = $this->resolveDrupalDetectedVersion($bodyRaw, $probes, $drupalFingerprint);

            // Forzamos a que $drupalMajor sea puramente el número entero (ej: "10")
            $drupalMajor = $this->resolveDrupalMajorVersion($drupalVersion, $probes);

            if ($drupalMajor !== null) {
                // Limpieza de seguridad: si por error trae "Drupal", dejamos solo el número
                $drupalMajorClean = preg_replace('/[^0-9]/', '', (string) $drupalMajor);

                // Si la versión detallada es igual a la mayor (ej: "10" y "10"),
                // o si viene vacía, usamos solo el número limpio.
                $finalVersion = ($drupalVersion !== '' && $drupalVersion !== $drupalMajorClean)
                    ? trim($drupalVersion)
                    : $drupalMajorClean;

                $candidates[] = [
                    'slug' => 'drupal-'.$drupalMajorClean,
                    'label' => 'Drupal '.$drupalMajorClean, // Asegura "Drupal 10" estrictamente
                    'category' => 'cms',
                    'vendor' => 'Drupal Association',
                    'version' => $finalVersion,
                    'confidence' => min(99, 50 + count($drupalEvidence) * 12),
                    'evidence' => $drupalEvidence,
                    'audit' => ['availability' => $availability],
                    'cms_type' => 'drupal',
                    'cms_version' => $finalVersion,
                ];
            } else {
                // Hay evidencia suficiente de Drupal pero no se pudo resolver la version mayor:
                // se conserva como Drupal generico en vez de descartar la evidencia por completo.
                $candidates[] = [
                    'slug' => 'drupal',
                    'label' => 'Drupal (version no determinada)',
                    'category' => 'cms',
                    'vendor' => 'Drupal Association',
                    'version' => null,
                    'confidence' => min(95, 45 + count($drupalEvidence) * 12),
                    'evidence' => $drupalEvidence,
                    'audit' => ['availability' => $availability, 'version_resolution' => 'failed'],
                    'cms_type' => 'drupal',
                    'cms_version' => null,
                ];
            }
        }

        $laravelEvidence = [];

        if (str_contains($poweredBy, 'laravel')) {
            $laravelEvidence[] = 'x-powered-by-laravel';
        }

        if (str_contains($setCookie, 'laravel_session')) {
            $laravelEvidence[] = 'laravel-session-cookie';
        }

        if (str_contains($setCookie, 'xsrf-token') || str_contains($setCookie, 'x-xsrf-token')) {
            $laravelEvidence[] = 'xsrf-cookie';
        }

        if (str_contains($body, '/vendor/livewire') || str_contains($body, '/sanctum/csrf-cookie')) {
            $laravelEvidence[] = 'laravel-runtime-path';
        }

        // Laravel permite renombrar la cookie de sesion (SESSION_COOKIE): la combinacion de
        // XSRF-TOKEN + una cookie "*_session" (no PHPSESSID) + runtime PHP es evidencia valida,
        // pero una cookie "*_session" aislada nunca debe bastar por si sola.
        $hasXsrfCookie = str_contains($setCookie, 'xsrf-token') || str_contains($setCookie, 'x-xsrf-token');
        $hasRenamedSessionCookie = str_contains($setCookie, 'session=') && ! str_contains($setCookie, 'phpsessid');
        $hasPhpRuntimeEvidence = ($fingerprint['php_version'] ?? null) !== null
            || (is_array($fingerprint['php_evidence'] ?? null) && $fingerprint['php_evidence'] !== []);

        if ($hasXsrfCookie && $hasRenamedSessionCookie && $hasPhpRuntimeEvidence) {
            $laravelEvidence[] = 'xsrf-with-renamed-session-cookie-and-php';
        }

        if (count(array_unique($laravelEvidence)) >= 2) {
            $candidates[] = [
                'slug' => 'laravel',
                'label' => 'Laravel',
                'category' => 'framework',
                'vendor' => 'Laravel LLC',
                'version' => null,
                'confidence' => min(97, 44 + count(array_unique($laravelEvidence)) * 13),
                'evidence' => array_values(array_unique($laravelEvidence)),
                'audit' => ['availability' => $availability],
                'cms_type' => 'laravel',
                'cms_version' => null,
            ];
        }

        if ($candidates === []) {
            return [
                'slug' => 'no-determinado',
                'label' => 'No determinado',
                'category' => 'cms',
                'vendor' => 'unknown',
                'version' => null,
                'confidence' => 55,
                'evidence' => ['sin-firmas-concluyentes'],
                'audit' => ['availability' => $availability],
                'cms_type' => null,
                'cms_version' => null,
            ];
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => (int) ($right['confidence'] ?? 0) <=> (int) ($left['confidence'] ?? 0),
        );

        $top = $candidates[0];
        $second = $candidates[1] ?? null;

        if (is_array($second) && abs((int) ($top['confidence'] ?? 0) - (int) ($second['confidence'] ?? 0)) < 10) {
            return [
                'slug' => 'no-determinado',
                'label' => 'No determinado',
                'category' => 'cms',
                'vendor' => 'unknown',
                'version' => null,
                'confidence' => 58,
                'evidence' => ['evidencia-ambigua'],
                'audit' => ['availability' => $availability, 'candidates' => $candidates],
                'cms_type' => null,
                'cms_version' => null,
            ];
        }

        return $top;
    }

    /**
     * Placeholder de vhost sin sitio configurado en el hosting compartido de UDG.
     * Exige la combinacion de 3 frases distintivas (no solo un hash) para evitar
     * falsos positivos contra un sitio real que casualmente coincida en una sola.
     */
    private function looksLikeUnconfiguredVhostPlaceholder(string $bodyRaw): bool
    {
        $body = mb_strtolower($bodyRaw);

        $hasTitle = str_contains($body, 'p&aacute;gina web no encontrada') || str_contains($body, 'página web no encontrada');
        $hasInstruction = str_contains($body, 'por favor escriba de forma correcta la p');
        $hasFallbackLink = str_contains($body, 'href="http://www.udg.mx/"') && str_contains($body, 'sitio web disponible');

        return $hasTitle && $hasInstruction && $hasFallbackLink;
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>|null
     */
    private function classifyPhpTechnology(array $fingerprint): ?array
    {
        $phpVersion = is_string($fingerprint['php_version'] ?? null) ? $fingerprint['php_version'] : null;
        $phpEvidence = is_array($fingerprint['php_evidence'] ?? null) ? $fingerprint['php_evidence'] : [];
        $phpPathEvidence = is_array($fingerprint['php_path_evidence'] ?? null) ? $fingerprint['php_path_evidence'] : [];

        $evidence = array_values(array_unique(array_filter(array_merge($phpEvidence, $phpPathEvidence))));

        if ($phpVersion === null && count($evidence) < 2) {
            return null;
        }

        return [
            'slug' => 'php',
            'label' => 'PHP',
            'version' => $phpVersion,
            'confidence' => $phpVersion !== null ? 92 : 76,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>
     */
    private function assessAvailability(array $fingerprint): array
    {
        $redirectContext = is_array($fingerprint['redirect_context'] ?? null) ? $fingerprint['redirect_context'] : [];
        $bodyRaw = (string) ($fingerprint['body_raw'] ?? '');
        $httpOk = ((int) ($redirectContext['final_status'] ?? 0)) >= 200 && ((int) ($redirectContext['final_status'] ?? 0)) < 400;
        $redirectOk = ! (bool) ($redirectContext['is_redirect'] ?? false)
            || (is_string($redirectContext['final_url'] ?? null) && trim((string) $redirectContext['final_url']) !== '');
        $contentOk = trim($bodyRaw) !== '';

        $checks = [
            'http_response' => $httpOk,
            'redirect_resolution' => $redirectOk,
            'content' => $contentOk,
        ];

        $failedChecks = [];

        foreach ($checks as $check => $ok) {
            if (! $ok) {
                $failedChecks[] = $check;
            }
        }

        $isAvailable = $httpOk && $redirectOk && $contentOk;

        return [
            'is_available' => $isAvailable,
            'checks' => $checks,
            'failed_checks' => $failedChecks,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $probes
     */
    private function resolveDrupalMajorVersion(string $drupalVersion, array $probes): ?int
    {
        if ($drupalVersion !== '' && preg_match('/^([0-9]{1,2})(?:\.|$)/', $drupalVersion, $matches) === 1) {
            $major = (int) $matches[1];

            return in_array($major, [6, 7, 8, 9, 10], true) ? $major : null;
        }

        foreach ($probes as $probe) {
            $path = mb_strtolower((string) ($probe['path'] ?? ''));
            $status = (int) ($probe['status'] ?? 0);

            if ($status !== 200) {
                continue;
            }

            if ($path === '/core/themes/stable10/version') {
                return 10;
            }

            if ($path === '/core/themes/stable9/version') {
                return 9;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $probes
     * @param  array<string, mixed>|null  $drupalFingerprint
     */
    private function resolveDrupalDetectedVersion(string $bodyRaw, array $probes, ?array $drupalFingerprint = null): string
    {
        $probeBodies = implode("\n", array_map(static fn (array $probe): string => (string) ($probe['body_raw'] ?? ''), $probes));
        $exactVersion = $this->firstVersionMatch(
            [$bodyRaw, $probeBodies],
            ['/drupal\s+([0-9]+(?:\.[0-9]+){1,2})/i', '/version\s+([0-9]+(?:\.[0-9]+){1,2})/i'],
        );

        if (is_string($exactVersion) && trim($exactVersion) !== '') {
            return trim($exactVersion);
        }

        return is_array($drupalFingerprint)
            ? trim((string) ($drupalFingerprint['version'] ?? ''))
            : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFingerprint(Site $site, Response $response, MonitoringHttpClientFactory $httpClientFactory, bool $relaxTls = false): array
    {
        $headers = array_change_key_case($response->headers(), CASE_LOWER);
        $bodyRaw = (string) $response->body();
        $redirectContext = $this->extractRedirectContext($site, $response, $httpClientFactory, $relaxTls);
        $isExternalRedirect = (bool) ($redirectContext['is_external'] ?? false);
        $isFinalAccessible = (bool) ($redirectContext['final_accessible'] ?? false);

        if ($isExternalRedirect && ! $isFinalAccessible) {
            return [
                'headers' => $headers,
                'body_raw' => $bodyRaw,
                'redirect_context' => $redirectContext,
                'probes' => [],
                'official_baseline_cms' => $this->resolveOfficialBaselineCms($site),
                'php_version' => null,
                'php_evidence' => [],
                'php_path_evidence' => [],
                'server' => ['slug' => null, 'name' => null, 'vendor' => null, 'version' => null, 'header' => null],
                'database' => null,
                'custom_themes' => [],
                'custom_modules' => [],
            ];
        }

        $probeBaseUrl = $isExternalRedirect
            && is_string($redirectContext['final_url'] ?? null)
            && trim((string) ($redirectContext['final_url'] ?? '')) !== ''
            ? (string) $redirectContext['final_url']
            : (string) $site->url;
        $probes = $this->probePublicPaths(
            $probeBaseUrl,
            $httpClientFactory,
            $this->determineProbePaths($headers, $bodyRaw, $redirectContext),
            $relaxTls,
        );
        $successfulProbes = array_values(array_filter(
            $probes,
            static fn (array $probe): bool => (int) ($probe['status'] ?? 0) === 200,
        ));
        $texts = array_merge([$bodyRaw], array_map(static fn (array $probe): string => $probe['body_raw'], $successfulProbes));
        $headerBags = array_merge([$headers], array_map(static fn (array $probe): array => $probe['headers'], $probes));
        $phpPathEvidence = $this->detectPhpPathEvidence((string) ($redirectContext['final_url'] ?? $site->url), array_merge([$bodyRaw], $texts));
        $officialBaselineCms = $this->resolveOfficialBaselineCms($site);

        return [
            'headers' => $headers,
            'body_raw' => $bodyRaw,
            'redirect_context' => $redirectContext,
            'probes' => $probes,
            'official_baseline_cms' => $officialBaselineCms,
            'php_version' => $this->detectPhpVersion($headerBags, $texts),
            'php_evidence' => $this->phpEvidence($headerBags),
            'php_path_evidence' => $phpPathEvidence,
            'server' => $this->detectServer($headerBags),
            'database' => $this->detectDatabase($texts),
            'custom_themes' => $this->extractCustomThemes(implode("\n", $texts)),
            'custom_modules' => $this->extractCustomModules(implode("\n", $texts), null),
        ];
    }

    private function resolveOfficialBaselineCms(Site $site): ?string
    {
        $snapshotId = $this->currentOfficialBaselineSnapshotId();

        if ($snapshotId === null) {
            return null;
        }

        $domain = mb_strtolower(trim((string) $site->domain));

        if ($domain === '') {
            return null;
        }

        $baselineCms = $this->officialBaselineCmsMap($snapshotId)[$domain] ?? null;

        return is_string($baselineCms) && trim($baselineCms) !== '' ? trim($baselineCms) : null;
    }

    private function currentOfficialBaselineSnapshotId(): ?int
    {
        $snapshotId = Cache::remember('monitoring:official-baseline:current-snapshot-id', now()->addMinutes(5), static function () {
            return OfficialBaselineSnapshot::query()
                ->where('is_current', true)
                ->value('id');
        });

        return is_numeric($snapshotId) ? (int) $snapshotId : null;
    }

    /**
     * @return array<string, string>
     */
    private function officialBaselineCmsMap(int $snapshotId): array
    {
        /** @var array<string, string> $map */
        $map = Cache::remember(
            'monitoring:official-baseline:cms-map:'.$snapshotId,
            now()->addMinutes(5),
            static function () use ($snapshotId): array {
                return OfficialBaselineSite::query()
                    ->where('snapshot_id', $snapshotId)
                    ->whereNotNull('normalized_domain')
                    ->get(['normalized_domain', 'cms_label'])
                    ->mapWithKeys(static function (OfficialBaselineSite $site): array {
                        $domain = mb_strtolower(trim((string) $site->normalized_domain));
                        $cmsLabel = trim((string) $site->cms_label);

                        return $domain !== '' && $cmsLabel !== '' ? [$domain => $cmsLabel] : [];
                    })
                    ->all();
            },
        );

        return $map;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @param  array<string, mixed>  $redirectContext
     * @return array<int, string>
     */
    private function determineProbePaths(array $headers, string $bodyRaw, array $redirectContext): array
    {
        $body = mb_strtolower($bodyRaw);
        $paths = [];

        if ($this->hasWordPressBaseSignature($headers, $body, [])) {
            array_push($paths, '/wp-json/', '/wp-login.php');
        }

        if ($this->hasDrupalBaseSignature($headers, $bodyRaw, [])) {
            array_push($paths, '/core/lib/Drupal.php', '/core/CHANGELOG.txt', '/core/themes/stable10/VERSION', '/core/themes/stable9/VERSION');
        }

        if (! $this->hasWixBaseSignature($headers, $body, '') && $paths === []) {
            array_push($paths, '/wp-json/', '/core/lib/Drupal.php');
        }

        if ((bool) ($redirectContext['is_external'] ?? false)) {
            $paths = array_values(array_filter($paths, static fn (string $path): bool => ! str_starts_with($path, '/wp-login')));
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function probePublicPaths(string $baseUrl, MonitoringHttpClientFactory $httpClientFactory, array $paths, bool $relaxTls = false): array
    {
        if ($paths === []) {
            return [];
        }

        $client = $httpClientFactory->make([
            'Accept' => 'text/plain,text/html,*/*;q=0.8',
            'X-UDG-Sentinel-Probe' => 'technology-scan',
        ], 'default', $relaxTls);

        $responses = $client->pool(function (Pool $pool) use ($baseUrl, $paths): array {
            $requests = [];

            foreach ($paths as $path) {
                $requests[$path] = $pool
                    ->as($path)
                    ->get($this->buildProbeUrl($baseUrl, $path));
            }

            return $requests;
        });

        $probes = [];

        foreach ($responses as $path => $probeResponse) {
            if (! $probeResponse instanceof Response) {
                continue;
            }

            $probes[] = [
                'path' => (string) $path,
                'status' => $probeResponse->status(),
                'headers' => array_change_key_case($probeResponse->headers(), CASE_LOWER),
                'body_raw' => $this->truncateBody((string) $probeResponse->body()),
            ];
        }

        return $probes;
    }

    private function buildProbeUrl(string $siteUrl, string $path): string
    {
        return rtrim($siteUrl, '/').'/'.ltrim($path, '/');
    }

    private function truncateBody(string $body): string
    {
        return mb_substr($body, 0, 12000);
    }

    /**
     * @param  array<int, array<string, array<int, string>>>  $headerBags
     * @param  array<int, string>  $texts
     */
    private function detectPhpVersion(array $headerBags, array $texts): ?string
    {
        foreach ($headerBags as $headers) {
            $poweredBy = isset($headers['x-powered-by']) ? implode(' ', $headers['x-powered-by']) : '';
            $server = isset($headers['server']) ? implode(' ', $headers['server']) : '';

            foreach ([$poweredBy, $server] as $source) {
                if (preg_match('/php\/?\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $source, $matches) === 1) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, array<int, string>>>  $headerBags
     * @return array<int, string>
     */
    private function phpEvidence(array $headerBags): array
    {
        $evidence = [];

        foreach ($headerBags as $headers) {
            if (isset($headers['x-powered-by'])) {
                $evidence[] = implode(' ', $headers['x-powered-by']);
            }

            if (isset($headers['server']) && preg_match('/php/i', implode(' ', $headers['server'])) === 1) {
                $evidence[] = implode(' ', $headers['server']);
            }

            // Evidencia secundaria: no confirma PHP por si sola (classifyPhpTechnology exige
            // version real o >=2 senales), pero refuerza cuando se combina con otra evidencia.
            if (isset($headers['set-cookie']) && preg_match('/phpsessid/i', implode(' ', (array) $headers['set-cookie'])) === 1) {
                $evidence[] = 'cookie-phpsessid';
            }
        }

        return array_values(array_unique(array_filter($evidence)));
    }

    /**
     * @param  array<int, array<string, array<int, string>>>  $headerBags
     * @return array<string, string|null>
     */
    private function detectServer(array $headerBags): array
    {
        foreach ($headerBags as $headers) {
            $serverHeader = isset($headers['server']) ? trim(implode(' ', $headers['server'])) : '';

            if ($serverHeader === '') {
                continue;
            }

            if (preg_match('/(nginx|apache|openresty|caddy|iis)(?:\/([0-9.]+))?/i', $serverHeader, $matches) === 1) {
                $name = mb_strtolower($matches[1]);

                return match ($name) {
                    'apache' => ['slug' => 'apache', 'name' => 'Apache HTTP Server', 'vendor' => 'Apache Software Foundation', 'version' => $matches[2] ?? null, 'header' => $serverHeader],
                    'openresty' => ['slug' => 'openresty', 'name' => 'OpenResty', 'vendor' => 'OpenResty Inc.', 'version' => $matches[2] ?? null, 'header' => $serverHeader],
                    'caddy' => ['slug' => 'caddy', 'name' => 'Caddy', 'vendor' => 'Caddy', 'version' => $matches[2] ?? null, 'header' => $serverHeader],
                    'iis' => ['slug' => 'iis', 'name' => 'Microsoft IIS', 'vendor' => 'Microsoft', 'version' => $matches[2] ?? null, 'header' => $serverHeader],
                    default => ['slug' => 'nginx', 'name' => 'Nginx', 'vendor' => 'F5', 'version' => $matches[2] ?? null, 'header' => $serverHeader],
                };
            }

            return ['slug' => 'server-software', 'name' => 'Servidor Web', 'vendor' => 'unknown', 'version' => null, 'header' => $serverHeader];
        }

        return ['slug' => null, 'name' => null, 'vendor' => null, 'version' => null, 'header' => null];
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @param  array<int, array<string, mixed>>  $probes
     */
    private function hasDrupalBaseSignature(array $headers, string $bodyRaw, array $probes): bool
    {
        $body = mb_strtolower($bodyRaw);
        $xGenerator = isset($headers['x-generator']) ? mb_strtolower(implode(' ', $headers['x-generator'])) : '';
        $xDrupalCache = isset($headers['x-drupal-cache']) ? mb_strtolower(implode(' ', $headers['x-drupal-cache'])) : '';
        $generatorMeta = mb_strtolower($this->extractGeneratorMeta($bodyRaw));

        if (
            str_contains($generatorMeta, 'drupal')
            || str_contains($xGenerator, 'drupal')
            || $xDrupalCache !== ''
            || str_contains($body, 'drupal-settings-json')
            || str_contains($body, '/sites/default/files')
            || str_contains($body, '/sites/all/themes/')
            || str_contains($body, '/sites/all/modules/')
            || str_contains($body, '/misc/drupal.js')
        ) {
            return true;
        }

        foreach ($probes as $probe) {
            $path = mb_strtolower((string) ($probe['path'] ?? ''));
            $status = (int) ($probe['status'] ?? 0);

            if ($status !== 200) {
                continue;
            }

            if (
                in_array($path, [
                    '/core/lib/drupal.php',
                    '/core/themes/stable9/version',
                    '/core/themes/stable10/version',
                    '/core/themes/stable11/version',
                    '/core/changelog.txt',
                ], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    private function hasWordPressBaseSignature(array $headers, string $body, array $probes): bool
    {
        if (
            str_contains($body, 'wp-content')
            || str_contains($body, 'wp-includes')
            || str_contains($body, '/wp-json/')
            || str_contains($body, 'api.w.org')
        ) {
            return true;
        }

        if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']\s*wordpress\b/i', $body) === 1) {
            return true;
        }

        foreach ($probes as $probe) {
            $path = mb_strtolower((string) ($probe['path'] ?? ''));
            $status = (int) ($probe['status'] ?? 0);

            if ($status !== 200) {
                continue;
            }

            if (in_array($path, ['/wp-json/', '/wp-login.php'], true)) {
                return true;
            }
        }

        if (isset($headers['x-pingback']) && str_contains(mb_strtolower(implode(' ', $headers['x-pingback'])), 'xmlrpc.php')) {
            return true;
        }

        return false;
    }

    private function hasLaravelBaseSignature(string $poweredBy, string $body, string $setCookie, array $headers): bool
    {
        if (str_contains($poweredBy, 'laravel')) {
            return true;
        }

        $hasLaravelSession = str_contains($setCookie, 'laravel_session');
        $hasXsrf = str_contains($setCookie, 'xsrf-token');
        $hasLivewire = str_contains($body, '/vendor/livewire');
        $hasSanctum = str_contains($body, '/sanctum/csrf-cookie') || str_contains($setCookie, 'x-xsrf-token');

        if ($hasLaravelSession && ($hasXsrf || $hasLivewire || $hasSanctum)) {
            return true;
        }

        if (isset($headers['set-cookie']) && $hasLaravelSession && $hasXsrf) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $detected
     */
    private function recordBaselineDriftIfNeeded(Site $site, array $detected, ?string $baselineCms = null): void
    {
        $domain = mb_strtolower(trim((string) $site->domain));

        if ($domain === '') {
            return;
        }

        if (! is_string($baselineCms) || trim($baselineCms) === '') {
            return;
        }

        $detectedPrimaryCms = null;

        foreach ($detected as $item) {
            if (($item['category'] ?? null) === 'cms') {
                $detectedSlug = mb_strtolower((string) ($item['slug'] ?? ''));
                $detectedName = mb_strtolower((string) ($item['name'] ?? ''));

                if (in_array($detectedSlug, ['no-determinado', 'inactivo'], true) || in_array($detectedName, ['no determinado', 'inactivo'], true)) {
                    return;
                }

                if ($detectedSlug !== '' && str_starts_with($detectedSlug, 'drupal-')) {
                    $detectedPrimaryCms = 'drupal';
                } elseif (str_contains($detectedName, 'drupal')) {
                    $detectedPrimaryCms = 'drupal';
                } elseif (str_contains($detectedSlug, 'wordpress') || str_contains($detectedName, 'wordpress')) {
                    $detectedPrimaryCms = 'wordpress';
                } elseif (str_contains($detectedSlug, 'laravel') || str_contains($detectedName, 'laravel')) {
                    $detectedPrimaryCms = 'laravel';
                } elseif (str_contains($detectedSlug, 'wix') || str_contains($detectedName, 'wix')) {
                    $detectedPrimaryCms = 'wix';
                } else {
                    $detectedPrimaryCms = $detectedName;
                }

                break;
            }
        }

        if ($detectedPrimaryCms === null || $detectedPrimaryCms === '') {
            return;
        }

        $normalizedBaselineCms = mb_strtolower(trim($baselineCms));
        $normalizedBaselineCms = str_contains($normalizedBaselineCms, 'wordpress')
            ? 'wordpress'
            : (str_contains($normalizedBaselineCms, 'drupal') ? 'drupal' : $normalizedBaselineCms);

        if ($normalizedBaselineCms === $detectedPrimaryCms) {
            return;
        }

        SiteEvent::record(
            siteId: (int) $site->id,
            eventType: 'monitoring.baseline_drift',
            title: 'Incongruencia con Base Real',
            severity: 'warning',
            description: sprintf('Baseline reporta %s, detección actual reporta %s. Requiere validación humana.', $baselineCms, ucfirst($detectedPrimaryCms)),
            metadata: [
                'baseline_cms' => $baselineCms,
                'detected_cms' => $detectedPrimaryCms,
                'domain' => $domain,
            ],
            occurredAt: now(),
        );
    }

    /**
     * @param  array<int, string>  $sources
     * @return array<int, string>
     */
    private function detectPhpPathEvidence(string $finalUrl, array $sources): array
    {
        $evidence = [];

        $finalPath = mb_strtolower((string) (parse_url($finalUrl, PHP_URL_PATH) ?? ''));

        if ($finalPath !== '' && str_ends_with($finalPath, '.php')) {
            $evidence[] = 'final-url-php-path';
        }

        foreach ($sources as $source) {
            if (preg_match('/\b(?:href|src|action)=["\'][^"\']+\.php(?:[?#][^"\']*)?["\']/i', $source) === 1) {
                $evidence[] = 'html-link-php-path';
                break;
            }
        }

        return array_values(array_unique($evidence));
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    private function hasWixBaseSignature(array $headers, string $body, string $combinedProbeBodies): bool
    {
        $combined = $body."\n".mb_strtolower($combinedProbeBodies);

        if (
            str_contains($combined, 'wixsite')
            || str_contains($combined, 'wix-code')
            || str_contains($combined, 'static.parastorage.com')
            || str_contains($combined, 'wixstatic.com')
        ) {
            return true;
        }

        return isset($headers['x-wix-request-id']) || isset($headers['x-wix-punisher']);
    }

    private function extractGeneratorMeta(string $bodyRaw): string
    {
        if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)["\']/i', $bodyRaw, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * @param  array<int, string>  $sources
     * @param  array<int, string>  $patterns
     */
    private function firstVersionMatch(array $sources, array $patterns): ?string
    {
        foreach ($sources as $source) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source, $matches) === 1) {
                    return $matches[1];
                }
            }
        }

        return null;
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
                ->get($siteUrl);

            $initialStatus = (int) $initialResponse->status();
            $initialLocation = $initialResponse->header('location');
        } catch (\Throwable) {
            // Si falla la sonda inicial, seguimos con la respuesta final ya obtenida.
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

    /**
     * @param  array<int, string>  $sources
     * @return array<string, mixed>|null
     */
    private function detectDatabase(array $sources): ?array
    {
        $rules = [
            [
                'slug' => 'postgresql',
                'name' => 'PostgreSQL',
                'vendor' => 'PostgreSQL Global Development Group',
                'confidence' => 74,
                'patterns' => ['/sqlstate\[[0-9a-z]+\].*postgres/i', '/pdoexception.*pgsql/i', '/org\.postgresql\./i'],
            ],
            [
                'slug' => 'mariadb',
                'name' => 'MariaDB',
                'vendor' => 'MariaDB Foundation',
                'confidence' => 72,
                'patterns' => ['/sqlstate\[[0-9a-z]+\].*mariadb/i', '/pdoexception.*mariadb/i'],
            ],
            [
                'slug' => 'mysql',
                'name' => 'MySQL',
                'vendor' => 'Oracle',
                'confidence' => 70,
                'patterns' => ['/sqlstate\[[0-9a-z]+\].*mysql/i', '/pdoexception.*mysql/i', '/mysqli?_(?:connect|query|prepare)/i'],
            ],
            [
                'slug' => 'sqlserver',
                'name' => 'Microsoft SQL Server',
                'vendor' => 'Microsoft',
                'confidence' => 68,
                'patterns' => ['/sqlstate\[[0-9a-z]+\].*sql server/i', '/pdoexception.*sqlsrv/i', '/microsoft sql server/i'],
            ],
        ];

        foreach ($rules as $rule) {
            $evidence = [];

            foreach ($sources as $source) {
                foreach ($rule['patterns'] as $pattern) {
                    if (preg_match($pattern, $source) === 1) {
                        $evidence[] = $pattern;
                    }
                }
            }

            if ($evidence !== []) {
                return [
                    'slug' => $rule['slug'],
                    'name' => $rule['name'],
                    'vendor' => $rule['vendor'],
                    'version' => null,
                    'confidence' => $rule['confidence'],
                    'evidence' => array_values(array_unique($evidence)),
                ];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractCustomThemes(string $text): array
    {
        $matches = [];
        preg_match_all('#/(?:wp-content/themes|sites/all/themes|themes/custom|themes)/([a-z0-9_-]+)#i', $text, $matches);

        if (! isset($matches[1]) || ! is_array($matches[1])) {
            return [];
        }

        $defaultDrupalThemes = ['bartik', 'claro', 'olivero', 'seven', 'stark', 'stable', 'stable9', 'classy', 'starterkit_theme'];

        $themes = array_filter(array_map(static fn (string $name): string => mb_strtolower($name), $matches[1]), static function (string $name) use ($defaultDrupalThemes): bool {
            return ! in_array($name, $defaultDrupalThemes, true) && ! preg_match('/^twenty[a-z0-9]*$/', $name);
        });

        return array_values(array_unique($themes));
    }

    /**
     * @return array<int, string>
     */
    private function extractCustomModules(string $text, ?string $cmsType): array
    {
        $matches = [];
        preg_match_all('#/(?:modules/custom|sites/all/modules/custom)/([a-z0-9_-]+)#i', $text, $matches);

        $modules = [];

        if (isset($matches[1]) && is_array($matches[1])) {
            foreach ($matches[1] as $moduleName) {
                $modules[] = mb_strtolower($moduleName);
            }
        }

        foreach (['drudg8b3', 'bootr4theme'] as $signature) {
            if (str_contains(mb_strtolower($text), $signature)) {
                $modules[] = $signature;
            }
        }

        return array_values(array_unique($modules));
    }

    private function scopedSlug(string $scope, string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($scope.'-'.$value)) ?? ($scope.'-'.$value);
        $slug = trim($slug, '-');

        return mb_substr($slug, 0, 100);
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    private function persistCmsDetail(int $siteId, array $fingerprint, array $classification): void
    {
        $phpVersion = $fingerprint['php_version'];
        $database = $fingerprint['database'];
        $server = $fingerprint['server'];
        $themes = $fingerprint['custom_themes'];
        $modules = $fingerprint['custom_modules'];
        $cmsType = $classification['cms_type'] ?? null;
        $cmsVersion = $classification['cms_version'] ?? null;

        $cmsDetail = CmsDetail::updateOrCreate(
            ['site_id' => $siteId],
            [
                'cms_type' => is_string($cmsType) ? $cmsType : null,
                'cms_version' => is_string($cmsVersion) ? $cmsVersion : null,
                'db_type' => $database['name'] ?? null,
                'db_version' => $database['version'] ?? null,
                'php_version' => $phpVersion,
                'php_is_vulnerable' => $phpVersion !== null ? version_compare($phpVersion, '8.1.0', '<') : false,
                'server_software' => $server['header'] ?? null,
                'theme_name' => $themes[0] ?? null,
                'theme_version' => null,
                'modules_count' => count($modules),
                'has_updates' => false,
                'has_security_updates' => false,
                'last_scanned_at' => now(),
            ],
        );

        if ($cmsType === 'drupal') {
            $this->captureDrupalModuleHints($cmsDetail, $modules);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $detected
     * @return array<int, array<string, mixed>>
     */
    private function uniqueDetectedTechnologies(array $detected): array
    {
        $unique = [];

        foreach ($detected as $item) {
            $slug = mb_strtolower(trim((string) ($item['slug'] ?? '')));

            if ($slug === '') {
                continue;
            }

            if (! isset($unique[$slug]) || ((int) ($item['confidence_pct'] ?? 0)) > ((int) ($unique[$slug]['confidence_pct'] ?? 0))) {
                $unique[$slug] = $item;
            }
        }

        return array_values($unique);
    }

    /**
     * @param  array<int, string>  $moduleNames
     */
    private function captureDrupalModuleHints(CmsDetail $cmsDetail, array $moduleNames): void
    {
        if ($moduleNames === []) {
            DrupalModule::query()
                ->where('cms_detail_id', $cmsDetail->id)
                ->delete();

            return;
        }

        foreach ($moduleNames as $moduleName) {
            DrupalModule::updateOrCreate(
                ['cms_detail_id' => $cmsDetail->id, 'module_name' => $moduleName],
                [
                    'module_version' => null,
                    'is_enabled' => true,
                    'is_core' => false,
                    'project_url' => null,
                    'has_update_available' => false,
                    'security_update_available' => false,
                ],
            );
        }

        DrupalModule::query()
            ->where('cms_detail_id', $cmsDetail->id)
            ->whereNotIn('module_name', $moduleNames)
            ->delete();
    }
}
