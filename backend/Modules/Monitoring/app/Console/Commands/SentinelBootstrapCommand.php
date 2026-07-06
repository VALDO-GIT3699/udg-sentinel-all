<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\SiteTechnology;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Modules\Monitoring\Support\EnsureLocalMonitoringUser;

final class SentinelBootstrapCommand extends Command
{
    protected $signature = 'sentinel:bootstrap
        {--scan-limit=200 : Maximo de sitios para despachar en la primera rafaga}
        {--force : Permite ejecutar fuera de local o testing}';

    protected $description = 'Prepara el entorno local de Sentinel con migraciones, seeds, usuario local y primera rafaga de escaneos.';

    public function handle(EnsureLocalMonitoringUser $ensureLocalMonitoringUser): int
    {
        if (! app()->environment(['local', 'testing']) && ! (bool) $this->option('force')) {
            $this->components->error('Este comando solo debe ejecutarse en local o testing. Usa --force si realmente necesitas omitir la proteccion.');

            return self::FAILURE;
        }

        $scanLimit = max(1, (int) $this->option('scan-limit'));

        $this->components->info('Limpiando caches de la aplicacion...');

        if ($this->call('optimize:clear') !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->components->info('Ejecutando migraciones...');

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->components->info('Sembrando datos base...');

        if ($this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->components->info('Sincronizando inventario oficial UDG...');

        if ($this->call('monitoring:sync-official-inventory', ['--replace' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $user = $ensureLocalMonitoringUser->handle();

        $this->components->info(sprintf('Usuario local asegurado: %s', $user->email));

        $this->dispatchInitialBurstIfNeeded($scanLimit);

        $this->newLine();
        $this->components->info('Bootstrap completado.');

        return self::SUCCESS;
    }

    private function dispatchInitialBurstIfNeeded(int $scanLimit): void
    {
        $uptimeQueue = (string) env('SENTINEL_QUEUE_UPTIME', 'monitoring-uptime');
        $technologyQueue = (string) env('SENTINEL_QUEUE_TECH', 'monitoring-tech');

        $hasUptimeEvidence = SiteCheck::query()->exists() || Site::query()->whereNotNull('last_checked_at')->exists();
        $hasTechnologyEvidence = SiteTechnology::query()->exists();

        if ($this->queueHasPendingJobs($uptimeQueue) || $hasUptimeEvidence) {
            $this->components->twoColumnDetail('Rafaga uptime', 'omitida');
        } else {
            $this->components->twoColumnDetail('Rafaga uptime', 'despachando');
            $this->call('monitoring:dispatch-head-checks', ['--limit' => $scanLimit]);
        }

        if ($this->queueHasPendingJobs($technologyQueue) || $hasTechnologyEvidence) {
            $this->components->twoColumnDetail('Rafaga tecnologia', 'omitida');
        } else {
            $this->components->twoColumnDetail('Rafaga tecnologia', 'despachando');
            $this->call('monitoring:dispatch-technology-scans', ['--limit' => $scanLimit]);
        }
    }

    private function queueHasPendingJobs(string $queueName): bool
    {
        try {
            return Queue::size($queueName) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
