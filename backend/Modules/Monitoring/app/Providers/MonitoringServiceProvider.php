<?php

declare(strict_types=1);

namespace Modules\Monitoring\Providers;

use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Monitoring\Console\Commands\AnalyzeDashboardQueriesCommand;
use Modules\Monitoring\Console\Commands\BackupDatabaseCommand;
use Modules\Monitoring\Console\Commands\DispatchAssetMonitoringCommand;
use Modules\Monitoring\Console\Commands\DispatchHeadChecksCommand;
use Modules\Monitoring\Console\Commands\DispatchSecurityHeadersChecksCommand;
use Modules\Monitoring\Console\Commands\DispatchSslChecksCommand;
use Modules\Monitoring\Console\Commands\DispatchTechnologyScansCommand;
use Modules\Monitoring\Console\Commands\ImportOfficialBaselineCommand;
use Modules\Monitoring\Console\Commands\InspectDebugCommand;
use Modules\Monitoring\Console\Commands\InspectSiteCommand;
use Modules\Monitoring\Console\Commands\PruneOldMetricsCommand;
use Modules\Monitoring\Console\Commands\PruneSiteChecksCommand;
use Modules\Monitoring\Console\Commands\RunMaintenanceSweepCommand;
use Modules\Monitoring\Console\Commands\SeedUdgSitesCommand;
use Modules\Monitoring\Console\Commands\SentinelBootstrapCommand;
use Modules\Monitoring\Console\Commands\SyncOfficialInventoryCommand;
use Modules\Monitoring\Services\Strategies\AssetMonitoringStrategyRouter;
use Modules\Monitoring\Services\Strategies\MailServerMonitoringStrategy;
use Modules\Monitoring\Services\Strategies\RestApiMonitoringStrategy;
use Modules\Monitoring\Services\Strategies\WebsiteMonitoringStrategy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class MonitoringServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Monitoring';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'monitoring';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        AnalyzeDashboardQueriesCommand::class,
        BackupDatabaseCommand::class,
        DispatchAssetMonitoringCommand::class,
        DispatchHeadChecksCommand::class,
        DispatchSslChecksCommand::class,
        DispatchSecurityHeadersChecksCommand::class,
        DispatchTechnologyScansCommand::class,
        ImportOfficialBaselineCommand::class,
        InspectDebugCommand::class,
        InspectSiteCommand::class,
        PruneOldMetricsCommand::class,
        PruneSiteChecksCommand::class,
        RunMaintenanceSweepCommand::class,
        SeedUdgSitesCommand::class,
        SyncOfficialInventoryCommand::class,
        SentinelBootstrapCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(AssetMonitoringStrategyRouter::class, function (): AssetMonitoringStrategyRouter {
            return new AssetMonitoringStrategyRouter([
                new RestApiMonitoringStrategy,
                new MailServerMonitoringStrategy,
                new WebsiteMonitoringStrategy,
            ]);
        });
    }

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        // El respaldo de base de datos corre siempre, incluso si los escaneos
        // programados estan pausados desde ajustes -son preocupaciones
        // operativas independientes-. BACKUP_OFFSITE_DISK es opcional: si no
        // se configura ningun disco extra, el respaldo se queda solo local.
        $offsiteDisk = trim((string) env('BACKUP_OFFSITE_DISK', ''));
        $backupCommand = 'monitoring:backup-database --keep-days='.max(1, (int) env('BACKUP_KEEP_DAYS', 14));

        if ($offsiteDisk !== '') {
            $backupCommand .= ' --disk='.$offsiteDisk;
        }

        $schedule
            ->command($backupCommand)
            ->dailyAt('02:30')
            ->withoutOverlapping()
            ->runInBackground();

        $scheduledScansEnabled = true;

        try {
            $scheduledScansEnabled = (bool) Setting::get('monitoring.scheduled_scans_enabled', true);
        } catch (\Throwable) {
            $scheduledScansEnabled = true;
        }

        if (! $scheduledScansEnabled) {
            $schedule
                ->command('monitoring:maintenance-sweep --days=90')
                ->dailyAt('03:00')
                ->withoutOverlapping()
                ->runInBackground();

            return;
        }

        $routerEnabled = filter_var((string) env('SENTINEL_ASSET_MONITOR_ROUTER', 'true'), FILTER_VALIDATE_BOOL);

        if ($routerEnabled) {
            $schedule
                ->command('monitoring:dispatch-asset-monitoring --limit=200')
                ->hourly()
                ->withoutOverlapping()
                ->runInBackground();
        } else {
            $schedule
                ->command('monitoring:dispatch-head-checks --limit=200')
                ->hourly()
                ->withoutOverlapping()
                ->runInBackground();

            $schedule
                ->command('monitoring:dispatch-ssl-checks --limit=200')
                ->hourly()
                ->withoutOverlapping()
                ->runInBackground();

            $schedule
                ->command('monitoring:dispatch-security-headers-checks --limit=200')
                ->everyTwoHours()
                ->withoutOverlapping()
                ->runInBackground();

            $schedule
                ->command('monitoring:dispatch-technology-scans --limit=200')
                ->everyTwoHours()
                ->withoutOverlapping()
                ->runInBackground();
        }

        // El sweep ya incluye prune-site-checks + prune-old-metrics + limpieza de
        // storage/app/temp; antes solo se programaba la poda de site_checks y el
        // resto del mantenimiento (compactacion de metricas, temporales) nunca se
        // ejecutaba de forma automatica.
        $schedule
            ->command('monitoring:maintenance-sweep --days=90')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->runInBackground();

        // Sin esto, los jobs fallidos (timeouts puntuales de red al revisar
        // cientos de sitios son normales) se acumulan indefinidamente en
        // failed_jobs -llegaron a pasar de 2000 registros viejos sin podar-
        // ensuciando el dashboard de Horizon sin aportar nada pasados unos dias.
        $schedule
            ->command('queue:prune-failed --hours=168')
            ->dailyAt('03:15')
            ->withoutOverlapping()
            ->runInBackground();
    }
}
