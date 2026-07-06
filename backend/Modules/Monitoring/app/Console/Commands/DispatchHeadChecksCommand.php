<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use App\Contracts\Repositories\SiteRepositoryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\Monitoring\Jobs\RunHeadCheckJob;

final class DispatchHeadChecksCommand extends Command
{
    protected $signature = 'monitoring:dispatch-head-checks
        {--limit=100 : Maximo de sitios a despachar por ciclo}
        {--chunk=60 : Tamano de lote para despacho gradual}
        {--stagger=1 : Segundos de desfase por lote para evitar rafagas}';

    protected $description = 'Despacha jobs de chequeo HEAD para sitios monitoreados pendientes';

    public function handle(SiteRepositoryInterface $siteRepository): int
    {
        if ((bool) Cache::get('monitoring:single-site-rescan:pause', false) === true) {
            $prioritySiteId = (int) Cache::get('monitoring:single-site-rescan:site_id', 0);

            if ($prioritySiteId <= 0) {
                $this->warn('Despacho general en pausa temporal por reescaneo puntual.');
                return self::SUCCESS;
            }

            $prioritySite = $siteRepository->findById($prioritySiteId);
            if ($prioritySite !== null && $prioritySite->is_active && $prioritySite->is_monitored) {
                $this->dispatchMonitoringJob(RunHeadCheckJob::class, (int) $prioritySite->id);
                $this->line(sprintf('Site prioritario #%d despachado para HEAD check.', (int) $prioritySite->id));
            }

            Cache::forget('monitoring:single-site-rescan:pause');
            Cache::forget('monitoring:single-site-rescan:site_id');

            $this->info('Reescaneo prioritario completado. El escaneo general puede reanudarse.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $staggerSeconds = max(0, (int) $this->option('stagger'));
        $sites = $siteRepository->dueForCheck($limit);
        $dispatched = 0;

        $sites->chunk($chunkSize)->values()->each(function ($chunk, int $chunkIndex) use ($staggerSeconds, &$dispatched): void {
            $delay = $staggerSeconds > 0 ? now()->addSeconds($chunkIndex * $staggerSeconds) : null;

            foreach ($chunk as $site) {
                if ($delay !== null && ! $this->shouldDispatchMonitoringSynchronously()) {
                    RunHeadCheckJob::dispatch((int) $site->id)->delay($delay);
                } else {
                    $this->dispatchMonitoringJob(RunHeadCheckJob::class, (int) $site->id);
                }

                $dispatched++;
            }
        });

        $this->info(sprintf(
            'Despachados %d jobs de uptime en %d lote(s) de hasta %d sitio(s).',
            $dispatched,
            (int) ceil(max(1, $sites->count()) / $chunkSize),
            $chunkSize
        ));

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
