<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('reports', ReportsController::class)->names('reports');
    Route::get('reports/summary', [ReportsController::class, 'summary'])->name('reports.summary');
    Route::get('reports/export/executive.csv', [ReportsController::class, 'exportExecutiveCsv'])->name('reports.export.executive');
});
