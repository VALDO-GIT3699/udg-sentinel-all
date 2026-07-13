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
        $bodyRaw = (string) $response->body();
        $body = mb_strtolower($bodyRaw);
        $results = [];

        $hasWordPress = $this->hasWordPressBaseSignature($headers, $body);
        $hasWix = $this->hasWixBaseSignature($headers, $body);

        // Exclusión mutua estricta de CMS.
        if ($hasWordPress) {
            $wordpressVersion = null;

            if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']\s*wordpress\s*([0-9]+(?:\.[0-9]+){0,2})/i', $bodyRaw, $matches) === 1) {
                $wordpressVersion = $matches[1];
            }

            $results[] = DetectedTechnology::fromArray([
                'name' => 'WordPress',
                'version' => $wordpressVersion,
                'category' => 'cms',
                'confidence' => 95,
                'slug' => 'wordpress',
                'evidence' => ['wp-content', 'wp-includes'],
            ])->toFrontendArray();

            return array_values(array_unique($results, SORT_REGULAR));
        }

        if ($hasWix) {
            $results[] = DetectedTechnology::fromArray([
                'name' => 'Wix',
                'version' => null,
                'category' => 'cms',
                'confidence' => 96,
                'slug' => 'wix',
                'evidence' => ['wixsite', 'wix-code'],
            ])->toFrontendArray();

            return array_values(array_unique($results, SORT_REGULAR));
        }

        if ($this->hasDrupalBaseSignature($headers, $bodyRaw)) {
            $drupal = DrupalFingerprint::detect($headers, $bodyRaw, []);

        if (is_array($drupal)) {
            $results[] = DetectedTechnology::fromArray([
                'name' => 'Drupal',
                'version' => $drupal['version'] ?? null,
                'category' => 'cms',
                'confidence' => $drupal['confidence'] ?? 90,
                'slug' => 'drupal',
                'evidence' => $drupal['evidence'] ?? [],
            ])->toFrontendArray();

                return array_values(array_unique($results, SORT_REGULAR));
            }
        }

        $poweredByHeader = isset($headers['x-powered-by']) ? implode(' ', $headers['x-powered-by']) : '';

        if (preg_match('/php\/?\s*([0-9]+(?:\.[0-9]+){1,2})/i', $poweredByHeader, $matches) === 1) {
            $results[] = DetectedTechnology::fromArray([
                'name' => 'PHP',
                'version' => $matches[1],
                'category' => 'language',
                'confidence' => 92,
                'slug' => 'php',
                'evidence' => ['x-powered-by'],
            ])->toFrontendArray();
        }

        if (isset($headers['x-powered-by']) && str_contains(mb_strtolower(implode(' ', $headers['x-powered-by'])), 'laravel')) {
            $laravelVersion = null;
            $poweredBy = $poweredByHeader;

            if (preg_match('/laravel(?:\s+framework|\s+v|\/)?\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $poweredBy, $matches) === 1) {
                $laravelVersion = $matches[1];
            }

            $results[] = DetectedTechnology::fromArray([
                'name' => 'Laravel',
                'version' => $laravelVersion,
                'category' => 'framework',
                'confidence' => 86,
                'slug' => 'laravel',
                'evidence' => ['x-powered-by'],
            ])->toFrontendArray();
        }

        if (isset($headers['server'])) {
            $serverHeader = implode(' ', $headers['server']);
            $server = mb_strtolower($serverHeader);

            if (str_contains($server, 'nginx')) {
                $version = null;

                if (preg_match('/nginx\/([0-9]+(?:\.[0-9]+){0,2})/i', $serverHeader, $matches) === 1) {
                    $version = $matches[1];
                }

                $results[] = DetectedTechnology::fromArray([
                    'name' => 'Nginx',
                    'version' => $version,
                    'category' => 'web-server',
                    'confidence' => 82,
                    'slug' => 'nginx',
                    'evidence' => ['server'],
                ])->toFrontendArray();
            }

            if (str_contains($server, 'apache')) {
                $version = null;

                if (preg_match('/apache(?:\s+http\s+server)?\/([0-9]+(?:\.[0-9]+){0,2})/i', $serverHeader, $matches) === 1) {
                    $version = $matches[1];
                }

                $results[] = DetectedTechnology::fromArray([
                    'name' => 'Apache HTTP Server',
                    'version' => $version,
                    'category' => 'web-server',
                    'confidence' => 80,
                    'slug' => 'apache',
                    'evidence' => ['server'],
                ])->toFrontendArray();
            }
        }

        return array_values(array_unique($results, SORT_REGULAR));
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function hasDrupalBaseSignature(array $headers, string $bodyRaw): bool
    {
        $body = mb_strtolower($bodyRaw);
        $xGenerator = isset($headers['x-generator']) ? mb_strtolower(implode(' ', $headers['x-generator'])) : '';
        $xDrupalCache = isset($headers['x-drupal-cache']) ? mb_strtolower(implode(' ', $headers['x-drupal-cache'])) : '';

        if ($xDrupalCache !== '' || str_contains($xGenerator, 'drupal')) {
            return true;
        }

        if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)["\']/i', $bodyRaw, $matches) === 1) {
            if (str_contains(mb_strtolower((string) $matches[1]), 'drupal')) {
                return true;
            }
        }

        return str_contains($body, 'drupal-settings-json')
            || str_contains($body, '/sites/default/files')
            || str_contains($body, '/sites/all/themes/')
            || str_contains($body, '/sites/all/modules/')
            || str_contains($body, '/misc/drupal.js')
            || str_contains($body, '/core/lib/drupal.php');
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function hasWordPressBaseSignature(array $headers, string $body): bool
    {
        if (str_contains($body, 'wp-content') || str_contains($body, 'wp-includes')) {
            return true;
        }

        return isset($headers['x-pingback']) && str_contains(mb_strtolower(implode(' ', $headers['x-pingback'])), 'xmlrpc.php');
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function hasWixBaseSignature(array $headers, string $body): bool
    {
        if (
            str_contains($body, 'wixsite')
            || str_contains($body, 'wix-code')
            || str_contains($body, 'wixstatic.com')
            || str_contains($body, 'static.parastorage.com')
        ) {
            return true;
        }

        return isset($headers['x-wix-request-id']) || isset($headers['x-wix-punisher']);
    }
}
