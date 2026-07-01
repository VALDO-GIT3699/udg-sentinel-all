<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Modules\Monitoring\Http\Controllers\DashboardController;
use Modules\Monitoring\Http\Controllers\SiteController;
use Modules\Monitoring\Http\Controllers\SiteGroupController;

Route::middleware(['web'])->group(function () {
    Route::get('monitoring/local-autologin', function () {
        abort_unless(app()->environment('local'), 404);

        $user = User::query()->where('email', 'udgmonitoreo26B')->first();

        if (! $user instanceof User) {
            abort(500, 'No existe el usuario local udgmonitoreo26B. Ejecuta php artisan db:seed.');
        }

        Auth::login($user, true);
        request()->session()->regenerate();

        return redirect()->route('monitoring.dashboard');
    })->name('monitoring.local-login');

    Route::get('monitoring', fn () => redirect()->route('monitoring.dashboard'))
        ->name('monitoring');

    Route::get('monitoring/dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:monitoring.view_dashboard')
        ->name('monitoring.dashboard');

    Route::get('monitoring/groups/{group}/view', [DashboardController::class, 'groupView'])
        ->middleware('permission:monitoring.view_dashboard')
        ->name('monitoring.groups.view');

    Route::get('monitoring/sites/{site}/detail', [DashboardController::class, 'siteDetail'])
        ->middleware('permission:monitoring.view_site_detail')
        ->name('monitoring.sites.detail');

    Route::resource('monitoring/sites', SiteController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->names('monitoring.sites');

    Route::resource('monitoring/groups', SiteGroupController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->names('monitoring.groups');
});
