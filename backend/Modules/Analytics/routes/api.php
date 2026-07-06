<?php

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\AnalyticsController;

Route::middleware(['auth:sanctum'])
    ->prefix('analytics')
    ->name('analytics.')
    ->group(function (): void {
        Route::get('overview', [AnalyticsController::class, 'summary'])->name('overview');
    });
