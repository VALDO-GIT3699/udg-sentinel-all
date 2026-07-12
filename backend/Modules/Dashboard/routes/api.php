<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('dashboards/chart-data', [DashboardController::class, 'chartData'])
        ->name('dashboard.chart-data');

    Route::apiResource('dashboards', DashboardController::class)->names('dashboard');
});
