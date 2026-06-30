<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteCheckRepositoryInterface;
use App\Contracts\Repositories\SiteGroupRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Repositories\EloquentAlertRepository;
use App\Repositories\EloquentSiteCheckRepository;
use App\Repositories\EloquentSiteGroupRepository;
use App\Repositories\EloquentSiteRepository;
use Illuminate\Support\ServiceProvider;

final class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SiteRepositoryInterface::class, EloquentSiteRepository::class);
        $this->app->bind(SiteGroupRepositoryInterface::class, EloquentSiteGroupRepository::class);
        $this->app->bind(SiteCheckRepositoryInterface::class, EloquentSiteCheckRepository::class);
        $this->app->bind(AlertRepositoryInterface::class, EloquentAlertRepository::class);
    }
}
