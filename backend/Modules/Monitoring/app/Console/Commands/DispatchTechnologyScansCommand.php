<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use App\Contracts\Repositories\SiteRepositoryInterface;
use Illuminate\Console\Command;
use Modules\Monitoring\Jobs\RunTechnologyScanJob;

final class DispatchTechnologyScansCommand extends Command
{
    protected $signature = 'monitoring:dispatch-technology-scans {--limit=100 : Maximo de sitios a despachar por ciclo}';

    protected $description = 'Despacha jobs de deteccion de tecnologia activa';

    public function handle(SiteRepositoryInterface $siteRepository): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sites = $siteRepository->dueForTechnologyScan($limit);

        foreach ($sites as $site) {
            $this->dispatchMonitoringJob(RunTechnologyScanJob::class, (int) $site->id);
        }

        $this->info(sprintf('Despachados %d jobs de tecnologia.', $sites->count()));

        return self::SUCCESS;
    }

    private function dispatchMonitoringJob(string $jobClass, mixed ...$arguments): mixed
    {
        if ($this->shouldDispatchMonitoringSynchronously()) {
            return $jobClass::dispatchSync(...$arguments);
        }

        return $jobClass::dispatch(...$arguments);
    }

    private function shouldDispatchMonitoringSynchronously(): bool
    {
        return app()->environment(['local', 'development', 'testing']);
    }
}
