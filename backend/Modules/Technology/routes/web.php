<?php

use Illuminate\Support\Facades\Route;
use Modules\Technology\Http\Controllers\TechnologyController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('technologies', TechnologyController::class)->names('technology');
});
