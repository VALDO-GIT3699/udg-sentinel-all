<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboards/chart-data', [DashboardController::class, 'chartData'])
        ->name('dashboard.chart-data');

    Route::get('dashboards/executive-report', [DashboardController::class, 'executiveReport'])
        ->name('dashboard.executive-report');

    Route::resource('dashboards', DashboardController::class)->names('dashboard');
});
