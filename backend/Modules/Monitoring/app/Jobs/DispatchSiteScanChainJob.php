<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

final class DispatchSiteScanChainJob implements ShouldQueue
{
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
        Bus::chain([
            new RunHeadCheckJob($this->siteId, $this->runId, $this->forceScan),
            new RunSslCheckJob($this->siteId, $this->runId, $this->forceScan),
            new RunSecurityHeadersCheckJob($this->siteId, $this->runId, $this->forceScan),
            new RunTechnologyScanJob($this->siteId, $this->runId, $this->forceScan),
            new RunBrokenLinksCheckJob($this->siteId, $this->runId, $this->forceScan),
        ])->dispatch();
    }
}