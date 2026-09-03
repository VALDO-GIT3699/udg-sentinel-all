<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services\Strategies;

use App\Models\Site;
use Illuminate\Http\Client\Response;
use Modules\Monitoring\Jobs\RunSiteInspectionJob;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;

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
        // El pipeline unificado (DNS/HTTP/SSL/cabeceras/fingerprint en una sola pasada)
        // es el mismo que usa el escaneo masivo manual via DispatchSiteScanChainJob; el
        // dashboard y el detalle de sitio ya solo leen de SiteInspectionProfile, que solo
        // este job puebla.
        RunSiteInspectionJob::dispatch((int) $site->id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function inspectTechnologies(Site $site, MonitoringHttpClientFactory $httpClientFactory): array
    {
        // Pipeline oficial: la clasificacion tecnologica se realiza exclusivamente en RunTechnologyScanJob.
        return [];
    }

    /**
     * @param  array<string, array<int, string>>  $headers
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

            if (! in_array($status, [200, 301, 302], true)) {
                continue;
            }

            if (in_array($path, ['/wp-json/', '/wp-login.php', '/wp-content/', '/wp-includes/'], true)) {
                return true;
            }
        }

        return isset($headers['x-pingback']) && str_contains(mb_strtolower(implode(' ', $headers['x-pingback'])), 'xmlrpc.php');
    }

    /**
     * @param  array<string, array<int, string>>  $headers
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

    /**
     * @return array<string, mixed>
     */
    private function extractRedirectContext(Site $site, Response $response, MonitoringHttpClientFactory $httpClientFactory): array
    {
        $siteUrl = (string) $site->url;
        $initialStatus = 0;
        $initialLocation = null;

        try {
            $initialResponse = $httpClientFactory
                ->make(['Accept' => 'text/html,*/*;q=0.8'])
                ->withoutRedirecting()
                ->get($siteUrl);

            $initialStatus = (int) $initialResponse->status();
            $initialLocation = $initialResponse->header('location');
        } catch (\Throwable) {
            // Si falla la sonda inicial, usamos el estado de la respuesta principal.
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
            'initial_status' => $initialStatus,
            'initial_location' => $initialLocation,
            'final_url' => $finalUrl,
            'final_status' => $finalStatus,
            'is_redirect' => in_array($initialStatus, [301, 302, 307, 308], true),
            'is_external' => $isExternal,
            'final_accessible' => $finalStatus >= 200 && $finalStatus < 400,
        ];
    }

    private function resolveFinalUrl(Response $response, string $fallbackUrl): string
    {
        $effectiveUrl = method_exists($response, 'effectiveUri') ? (string) $response->effectiveUri() : '';

        if ($effectiveUrl !== '') {
            return $effectiveUrl;
        }

        return $fallbackUrl;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function probeCmsPaths(string $baseUrl, MonitoringHttpClientFactory $httpClientFactory): array
    {
        $paths = ['/wp-json/', '/wp-login.php', '/wp-content/', '/wp-includes/', '/core/lib/Drupal.php', '/core/CHANGELOG.txt'];
        $client = $httpClientFactory->make(['Accept' => 'text/plain,text/html,*/*;q=0.8']);
        $probes = [];

        foreach ($paths as $path) {
            try {
                $response = $client->get(rtrim($baseUrl, '/').'/'.ltrim($path, '/'));
                $probes[] = [
                    'path' => $path,
                    'status' => (int) $response->status(),
                    'body_raw' => mb_substr((string) $response->body(), 0, 5000),
                ];
            } catch (\Throwable) {
                $probes[] = [
                    'path' => $path,
                    'status' => 0,
                    'body_raw' => '',
                ];
            }
        }

        return $probes;
    }
}
