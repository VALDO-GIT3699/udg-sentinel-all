<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use App\Models\MonitoringMassScanRun;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchSiteScanChainJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int $siteId,
        private readonly string $runId,
        private readonly bool $forceScan = true,
    ) {
        $this->onQueue((string) env('SENTINEL_QUEUE_ORCHESTRATION', env('SENTINEL_QUEUE_ALERTS', 'monitoring-alerts')));
    }

    public function handle(): void
    {
        $triggerMode = MonitoringMassScanRun::query()
            ->where('run_id', $this->runId)
            ->value('trigger_mode');

        if (is_string($triggerMode) && in_array($triggerMode, ['manual_selected', 'manual_single'], true)) {
            RunSiteInspectionJob::dispatch($this->siteId, $this->runId, $this->forceScan);

            return;
        }

        RunSiteInspectionJob::dispatch($this->siteId, $this->runId, $this->forceScan);
    }
}
