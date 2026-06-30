<?php

use Illuminate\Support\Facades\Route;
use Modules\SSL\Http\Controllers\SSLController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('ssls', SSLController::class)->names('ssl');
});
