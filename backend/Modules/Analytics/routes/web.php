<?php

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\AnalyticsController;

Route::middleware(['web', 'auth', 'verified', 'permission:monitoring.view_dashboard'])
    ->group(function (): void {
        Route::get('analytics/overview', [AnalyticsController::class, 'index'])
            ->name('analytics.overview');
    });
