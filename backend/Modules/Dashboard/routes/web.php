<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

// A diferencia de Analytics (mismo tipo de datos: KPIs, alertas, sitios
// criticos) este modulo no exigia ningun permiso de monitoreo, solo sesion
// iniciada -cualquier cuenta autenticada, incluida un rol viewer sin ningun
// permiso, podia ver el tablero ejecutivo completo. Se alinea aqui con el
// mismo permiso que ya protege Analytics.
Route::middleware(['auth', 'verified', 'permission:monitoring.view_dashboard'])->group(function () {
    Route::get('dashboards/chart-data', [DashboardController::class, 'chartData'])
        ->name('dashboard.chart-data');

    Route::get('dashboards/executive-report', [DashboardController::class, 'executiveReport'])
        ->name('dashboard.executive-report');

    Route::resource('dashboards', DashboardController::class)->names('dashboard');
});
