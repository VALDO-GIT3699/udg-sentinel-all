<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\Monitoring\Http\Controllers\AssetIntelligenceController;
use Modules\Monitoring\Http\Controllers\DashboardController;
use Modules\Monitoring\Http\Controllers\NotificationSettingsController;
use Modules\Monitoring\Http\Controllers\SiteController;
use Modules\Monitoring\Http\Controllers\SiteDetailController;
use Modules\Monitoring\Http\Controllers\SiteGroupController;
use Modules\Monitoring\Http\Controllers\TrashController;
use Modules\Monitoring\Http\Controllers\UserManagementController;
use Modules\Monitoring\Support\EnsureLocalMonitoringUser;
use Symfony\Component\HttpFoundation\IpUtils;

Route::middleware(['web'])->group(function () {
    Route::get('monitoring/local-autologin', function (Request $request) {
        abort_unless(app()->environment('local'), 404);

        $ip = (string) $request->ip();
        $throttleKey = 'monitoring:local-autologin:'.$ip;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            abort(429, 'Demasiados intentos. Espera unos segundos e intenta de nuevo.');
        }

        $isTrustedLocalIp = IpUtils::checkIp($ip, [
            '127.0.0.1',
            '::1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);
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
            ->middleware(['permission:monitoring.run_mass_scan', 'throttle:mass-scan'])
            ->name('monitoring.dashboard.scan-all');

        Route::get('monitoring/dashboard/export-report', [DashboardController::class, 'exportReport'])
            ->middleware(['permission:monitoring.view_dashboard', 'throttle:exports'])
            ->name('monitoring.dashboard.export-report');

        Route::post('monitoring/dashboard/scan-selected', [DashboardController::class, 'scanSelected'])
            ->middleware(['permission:monitoring.run_mass_scan', 'throttle:mass-scan'])
            ->name('monitoring.dashboard.scan-selected');

        Route::get('monitoring/dashboard/scan-progress', [DashboardController::class, 'scanProgress'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.dashboard.scan-progress');

        Route::get('monitoring/dashboard/search-suggestions', [DashboardController::class, 'searchSuggestionsEndpoint'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.dashboard.search-suggestions');

        Route::get('monitoring/dashboard/latency-timeseries', [DashboardController::class, 'latencyTimeseries'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.dashboard.latency-timeseries');

        Route::patch('monitoring/dashboard/scheduled-scans', [DashboardController::class, 'updateScheduledScans'])
            ->middleware('permission:monitoring.manage_settings')
            ->name('monitoring.dashboard.scheduled-scans.update');

        Route::get('monitoring/dashboard/maintenance', [DashboardController::class, 'maintenance'])
            ->middleware('permission:monitoring.manage_settings')
            ->name('monitoring.dashboard.maintenance');

        Route::post('monitoring/dashboard/maintenance/run', [DashboardController::class, 'runMaintenance'])
            ->middleware('permission:monitoring.manage_settings')
            ->name('monitoring.dashboard.maintenance.run');

        Route::delete('monitoring/dashboard/scan-history', [DashboardController::class, 'clearMassScanHistory'])
            ->middleware('permission:monitoring.manage_settings')
            ->name('monitoring.dashboard.scan-history.clear');

        Route::get('monitoring/scans/{runId}', [DashboardController::class, 'scanRunView'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.scans.show');

        Route::get('monitoring/scans/{runId}/progress', [DashboardController::class, 'scanRunProgress'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.scans.progress');

        Route::post('monitoring/scans/{runId}/cancel', [DashboardController::class, 'cancelScan'])
            ->middleware('permission:monitoring.run_mass_scan')
            ->name('monitoring.scans.cancel');

        Route::post('monitoring/sites/{siteId}/scan', [DashboardController::class, 'scanSite'])
            ->middleware(['permission:monitoring.run_mass_scan', 'throttle:mass-scan'])
            ->name('monitoring.sites.scan');

        // Antes gateadas por view_dashboard (solo-lectura): cualquier rol
        // "viewer" podia reclasificar el tipo/rol de un activo, que es una
        // escritura sobre el sitio igual que editar su lifecycle-status
        // (esa si ya exigia manage_sites).
        Route::post('monitoring/sites/{site}/classification/manual', [SiteController::class, 'setManualClassification'])
            ->middleware('permission:monitoring.manage_sites')
            ->name('monitoring.sites.classification.manual');

        Route::post('monitoring/sites/{site}/classification/approve', [SiteController::class, 'approveAutomaticClassification'])
            ->middleware('permission:monitoring.manage_sites')
            ->name('monitoring.sites.classification.approve');

        Route::get('monitoring/groups/{group}/view', [DashboardController::class, 'groupView'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.groups.view');

        Route::get('monitoring/sites/{siteId}/detail', [SiteDetailController::class, 'show'])
            ->middleware('permission:monitoring.view_site_detail')
            ->name('monitoring.sites.detail');

        Route::get('monitoring/sites/{siteId}/latency-timeseries', [SiteDetailController::class, 'latencyTimeseries'])
            ->middleware('permission:monitoring.view_site_detail')
            ->name('monitoring.sites.latency-timeseries');

        Route::get('monitoring/diagnostic/{bucket}', [DashboardController::class, 'diagnosticSites'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.diagnostic.sites');

        Route::post('monitoring/sites/{siteId}/notes', [SiteDetailController::class, 'addNote'])
            ->middleware('permission:monitoring.manage_comments')
            ->name('monitoring.sites.notes.store');

        Route::patch('monitoring/sites/{siteId}/notes/{eventId}', [SiteDetailController::class, 'updateNoteStatus'])
            ->middleware('permission:monitoring.manage_comments')
            ->name('monitoring.sites.notes.update');

        Route::delete('monitoring/sites/{siteId}/notes/{eventId}', [SiteDetailController::class, 'deleteNote'])
            ->middleware('permission:monitoring.manage_comments')
            ->name('monitoring.sites.notes.delete');

        Route::post('monitoring/sites/register', [SiteController::class, 'registerMonitoredSite'])
            ->middleware('permission:monitoring.manage_sites')
            ->name('monitoring.sites.register');

        Route::patch('monitoring/sites/{site}/lifecycle-status', [SiteController::class, 'updateLifecycleStatus'])
            ->middleware('permission:monitoring.manage_sites')
            ->name('monitoring.sites.lifecycle.update');

        Route::delete('monitoring/sites/{site}', [SiteController::class, 'destroy'])
            ->middleware('permission:monitoring.delete_sites')
            ->name('monitoring.sites.destroy');

        // Antes registrado como Route::resource(...) sin middleware propio:
        // heredaba solo "auth", asi que cualquier usuario autenticado -incluido
        // el rol de solo lectura- podia crear/editar sitios y grupos por esta
        // API JSON, sin pasar por el permiso monitoring.manage_sites/manage_groups
        // ni por la clave de confirmacion. Lectura y escritura ahora se separan
        // explicitamente, igual que el resto de las rutas de este archivo.
        Route::get('monitoring/sites', [SiteController::class, 'index'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.sites.index');

        Route::get('monitoring/sites/{site}', [SiteController::class, 'show'])
            ->middleware('permission:monitoring.view_site_detail')
            ->name('monitoring.sites.show');

        Route::post('monitoring/sites', [SiteController::class, 'store'])
            ->middleware('permission:monitoring.manage_sites')
            ->name('monitoring.sites.store');

        Route::match(['put', 'patch'], 'monitoring/sites/{site}', [SiteController::class, 'update'])
            ->middleware('permission:monitoring.manage_sites')
            ->name('monitoring.sites.update');

        Route::get('monitoring/groups', [SiteGroupController::class, 'index'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.groups.index');

        Route::get('monitoring/groups/{siteGroup}', [SiteGroupController::class, 'show'])
            ->middleware('permission:monitoring.view_dashboard')
            ->name('monitoring.groups.show');

        Route::post('monitoring/groups', [SiteGroupController::class, 'store'])
            ->middleware('permission:monitoring.manage_groups')
            ->name('monitoring.groups.store');

        Route::match(['put', 'patch'], 'monitoring/groups/{siteGroup}', [SiteGroupController::class, 'update'])
            ->middleware('permission:monitoring.manage_groups')
            ->name('monitoring.groups.update');

        Route::delete('monitoring/groups/{siteGroup}', [SiteGroupController::class, 'destroy'])
            ->middleware('permission:monitoring.manage_groups')
            ->name('monitoring.groups.destroy');

        Route::middleware('permission:monitoring.manage_users')->group(function () {
            Route::resource('monitoring/admin/users', UserManagementController::class)
                ->only(['index', 'store', 'update', 'destroy'])
                ->names('monitoring.admin.users');

            Route::get('monitoring/trash', [TrashController::class, 'index'])
                ->name('monitoring.trash');
            Route::post('monitoring/trash/sites/{siteId}/restore', [TrashController::class, 'restoreSite'])
                ->name('monitoring.trash.sites.restore');
            Route::post('monitoring/trash/users/{userId}/restore', [TrashController::class, 'restoreUser'])
                ->name('monitoring.trash.users.restore');
        });

        Route::middleware('permission:monitoring.manage_settings')->prefix('monitoring/admin/notifications')->name('monitoring.admin.notifications.')->group(function () {
            Route::get('/', [NotificationSettingsController::class, 'index'])->name('index');
            Route::patch('/global', [NotificationSettingsController::class, 'updateGlobal'])->name('global');
            Route::post('/channels', [NotificationSettingsController::class, 'storeChannel'])->name('channels.store');
            Route::patch('/channels/{channel}/toggle', [NotificationSettingsController::class, 'toggleChannel'])->name('channels.toggle');
            Route::delete('/channels/{channel}', [NotificationSettingsController::class, 'destroyChannel'])->name('channels.destroy');
        });
    });
});
