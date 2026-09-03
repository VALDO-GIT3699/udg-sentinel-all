<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('monitoring/audit', [AuditController::class, 'index'])
        ->middleware('permission:monitoring.view_audit_log')
        ->name('audit.index');
});
