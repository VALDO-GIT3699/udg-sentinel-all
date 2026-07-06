<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Models\SiteEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;

final class RunApiContractCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $siteId)
    {
        $this->onQueue((string) env('SENTINEL_QUEUE_UPTIME', 'monitoring-uptime'));
    }

    public function handle(
        SiteRepositoryInterface $siteRepository,
        MonitoringHttpClientFactory $httpClientFactory,
    ): void {
        $site = $siteRepository->findById($this->siteId);

        if (! $site instanceof Site || ! $site->is_active || ! $site->is_monitored) {
            return;
        }

        try {
            $response = $httpClientFactory->make([
                'Accept' => 'application/json,application/xml,text/xml,*/*;q=0.8',
            ])->get($site->url);

            $contentType = mb_strtolower((string) ($response->header('content-type') ?? ''));
            $body = trim((string) $response->body());

            $looksLikeJson = str_starts_with($body, '{') || str_starts_with($body, '[');
            $looksLikeXml = str_starts_with($body, '<') && str_contains($body, '>');

            SiteEvent::record(
                siteId: (int) $site->id,
                eventType: 'monitoring.api.contract.checked',
                title: 'Validacion de endpoint API completada',
                severity: 'info',
                description: 'Se verifico respuesta de endpoint API segun estrategia de monitoreo.',
                metadata: [
                    'http_status' => $response->status(),
                    'content_type' => $contentType,
                    'looks_like_json' => $looksLikeJson,
                    'looks_like_xml' => $looksLikeXml,
                ],
            );
        } catch (\Throwable) {
            // No interrumpe el pipeline.
        }
    }
}
