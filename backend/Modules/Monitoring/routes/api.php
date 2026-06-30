<?php

use Illuminate\Support\Facades\Route;
use Modules\Monitoring\Http\Controllers\MonitoringController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('monitorings', MonitoringController::class)->names('monitoring');
});
