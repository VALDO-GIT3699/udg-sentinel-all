<?php

use Illuminate\Support\Facades\Route;
use Modules\SSL\Http\Controllers\SSLController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('ssls', SSLController::class)->names('ssl');
});
