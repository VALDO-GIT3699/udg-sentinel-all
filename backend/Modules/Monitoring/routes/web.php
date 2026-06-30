<?php

use Illuminate\Support\Facades\Route;
use Modules\Monitoring\Http\Controllers\MonitoringController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('monitorings', MonitoringController::class)->names('monitoring');
});
