<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportsController;

Route::middleware(['auth', 'verified'])->group(function () {
    // IMPORTANTE: estas dos rutas van ANTES de Route::resource() a propósito.
    // El resource registra GET /reports/{report} (show); si "summary" o
    // "export" se registran después, ese comodín las intercepta primero y
    // Laravel intenta renderizar la vista "show" en vez de correr el
    // controlador correcto -exactamente lo que pasaba aquí antes de este
    // reordenamiento (una prueba lo detectó: "View [show] not found").
    Route::get('reports/summary', [ReportsController::class, 'summary'])->name('reports.summary');

    // Mismo limitador "exports" ya usado por la exportacion de PDF de
    // Monitoring: genera el reporte completo en cada llamada, igual de
    // costoso, y se habia quedado fuera del rate limiting agregado ahi.
    Route::get('reports/export/executive.csv', [ReportsController::class, 'exportExecutiveCsv'])
        ->middleware('throttle:exports')
        ->name('reports.export.executive');

    Route::resource('reports', ReportsController::class)->names('reports');
});
