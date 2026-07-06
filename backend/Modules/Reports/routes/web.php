<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('reports', ReportsController::class)->names('reports');
    Route::get('reports/summary', [ReportsController::class, 'summary'])->name('reports.summary');
    Route::get('reports/export/executive.csv', [ReportsController::class, 'exportExecutiveCsv'])->name('reports.export.executive');
});
