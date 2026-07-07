<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Modules\Monitoring\Http\Controllers\DashboardController;
use Modules\Monitoring\Http\Controllers\AssetIntelligenceController;
use Modules\Monitoring\Http\Controllers\SiteDetailController;
use Modules\Monitoring\Http\Controllers\SiteController;
use Modules\Monitoring\Http\Controllers\SiteGroupController;
use Modules\Monitoring\Support\EnsureLocalMonitoringUser;

Route::middleware(['web'])->group(function () {
    Route::get('monitoring/local-autologin', function () {
        abort_unless(app()->environment('local'), 404);

        /** @var User $user */
        $user = app(EnsureLocalMonitoringUser::class)->handle();

        Auth::login($user, true);
        request()->session()->regenerate();

        return redirect()->route('monitoring.dashboard');
    })->name('monitoring.local-login');

    Route::get('monitoring', fn () => redirect()->route('monitoring.dashboard'))
        ->name('monitoring');

    Route::get('monitoring/dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:monitoring.view_dashboard')
        ->name('monitoring.dashboard');

    Route::get('monitoring/assets/intelligence', [AssetIntelligenceController::class, 'index'])
        ->middleware('permission:monitoring.view_dashboard')
        ->name('monitoring.assets.intelligence');

    Route::post('monitoring/dashboard/scan-all', [DashboardController::class, 'scanAll'])
        ->middleware('permission:monitoring.view_dashboard')
        ->name('monitoring.dashboard.scan-all');

    Route::post('monitoring/dashboard/diagnostic', [DashboardController::class, 'runDiagnostic'])
        ->middleware('permission:monitoring.manage_settings')
        ->name('monitoring.dashboard.diagnostic');

    Route::post('monitoring/dashboard/register-site', [DashboardController::class, 'registerOfficialSite'])
        ->middleware('permission:monitoring.view_dashboard')
        ->name('monitoring.dashboard.register-site');

    Route::post('monitoring/sites/{siteId}/scan', [DashboardController::class, 'scanSite'])
        ->middleware('permission:monitoring.view_dashboard')
        ->name('monitoring.sites.scan');

    Route::post('monitoring/sites/{site}/classification/manual', [SiteController::class, 'setManualClassification'])
        ->middleware('permission:monitoring.view_dashboard')
        ->name('monitoring.sites.classification.manual');

    Route::post('monitoring/sites/{site}/classification/approve', [SiteController::class, 'approveAutomaticClassification'])
        ->middleware('permission:monitoring.view_dashboard')
        ->name('monitoring.sites.classification.approve');

    Route::get('monitoring/groups/{group}/view', [DashboardController::class, 'groupView'])
        ->middleware('permission:monitoring.view_dashboard')
        ->name('monitoring.groups.view');

    Route::get('monitoring/sites/{siteId}/detail', [SiteDetailController::class, 'show'])
        ->middleware('permission:monitoring.view_site_detail')
        ->name('monitoring.sites.detail');

    Route::get('monitoring/diagnostic/{bucket}', [DashboardController::class, 'diagnosticSites'])
        ->middleware('permission:monitoring.view_dashboard')
        ->name('monitoring.diagnostic.sites');

    Route::post('monitoring/sites/{siteId}/notes', [SiteDetailController::class, 'addNote'])
        ->middleware('permission:monitoring.view_site_detail')
        ->name('monitoring.sites.notes.store');

    Route::patch('monitoring/sites/{siteId}/notes/{eventId}', [SiteDetailController::class, 'updateNoteStatus'])
        ->middleware('permission:monitoring.view_site_detail')
        ->name('monitoring.sites.notes.update');

    Route::resource('monitoring/sites', SiteController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->names('monitoring.sites');

    Route::resource('monitoring/groups', SiteGroupController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->names('monitoring.groups');
});
