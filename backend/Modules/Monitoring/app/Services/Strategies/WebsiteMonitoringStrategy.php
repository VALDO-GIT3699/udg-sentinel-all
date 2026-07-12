<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services\Strategies;

use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Modules\Monitoring\Jobs\RunHeadCheckJob;
use Modules\Monitoring\Jobs\RunSecurityHeadersCheckJob;
use Modules\Monitoring\Jobs\RunSslCheckJob;
use Modules\Monitoring\Jobs\RunTechnologyScanJob;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;
use Modules\Monitoring\Support\DrupalFingerprint;
use Modules\Monitoring\Support\DetectedTechnology;

final class WebsiteMonitoringStrategy implements AssetMonitoringStrategyInterface
{
    public function key(): string
    {
        return 'website';
    }

    public function supports(string $assetType): bool
    {
        return in_array($assetType, ['website', 'web_application', 'unknown'], true);
    }

    public function dispatch(Site $site): void
    {
        RunHeadCheckJob::dispatch((int) $site->id);
        RunSecurityHeadersCheckJob::dispatch((int) $site->id);
        RunSslCheckJob::dispatch((int) $site->id);
        RunTechnologyScanJob::dispatch((int) $site->id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function inspectTechnologies(Site $site, MonitoringHttpClientFactory $httpClientFactory): array
    {
        try {
            $response = $httpClientFactory
                ->make(['Accept' => 'text/html,*/*;q=0.8'])
                ->get($site->url);
        } catch (ConnectionException $exception) {
            Log::warning('Monitoring: inspeccion tecnologica interrumpida por conexion.', [
                'site_id' => $site->id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $headers = array_change_key_case($response->headers(), CASE_LOWER);
        $body = mb_strtolower((string) $response->body());
        $results = [];

        $drupal = DrupalFingerprint::detect($headers, (string) $response->body(), [], []);

        if (is_array($drupal)) {
            $results[] = DetectedTechnology::fromArray([
                'name' => 'Drupal',
                'version' => $drupal['version'] ?? null,
                'category' => 'cms',
                'confidence' => $drupal['confidence'] ?? 90,
                'slug' => 'drupal',
                'evidence' => $drupal['evidence'] ?? [],
            ])->toFrontendArray();
        }

        if (str_contains($body, 'wp-content') || str_contains($body, 'wp-includes')) {
            $results[] = DetectedTechnology::fromArray([
                'name' => 'WordPress',
                'version' => null,
                'category' => 'cms',
                'confidence' => 88,
                'slug' => 'wordpress',
                'evidence' => ['wp-content', 'wp-includes'],
            ])->toFrontendArray();
        }

        if (isset($headers['x-powered-by']) && str_contains(mb_strtolower(implode(' ', $headers['x-powered-by'])), 'laravel')) {
            $results[] = DetectedTechnology::fromArray([
                'name' => 'Laravel',
                'version' => null,
                'category' => 'framework',
                'confidence' => 86,
                'slug' => 'laravel',
                'evidence' => ['x-powered-by'],
            ])->toFrontendArray();
        }

        if (isset($headers['server'])) {
            $server = mb_strtolower(implode(' ', $headers['server']));

            if (str_contains($server, 'nginx')) {
                $results[] = DetectedTechnology::fromArray([
                    'name' => 'Nginx',
                    'version' => null,
                    'category' => 'web-server',
                    'confidence' => 82,
                    'slug' => 'nginx',
                    'evidence' => ['server'],
                ])->toFrontendArray();
            }

            if (str_contains($server, 'apache')) {
                $results[] = DetectedTechnology::fromArray([
                    'name' => 'Apache HTTP Server',
                    'version' => null,
                    'category' => 'web-server',
                    'confidence' => 80,
                    'slug' => 'apache',
                    'evidence' => ['server'],
                ])->toFrontendArray();
            }
        }

        return array_values(array_unique($results, SORT_REGULAR));
    }
}
