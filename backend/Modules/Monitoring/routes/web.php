<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\Monitoring\Http\Controllers\DashboardController;
use Modules\Monitoring\Http\Controllers\AssetIntelligenceController;
use Modules\Monitoring\Http\Controllers\SiteDetailController;
use Modules\Monitoring\Http\Controllers\SiteController;
use Modules\Monitoring\Http\Controllers\SiteGroupController;
use Modules\Monitoring\Support\EnsureLocalMonitoringUser;

Route::middleware(['web'])->group(function () {
    Route::get('monitoring/local-autologin', function (Request $request) {
        abort_unless(app()->environment('local'), 404);

        $ip = (string) $request->ip();
        $throttleKey = 'monitoring:local-autologin:' . $ip;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            abort(429, 'Demasiados intentos. Espera unos segundos e intenta de nuevo.');
        }

        $isTrustedLocalIp = in_array($ip, ['127.0.0.1', '::1'], true);
        $expectedToken = trim((string) env('MONITORING_LOCAL_AUTOLOGIN_TOKEN', ''));
        $receivedToken = trim((string) $request->query('token', ''));

        if (! $isTrustedLocalIp) {
            if ($expectedToken === '' || $receivedToken === '' || ! hash_equals($expectedToken, $receivedToken)) {
                RateLimiter::hit($throttleKey, 60);
                abort(403, 'Autologin no autorizado para este origen.');
            }
        }

        /** @var User $user */
        $user = app(EnsureLocalMonitoringUser::class)->handle();

        Auth::login($user, true);
        request()->session()->regenerate();
        RateLimiter::clear($throttleKey);

        return redirect()->route('monitoring.dashboard');
    })->name('monitoring.local-login');

    Route::middleware(['auth'])->group(function () {
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

        Route::post('monitoring/dashboard/scan-selected', [DashboardController::class, 'scanSelected'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.dashboard.scan-selected');

        Route::get('monitoring/dashboard/scan-progress', [DashboardController::class, 'scanProgress'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.dashboard.scan-progress');

        Route::get('monitoring/dashboard/search-suggestions', [DashboardController::class, 'searchSuggestionsEndpoint'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.dashboard.search-suggestions');

        Route::patch('monitoring/dashboard/scheduled-scans', [DashboardController::class, 'updateScheduledScans'])
            ->middleware('permission:monitoring.manage_settings')
            ->name('monitoring.dashboard.scheduled-scans.update');

        Route::get('monitoring/scans/{runId}', [DashboardController::class, 'scanRunView'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.scans.show');

        Route::get('monitoring/scans/{runId}/progress', [DashboardController::class, 'scanRunProgress'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.scans.progress');

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
});
