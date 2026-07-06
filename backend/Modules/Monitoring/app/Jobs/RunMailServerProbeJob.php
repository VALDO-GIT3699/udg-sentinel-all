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

final class RunMailServerProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $siteId)
    {
        $this->onQueue((string) env('SENTINEL_QUEUE_SSL', 'monitoring-ssl'));
    }

    public function handle(SiteRepositoryInterface $siteRepository): void
    {
        $site = $siteRepository->findById($this->siteId);

        if (! $site instanceof Site || ! $site->is_active || ! $site->is_monitored) {
            return;
        }

        $host = trim((string) ($site->domain ?: parse_url((string) $site->url, PHP_URL_HOST)));

        if ($host === '' || ! function_exists('dns_get_record')) {
            return;
        }

        try {
            $mx = dns_get_record($host, DNS_MX) ?: [];

            SiteEvent::record(
                siteId: (int) $site->id,
                eventType: 'monitoring.mail.probe.checked',
                title: 'Sondeo de servidor de correo completado',
                severity: 'info',
                description: 'Se verifico disponibilidad basica de MX para activo tipo Mail Server.',
                metadata: [
                    'mx_count' => count($mx),
                    'mx_hosts' => array_values(array_map(static fn (array $item): string => (string) ($item['target'] ?? ''), $mx)),
                ],
            );
        } catch (\Throwable) {
            // No interrumpe el pipeline.
        }
    }
}
