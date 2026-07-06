<?php

namespace Modules\Inventory\Providers;

use Modules\Inventory\Console\Commands\DispatchAssetClassificationsCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class InventoryServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Inventory';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'inventory';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        DispatchAssetClassificationsCommand::class,
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
            ->command('inventory:dispatch-asset-classifications --limit=250')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();
    }
}
