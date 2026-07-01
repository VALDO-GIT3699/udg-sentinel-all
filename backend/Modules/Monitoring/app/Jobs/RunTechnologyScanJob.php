<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\CmsDetail;
use App\Models\DrupalModule;
use App\Models\Site;
use App\Models\SiteTechnology;
use App\Models\Technology;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Monitoring\Events\TechnologyStackChanged;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;

final class RunTechnologyScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(private readonly int $siteId)
    {
        $this->onQueue((string) env('SENTINEL_QUEUE_TECH', 'monitoring-tech'));
    }

    public function handle(SiteRepositoryInterface $siteRepository, MonitoringHttpClientFactory $httpClientFactory): void
    {
        $site = $siteRepository->findById($this->siteId);

        if (! $site instanceof Site || ! $site->is_active || ! $site->is_monitored) {
            return;
        }

        try {
            $response = $httpClientFactory
                ->make(['Accept' => 'text/html,*/*;q=0.8'])
                ->get($site->url);

            $fingerprint = $this->buildFingerprint($site, $response, $httpClientFactory);
            $detected = $this->detectTechnologies($fingerprint);

            /** @var string[] $previousSlugs */
            $previousSlugs = SiteTechnology::where('site_id', $site->id)
                ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
                ->pluck('technologies.slug')
                ->unique()
                ->values()
                ->all();

            /** @var string[] $detectedSlugs */
            $detectedSlugs = array_unique(array_column($detected, 'slug'));

            foreach ($detected as $item) {
                $technology = Technology::firstOrCreate(
                    ['slug' => $item['slug']],
                    [
                        'name' => $item['name'],
                        'category' => $item['category'],
                        'vendor' => $item['vendor'],
                    ]
                );

                SiteTechnology::create([
                    'site_id' => $site->id,
                    'technology_id' => $technology->id,
                    'version' => $item['version'],
                    'confidence_pct' => $item['confidence_pct'],
                    'is_primary' => $item['is_primary'],
                    'detected_at' => now(),
                    'detection_method' => $item['detection_method'],
                    'metadata' => $item['metadata'],
                ]);
            }

            $this->persistCmsDetail($site->id, $fingerprint);

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
            }
        } catch (\Throwable) {
            // El scanner de tecnologia no debe romper el pipeline de monitoreo.
        }
    }

    /**
     * @param array<string, mixed> $fingerprint
     * @return array<int, array<string, mixed>>
     */
    private function detectTechnologies(array $fingerprint): array
    {
        $detected = [];
        $cms = $fingerprint['cms'];
        $phpVersion = $fingerprint['php_version'];
        $server = $fingerprint['server'];
        $database = $fingerprint['database'];

        if (($cms['type'] ?? null) === 'laravel') {
            $detected[] = [
                'slug' => 'laravel',
                'name' => 'Laravel',
                'category' => 'framework',
                'vendor' => 'Laravel LLC',
                'version' => $cms['version'] ?? null,
                'confidence_pct' => $cms['confidence'] ?? 85,
                'is_primary' => true,
                'detection_method' => 'passive-http-fingerprint',
                'metadata' => [
                    'matched' => $cms['evidence'] ?? [],
                    'php_version' => $phpVersion,
                    'server' => $server['header'] ?? null,
                ],
            ];
        }

        if (($cms['type'] ?? null) === 'drupal') {
            $detected[] = [
                'slug' => 'drupal',
                'name' => 'Drupal',
                'category' => 'cms',
                'vendor' => 'Drupal Association',
                'version' => $cms['version'] ?? null,
                'confidence_pct' => $cms['confidence'] ?? 90,
                'is_primary' => true,
                'detection_method' => 'passive-http-fingerprint',
                'metadata' => [
                    'matched' => $cms['evidence'] ?? [],
                    'php_version' => $phpVersion,
                    'custom_themes' => $fingerprint['custom_themes'],
                    'custom_modules' => $fingerprint['custom_modules'],
                ],
            ];
        }

        if (($cms['type'] ?? null) === 'wordpress') {
            $detected[] = [
                'slug' => 'wordpress',
                'name' => 'WordPress',
                'category' => 'cms',
                'vendor' => 'WordPress Foundation',
                'version' => $cms['version'] ?? null,
                'confidence_pct' => $cms['confidence'] ?? 88,
                'is_primary' => true,
                'detection_method' => 'passive-http-fingerprint',
                'metadata' => [
                    'matched' => $cms['evidence'] ?? [],
                    'php_version' => $phpVersion,
                    'custom_themes' => $fingerprint['custom_themes'],
                ],
            ];
        }

        if (($server['slug'] ?? null) !== null) {
            $detected[] = [
                'slug' => $server['slug'],
                'name' => $server['name'],
                'category' => 'web-server',
                'vendor' => $server['vendor'],
                'version' => $server['version'],
                'confidence_pct' => 82,
                'is_primary' => false,
                'detection_method' => 'server-header',
                'metadata' => ['server' => $server['header']],
            ];
        }

        if ($phpVersion !== null || ($fingerprint['php_evidence'] ?? []) !== []) {
            $detected[] = [
                'slug' => 'php',
                'name' => 'PHP',
                'category' => 'language',
                'vendor' => 'PHP Group',
                'version' => $phpVersion,
                'confidence_pct' => $phpVersion !== null ? 92 : 74,
                'is_primary' => false,
                'detection_method' => 'x-powered-by',
                'metadata' => ['matched' => $fingerprint['php_evidence'] ?? []],
            ];
        }

        if ($database !== null) {
            $detected[] = [
                'slug' => $database['slug'],
                'name' => $database['name'],
                'category' => 'database',
                'vendor' => $database['vendor'],
                'version' => $database['version'],
                'confidence_pct' => $database['confidence'],
                'is_primary' => false,
                'detection_method' => 'passive-http-fingerprint',
                'metadata' => ['matched' => $database['evidence']],
            ];
        }

        foreach ($fingerprint['custom_themes'] as $themeName) {
            $detected[] = [
                'slug' => $this->scopedSlug(($cms['type'] ?? 'web') . '-theme', $themeName),
                'name' => sprintf('Theme %s', $themeName),
                'category' => 'theme',
                'vendor' => 'custom',
                'version' => null,
                'confidence_pct' => 79,
                'is_primary' => false,
                'detection_method' => 'asset-path-fingerprint',
                'metadata' => ['theme' => $themeName, 'cms' => $cms['type'] ?? null],
            ];
        }

        foreach ($fingerprint['custom_modules'] as $moduleName) {
            $detected[] = [
                'slug' => $this->scopedSlug(($cms['type'] ?? 'web') . '-module', $moduleName),
                'name' => sprintf('Module %s', $moduleName),
                'category' => 'module',
                'vendor' => 'custom',
                'version' => null,
                'confidence_pct' => 78,
                'is_primary' => false,
                'detection_method' => 'asset-path-fingerprint',
                'metadata' => ['module' => $moduleName, 'cms' => $cms['type'] ?? null],
            ];
        }

        return $detected;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFingerprint(Site $site, Response $response, MonitoringHttpClientFactory $httpClientFactory): array
    {
        $headers = array_change_key_case($response->headers(), CASE_LOWER);
        $bodyRaw = (string) $response->body();
        $probes = $this->probePublicPaths($site, $httpClientFactory);
        $texts = array_merge([$bodyRaw], array_map(static fn (array $probe): string => $probe['body_raw'], $probes));
        $headerBags = array_merge([$headers], array_map(static fn (array $probe): array => $probe['headers'], $probes));
        $cms = $this->detectCms($headers, $bodyRaw, $probes);

        return [
            'headers' => $headers,
            'body_raw' => $bodyRaw,
            'probes' => $probes,
            'cms' => $cms,
            'php_version' => $this->detectPhpVersion($headerBags, $texts),
            'php_evidence' => $this->phpEvidence($headerBags),
            'server' => $this->detectServer($headerBags),
            'database' => $this->detectDatabase($texts),
            'custom_themes' => $this->extractCustomThemes(implode("\n", $texts)),
            'custom_modules' => $this->extractCustomModules(implode("\n", $texts), $cms['type'] ?? null),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function probePublicPaths(Site $site, MonitoringHttpClientFactory $httpClientFactory): array
    {
        $paths = [
            '/readme.html',
            '/readme.txt',
            '/CHANGELOG.txt',
            '/core/CHANGELOG.txt',
            '/themes/',
            '/modules/',
            '/core/',
        ];

        $client = $httpClientFactory->make([
            'Accept' => 'text/plain,text/html,*/*;q=0.8',
            'X-UDG-Sentinel-Probe' => 'technology-scan',
        ]);

        $probes = [];

        foreach ($paths as $path) {
            try {
                $probeResponse = $client->get($this->buildProbeUrl($site->url, $path));
                $probes[] = [
                    'path' => $path,
                    'status' => $probeResponse->status(),
                    'headers' => array_change_key_case($probeResponse->headers(), CASE_LOWER),
                    'body_raw' => $this->truncateBody((string) $probeResponse->body()),
                ];
            } catch (\Throwable) {
                // Cada sonda es aislada; si falla un path no debe afectar el escaneo del sitio.
            }
        }

        return $probes;
    }

    private function buildProbeUrl(string $siteUrl, string $path): string
    {
        return rtrim($siteUrl, '/') . '/' . ltrim($path, '/');
    }

    private function truncateBody(string $body): string
    {
        return mb_substr($body, 0, 12000);
    }

    /**
     * @param array<int, array<string, array<int, string>>> $headerBags
     * @param array<int, string> $texts
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

        foreach ($texts as $text) {
            if (preg_match('/php\s+version\s*[:\-]?\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $text, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, array<int, string>>> $headerBags
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
        }

        return array_values(array_unique(array_filter($evidence)));
    }

    /**
     * @param array<int, array<string, array<int, string>>> $headerBags
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
     * @param array<string, array<int, string>> $headers
     * @param array<int, array<string, mixed>> $probes
     * @return array<string, mixed>
     */
    private function detectCms(array $headers, string $bodyRaw, array $probes): array
    {
        $body = mb_strtolower($bodyRaw);
        $combinedProbeBodies = implode("\n", array_map(static fn (array $probe): string => $probe['body_raw'], $probes));
        $probePaths = array_map(static fn (array $probe): string => $probe['path'], $probes);
        $generator = $this->extractGeneratorMeta($bodyRaw);
        $setCookie = isset($headers['set-cookie']) ? mb_strtolower(implode(' ', $headers['set-cookie'])) : '';
        $poweredBy = isset($headers['x-powered-by']) ? mb_strtolower(implode(' ', $headers['x-powered-by'])) : '';

        if (
            str_contains($body, 'drupal-settings-json')
            || str_contains($body, '/sites/default/files')
            || str_contains($body, '/sites/all/themes/')
            || preg_match('/drupal\s+[0-9]+\.[0-9]+/i', $combinedProbeBodies) === 1
        ) {
            return [
                'type' => 'drupal',
                'version' => $this->firstVersionMatch(
                    [$generator, $bodyRaw, $combinedProbeBodies],
                    ['/drupal\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', '/Drupal\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?),/i']
                ),
                'confidence' => 94,
                'evidence' => array_values(array_filter(['drupal-settings-json', '/sites/default/files', in_array('/core/CHANGELOG.txt', $probePaths, true) ? '/core/CHANGELOG.txt' : null])),
            ];
        }

        if (
            str_contains($body, 'wp-content')
            || str_contains($body, 'wp-includes')
            || str_contains(mb_strtolower($combinedProbeBodies), 'wordpress')
        ) {
            return [
                'type' => 'wordpress',
                'version' => $this->firstVersionMatch(
                    [$generator, $bodyRaw, $combinedProbeBodies],
                    ['/wordpress\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', '/version\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i']
                ),
                'confidence' => 92,
                'evidence' => array_values(array_filter(['wp-content', 'generator-meta', in_array('/readme.html', $probePaths, true) ? '/readme.html' : null])),
            ];
        }

        if (
            str_contains($poweredBy, 'laravel')
            || str_contains($body, '/vendor/livewire')
            || str_contains($setCookie, 'laravel_session')
            || str_contains($setCookie, 'xsrf-token')
        ) {
            return [
                'type' => 'laravel',
                'version' => $this->firstVersionMatch(
                    [$generator, $poweredBy, $bodyRaw, $combinedProbeBodies],
                    ['/laravel(?:\s+framework|\s+v|\/)?\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i']
                ),
                'confidence' => 86,
                'evidence' => array_values(array_filter(['x-powered-by', str_contains($setCookie, 'laravel_session') ? 'laravel_session' : null, str_contains($body, '/vendor/livewire') ? '/vendor/livewire' : null])),
            ];
        }

        return [
            'type' => null,
            'version' => null,
            'confidence' => 0,
            'evidence' => [],
        ];
    }

    private function extractGeneratorMeta(string $bodyRaw): string
    {
        if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)["\']/i', $bodyRaw, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * @param array<int, string> $sources
     * @param array<int, string> $patterns
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
     * @param array<int, string> $sources
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
                'patterns' => ['/postgresql/i', '/\bpgsql\b/i', '/pdo_pgsql/i', '/sqlstate\[[0-9a-z]+\].*postgres/i'],
            ],
            [
                'slug' => 'mariadb',
                'name' => 'MariaDB',
                'vendor' => 'MariaDB Foundation',
                'confidence' => 72,
                'patterns' => ['/mariadb/i'],
            ],
            [
                'slug' => 'mysql',
                'name' => 'MySQL',
                'vendor' => 'Oracle',
                'confidence' => 70,
                'patterns' => ['/mysql/i', '/pdo_mysql/i', '/sqlstate\[[0-9a-z]+\].*mysql/i'],
            ],
            [
                'slug' => 'sqlserver',
                'name' => 'Microsoft SQL Server',
                'vendor' => 'Microsoft',
                'confidence' => 68,
                'patterns' => ['/sql server/i', '/sqlsrv/i'],
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
        preg_match_all('#/(?:modules/custom|sites/all/modules/custom|modules)/([a-z0-9_-]+)#i', $text, $matches);

        $modules = [];

        if (isset($matches[1]) && is_array($matches[1])) {
            $defaultDrupalModules = ['node', 'system', 'user', 'views', 'field', 'file', 'image', 'menu_ui', 'path', 'taxonomy', 'toolbar', 'ckeditor5'];

            foreach ($matches[1] as $moduleName) {
                $normalized = mb_strtolower($moduleName);

                if ($cmsType === 'drupal' && in_array($normalized, $defaultDrupalModules, true)) {
                    continue;
                }

                $modules[] = $normalized;
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
        $slug = preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($scope . '-' . $value)) ?? ($scope . '-' . $value);
        $slug = trim($slug, '-');

        return mb_substr($slug, 0, 100);
    }

    /**
     * @param array<string, mixed> $fingerprint
     */
    private function persistCmsDetail(int $siteId, array $fingerprint): void
    {
        $cms = $fingerprint['cms'];
        $phpVersion = $fingerprint['php_version'];
        $database = $fingerprint['database'];
        $server = $fingerprint['server'];
        $themes = $fingerprint['custom_themes'];
        $modules = $fingerprint['custom_modules'];

        $cmsDetail = CmsDetail::updateOrCreate(
            ['site_id' => $siteId],
            [
                'cms_type' => $cms['type'] ?? null,
                'cms_version' => $cms['version'] ?? null,
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
            ]
        );

        if (($cms['type'] ?? null) === 'drupal') {
            $this->captureDrupalModuleHints($cmsDetail, $modules);
        }
    }

    /**
     * @param array<int, string> $moduleNames
     */
    private function captureDrupalModuleHints(CmsDetail $cmsDetail, array $moduleNames): void
    {
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
                ]
            );
        }
    }
}
