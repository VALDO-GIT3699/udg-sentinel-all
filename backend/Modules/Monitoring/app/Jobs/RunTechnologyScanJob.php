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

            $headers = array_change_key_case($response->headers(), CASE_LOWER);
            $bodyRaw = (string) $response->body();
            $body = mb_strtolower($bodyRaw);

            $detected = $this->detectTechnologies($headers, $bodyRaw);

            // Snapshot previo de slugs activos para comparar cambios de stack
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

                if ($item['slug'] === 'drupal') {
                    $this->captureDrupalModuleHints($site->id, $body);
                }
            }

            // Detectar cambios de stack y emitir evento si hay diferencias
            $added   = array_values(array_diff($detectedSlugs, $previousSlugs));
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
     * @param array<string, array<int, string>> $headers
     * @return array<int, array<string, mixed>>
     */
    private function detectTechnologies(array $headers, string $bodyRaw): array
    {
        $detected = [];
        $body = mb_strtolower($bodyRaw);
        $serverHeader = isset($headers['server']) ? mb_strtolower(implode(' ', $headers['server'])) : '';
        $poweredBy = isset($headers['x-powered-by']) ? mb_strtolower(implode(' ', $headers['x-powered-by'])) : '';

        $phpVersion = null;
        if (preg_match('/php\/?\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $poweredBy, $phpMatches) === 1) {
            $phpVersion = $phpMatches[1];
        }

        $drupalVersion = null;
        if (preg_match('/drupal\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $bodyRaw, $drupalMatches) === 1) {
            $drupalVersion = $drupalMatches[1];
        }

        $wpVersion = null;
        if (preg_match('/meta name="generator" content="wordpress\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)"/i', $bodyRaw, $wpMatches) === 1) {
            $wpVersion = $wpMatches[1];
        }

        $laravelVersion = null;
        if (preg_match('/laravel\/?\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $bodyRaw, $laravelMatches) === 1) {
            $laravelVersion = $laravelMatches[1];
        }

        if (str_contains($poweredBy, 'laravel') || str_contains($body, 'laravel') || str_contains($body, '/vendor/livewire')) {
            $detected[] = [
                'slug' => 'laravel',
                'name' => 'Laravel',
                'category' => 'framework',
                'vendor' => 'Laravel LLC',
                'version' => $laravelVersion,
                'confidence_pct' => 85,
                'is_primary' => true,
                'detection_method' => 'headers+html-fingerprint',
                'metadata' => ['matched' => ['x-powered-by', 'html-fingerprint'], 'php_version' => $phpVersion],
            ];
        }

        if (str_contains($body, 'drupal-settings-json') || str_contains($body, '/sites/default/files') || str_contains($body, 'drupal')) {
            $detected[] = [
                'slug' => 'drupal',
                'name' => 'Drupal',
                'category' => 'cms',
                'vendor' => 'Drupal Association',
                'version' => $drupalVersion,
                'confidence_pct' => 90,
                'is_primary' => true,
                'detection_method' => 'html-fingerprint',
                'metadata' => ['matched' => ['drupal-settings-json', 'sites/default/files'], 'php_version' => $phpVersion],
            ];
        }

        if (str_contains($body, 'wp-content') || str_contains($body, 'wordpress')) {
            $detected[] = [
                'slug' => 'wordpress',
                'name' => 'WordPress',
                'category' => 'cms',
                'vendor' => 'WordPress Foundation',
                'version' => $wpVersion,
                'confidence_pct' => 88,
                'is_primary' => true,
                'detection_method' => 'html-fingerprint',
                'metadata' => ['matched' => ['wp-content', 'generator-meta'], 'php_version' => $phpVersion],
            ];
        }

        if (str_contains($serverHeader, 'nginx')) {
            $detected[] = [
                'slug' => 'nginx',
                'name' => 'Nginx',
                'category' => 'web-server',
                'vendor' => 'F5',
                'version' => null,
                'confidence_pct' => 70,
                'is_primary' => false,
                'detection_method' => 'server-header',
                'metadata' => ['server' => $serverHeader],
            ];
        }

        if ($phpVersion !== null || str_contains($poweredBy, 'php')) {
            $detected[] = [
                'slug' => 'php',
                'name' => 'PHP',
                'category' => 'language',
                'vendor' => 'PHP Group',
                'version' => $phpVersion,
                'confidence_pct' => 75,
                'is_primary' => false,
                'detection_method' => 'x-powered-by',
                'metadata' => ['x-powered-by' => $poweredBy],
            ];
        }

        $databaseGuess = null;
        if (str_contains($body, 'postgres') || str_contains($body, 'pgsql')) {
            $databaseGuess = 'PostgreSQL';
        } elseif (str_contains($body, 'mariadb')) {
            $databaseGuess = 'MariaDB';
        } elseif (str_contains($body, 'mysql')) {
            $databaseGuess = 'MySQL';
        }

        if ($databaseGuess !== null) {
            $detected[] = [
                'slug' => 'database-engine',
                'name' => 'Motor de Base de Datos',
                'category' => 'database',
                'vendor' => 'unknown',
                'version' => null,
                'confidence_pct' => 45,
                'is_primary' => false,
                'detection_method' => 'html-keywords',
                'metadata' => ['engine' => $databaseGuess],
            ];
        }

        return $detected;
    }

    private function captureDrupalModuleHints(int $siteId, string $body): void
    {
        $cmsDetail = CmsDetail::firstOrCreate(
            ['site_id' => $siteId],
            [
                'cms_type' => 'drupal',
                'last_scanned_at' => now(),
            ]
        );

        if ($cmsDetail->cms_type !== 'drupal') {
            $cmsDetail->update([
                'cms_type' => 'drupal',
                'last_scanned_at' => now(),
            ]);
        }

        $matches = [];
        preg_match_all('/\/modules\/([a-z0-9_\-]+)/i', $body, $matches);

        if (! isset($matches[1]) || ! is_array($matches[1])) {
            return;
        }

        $moduleNames = array_unique(array_map(static fn (string $name): string => mb_strtolower($name), $matches[1]));

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

        foreach (['drudg8b3', 'bootr4theme'] as $moduleSignature) {
            if (! str_contains($body, $moduleSignature)) {
                continue;
            }

            DrupalModule::updateOrCreate(
                ['cms_detail_id' => $cmsDetail->id, 'module_name' => $moduleSignature],
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

        $cmsDetail->update([
            'modules_count' => count($moduleNames),
            'last_scanned_at' => now(),
        ]);
    }
}
