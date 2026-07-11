<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\BrokenLink;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;

final class RunBrokenLinksCheckJob implements ShouldQueue
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
        $this->onQueue('heavy');
    }

    public function handle(SiteRepositoryInterface $siteRepository, MonitoringHttpClientFactory $httpClientFactory): void
    {
        try {
            $site = $siteRepository->findById($this->siteId);

            if (! $site instanceof Site || (! $this->forceScan && (! $site->is_active || ! $site->is_monitored))) {
                return;
            }

            $response = $this->fetchSiteResponse($site->url, $httpClientFactory);

            if ($response === null) {
                return;
            }

            $this->recordBrokenLinksFromResponse($site, $response, $httpClientFactory);
        } catch (\Throwable $exception) {
            Log::warning('Monitoring: error al evaluar enlaces rotos.', [
                'site_id' => $this->siteId,
                'run_id' => $this->massScanRunId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function fetchSiteResponse(string $url, MonitoringHttpClientFactory $httpClientFactory): ?Response
    {
        try {
            return $httpClientFactory
                ->make(['Accept' => 'text/html,*/*;q=0.8'])
                ->get($url);
        } catch (ConnectionException $exception) {
            Log::info('Monitoring: no se pudo obtener HTML para enlaces rotos.', [
                'site_id' => $this->siteId,
                'run_id' => $this->massScanRunId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function recordBrokenLinksFromResponse(Site $site, Response $response, MonitoringHttpClientFactory $httpClientFactory): void
    {
        if ($response->status() === 404) {
            $this->upsertBrokenLink((int) $site->id, $site->url, '/');
        }

        $html = '';

        if ($response->header('content-type') !== null && str_contains(strtolower((string) $response->header('content-type')), 'text/html')) {
            $html = (string) $response->body();
        }

        if ($html === '') {
            return;
        }

        preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches);

        $links = isset($matches[1]) && is_array($matches[1]) ? array_values(array_unique($matches[1])) : [];
        $checked = 0;

        foreach ($links as $link) {
            if ($checked >= 8) {
                break;
            }

            if (! str_starts_with($link, '/') && ! str_contains($link, $site->domain)) {
                continue;
            }

            $absoluteUrl = str_starts_with($link, 'http') ? $link : rtrim($site->url, '/') . '/' . ltrim($link, '/');

            try {
                $linkResponse = $httpClientFactory
                    ->make(['Accept' => 'text/html,*/*;q=0.8'])
                    ->get($absoluteUrl);
            } catch (ConnectionException) {
                $checked++;
                continue;
            }

            $checked++;

            if ($linkResponse->status() !== 404) {
                continue;
            }

            $path = parse_url($absoluteUrl, PHP_URL_PATH);
            $this->upsertBrokenLink(
                siteId: (int) $site->id,
                url: $absoluteUrl,
                foundOn: is_string($path) && $path !== '' ? $path : '/',
            );
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