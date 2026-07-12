<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use App\Models\MonitoringMassScanRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Modules\Monitoring\Support\MassScanProgress;

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
        $triggerMode = MonitoringMassScanRun::query()
            ->where('run_id', $this->runId)
            ->value('trigger_mode');

        if (is_string($triggerMode) && in_array($triggerMode, ['manual_selected', 'manual_single'], true)) {
            foreach ($this->siteIds as $siteId) {
                DispatchSiteScanChainJob::dispatchSync((int) $siteId, $this->runId, true);
            }

            return;
        }

        $jobs = [];

        foreach ($this->siteIds as $siteId) {
            $jobs[] = new DispatchSiteScanChainJob((int) $siteId, $this->runId, true);
        }

        Bus::batch($jobs)
            ->name('monitoring-mass-scan:' . $this->runId)
            ->dispatch();
    }

    public function failed(\Throwable $exception): void
    {
        MassScanProgress::abortRun($this->runId, $exception->getMessage());
    }
}
