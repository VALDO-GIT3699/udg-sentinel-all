<?php

namespace Modules\Monitoring\Providers;

use Modules\Monitoring\Console\Commands\AnalyzeDashboardQueriesCommand;
use Modules\Monitoring\Console\Commands\DispatchHeadChecksCommand;
use Modules\Monitoring\Console\Commands\DispatchSecurityHeadersChecksCommand;
use Modules\Monitoring\Console\Commands\DispatchSslChecksCommand;
use Modules\Monitoring\Console\Commands\DispatchTechnologyScansCommand;
use Modules\Monitoring\Console\Commands\PruneSiteChecksCommand;
use Modules\Monitoring\Console\Commands\SeedUdgSitesCommand;
use Modules\Monitoring\Console\Commands\SentinelBootstrapCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

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
        DispatchHeadChecksCommand::class,
        DispatchSslChecksCommand::class,
        DispatchSecurityHeadersChecksCommand::class,
        DispatchTechnologyScansCommand::class,
        PruneSiteChecksCommand::class,
        SeedUdgSitesCommand::class,
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

    /**
     * Define module schedules.
     *
     * @param $schedule
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule
            ->command('monitoring:dispatch-head-checks --limit=200')
            ->everyMinute()
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

        $schedule
            ->command('monitoring:prune-site-checks --days=90')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->runInBackground();
    }
}
