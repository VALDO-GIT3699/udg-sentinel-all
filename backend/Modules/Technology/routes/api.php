<?php

use Illuminate\Support\Facades\Route;
use Modules\Technology\Http\Controllers\TechnologyController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('technologies', TechnologyController::class)->names('technology');
});
