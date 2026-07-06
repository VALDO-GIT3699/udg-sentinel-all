<?php

namespace Modules\Analytics\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class AnalyticsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Analytics';

    protected string $nameLower = 'analytics';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
