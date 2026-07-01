<?php

use Illuminate\Support\Facades\Route;
use Modules\Monitoring\Http\Controllers\SiteDetailController;
use Modules\Monitoring\Http\Controllers\SiteController;
use Modules\Monitoring\Http\Controllers\SiteGroupController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('monitoring-groups', SiteGroupController::class)
        ->middleware('permission:monitoring.manage_groups')
        ->names('monitoring.groups');

    Route::apiResource('monitoring-sites', SiteController::class)
        ->middleware('permission:monitoring.manage_sites')
        ->names('monitoring.sites');

    Route::get('monitoring-sites/{siteId}/detail', [SiteDetailController::class, 'showApi'])
        ->middleware('permission:monitoring.view_site_detail')
        ->name('monitoring.sites.detail.api');
});
