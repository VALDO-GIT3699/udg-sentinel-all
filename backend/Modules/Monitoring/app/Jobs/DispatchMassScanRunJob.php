<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchMassScanRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param array<int, int> $siteIds
     */
    public function __construct(
        private readonly string $runId,
        private readonly array $siteIds,
    ) {
        $this->onQueue((string) env('SENTINEL_QUEUE_ORCHESTRATION', env('SENTINEL_QUEUE_ALERTS', 'monitoring-alerts')));
    }

    public function handle(): void
    {
        foreach ($this->siteIds as $siteId) {
            $id = (int) $siteId;

            RunHeadCheckJob::dispatch($id, $this->runId, true);
            RunSslCheckJob::dispatch($id, $this->runId, true);
            RunSecurityHeadersCheckJob::dispatch($id, $this->runId, true);
            RunTechnologyScanJob::dispatch($id, $this->runId, true);
        }
    }
}
