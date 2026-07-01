<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use App\Contracts\Repositories\SiteRepositoryInterface;
use Illuminate\Console\Command;
use Modules\Monitoring\Jobs\RunSecurityHeadersCheckJob;

final class DispatchSecurityHeadersChecksCommand extends Command
{
    protected $signature = 'monitoring:dispatch-security-headers-checks
        {--limit=100 : Maximo de sitios a despachar por ciclo}
        {--chunk=50 : Tamano de lote para despacho gradual}
        {--stagger=1 : Segundos de desfase por lote para evitar rafagas}';

    protected $description = 'Despacha jobs de escaneo de cabeceras de seguridad';

    public function handle(SiteRepositoryInterface $siteRepository): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $staggerSeconds = max(0, (int) $this->option('stagger'));
        $sites = $siteRepository->dueForSecurityHeaderScan($limit);
        $dispatched = 0;

        $sites->chunk($chunkSize)->values()->each(function ($chunk, int $chunkIndex) use ($staggerSeconds, &$dispatched): void {
            $delay = $staggerSeconds > 0 ? now()->addSeconds($chunkIndex * $staggerSeconds) : null;

            foreach ($chunk as $site) {
                if ($delay !== null && ! $this->shouldDispatchMonitoringSynchronously()) {
                    RunSecurityHeadersCheckJob::dispatch((int) $site->id)->delay($delay);
                } else {
                    $this->dispatchMonitoringJob(RunSecurityHeadersCheckJob::class, (int) $site->id);
                }

                $dispatched++;
            }
        });

        $this->info(sprintf(
            'Despachados %d jobs de cabeceras en %d lote(s) de hasta %d sitio(s).',
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
