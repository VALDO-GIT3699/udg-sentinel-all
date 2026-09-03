<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class RunMaintenanceSweepCommand extends Command
{
    protected $signature = 'monitoring:maintenance-sweep
        {--days=90 : Antiguedad maxima para poda de historico}
        {--with-cache : Ejecuta limpieza de cache y optimize:clear}';

    protected $description = 'Ejecuta mantenimiento operativo: poda historicos y limpieza de temporales sin tocar datos productivos.';

    public function handle(): int
    {
        $days = max(30, (int) $this->option('days'));

        $commands = [
            sprintf('monitoring:prune-site-checks --days=%d', $days),
            'monitoring:prune-old-metrics --keep-live-days=7 --compact-days=30 --summary-years=5',
        ];

        if ((bool) $this->option('with-cache')) {
            $commands[] = 'cache:clear';
            $commands[] = 'optimize:clear';
        }

        foreach ($commands as $command) {
            try {
                Artisan::call($command);
                $this->line(sprintf('[OK] %s', $command));
            } catch (\Throwable $exception) {
                $this->warn(sprintf('[WARN] %s -> %s', $command, $exception->getMessage()));
            }
        }

        $tmpPath = storage_path('app/temp');

        if (is_dir($tmpPath)) {
            foreach (glob($tmpPath.DIRECTORY_SEPARATOR.'*') ?: [] as $tmpFile) {
                if (is_file($tmpFile)) {
                    @unlink($tmpFile);
                }
            }
            $this->line('[OK] Limpieza de temporales en storage/app/temp');
        }

        $this->info('Mantenimiento completado.');

        return self::SUCCESS;
    }
}
