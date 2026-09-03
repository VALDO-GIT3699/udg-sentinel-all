<?php

declare(strict_types=1);

use App\Http\Controllers\TwoFactorAuthenticationController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;

// Paginas legales: publicas a proposito (enlazadas desde el footer y el aviso
// de cookies, visibles incluso antes de iniciar sesion), sin datos sensibles.
Route::get('/legal/privacidad', fn () => Inertia::render('Legal/Privacidad'))->name('legal.privacidad');
Route::get('/legal/terminos', fn () => Inertia::render('Legal/Terminos'))->name('legal.terminos');

Route::get('/', function () {
    if (Auth::guest()) {
        return redirect()->route('login');
    }

    return redirect('/monitoring');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', function () {
        // El acceso SIEMPRE es con usuario y contraseña, sin atajos ni bypass
        // visibles en esta pantalla -sin excepcion de entorno.
        return view('auth.quick-login', [
            'defaultUser' => (string) env('MONITORING_LOGIN_DEFAULT_USER', 'udgmonitoreo26B'),
        ]);
    })->name('login');

    Route::post('/login', function (Request $request) {
        $credentials = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $maxAttempts = max(3, (int) env('AUTH_MAX_LOGIN_ATTEMPTS', 10));
        $lockoutMinutes = max(1, (int) env('AUTH_LOCKOUT_MINUTES', 15));
        $throttleKey = 'auth:login:'.Str::lower((string) $credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'email' => 'Demasiados intentos. Espera '.max(1, $seconds).' segundos para volver a intentar.',
            ])->onlyInput('email');
        }

        $remember = (bool) ($credentials['remember'] ?? false);
        unset($credentials['remember']);

        if (! Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($throttleKey, $lockoutMinutes * 60);

            return back()->withErrors([
                'email' => 'Credenciales invalidas.',
            ])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);

        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'email' => 'Tu usuario esta desactivado. Pidele al admin que lo habilite.',
            ])->onlyInput('email');
        }

        if (! $user->can('monitoring.view_dashboard')) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'email' => 'Tu cuenta no tiene acceso al sistema de monitoreo.',
            ])->onlyInput('email');
        }

        if ($user->hasVerifiedTwoFactor()) {
            // La contraseña ya se valido, pero la sesion NO se establece
            // todavia: se cierra de nuevo y solo se guarda un marcador
            // temporal con el id del usuario, hasta que el segundo factor
            // se verifique en TwoFactorAuthenticationController::verifyChallenge.
            Auth::logout();
            $request->session()->regenerateToken();
            $request->session()->put('2fa.user_id', $user->id);
            $request->session()->put('2fa.remember', $remember);

            return redirect()->route('two-factor.challenge');
        }

        $request->session()->regenerate();
        $request->session()->put('auth.profile', $user->can('monitoring.manage_settings') ? 'admin' : 'mortal');

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended('/monitoring/dashboard');
    })->name('login.perform');

    Route::get('/two-factor-challenge', [TwoFactorAuthenticationController::class, 'showChallenge'])
        ->name('two-factor.challenge');

    Route::post('/two-factor-challenge', [TwoFactorAuthenticationController::class, 'verifyChallenge'])
        ->middleware('throttle:6,1')
        ->name('two-factor.verify');
});

Route::middleware('auth')->group(function () {
    Route::get('/account/security', [TwoFactorAuthenticationController::class, 'edit'])
        ->name('account.security');
    Route::post('/account/two-factor/enable', [TwoFactorAuthenticationController::class, 'enable'])
        ->name('two-factor.enable');
    Route::post('/account/two-factor/confirm', [TwoFactorAuthenticationController::class, 'confirm'])
        ->name('two-factor.confirm');
    Route::delete('/account/two-factor', [TwoFactorAuthenticationController::class, 'disable'])
        ->name('two-factor.disable');
    Route::post('/account/two-factor/recovery-codes', [TwoFactorAuthenticationController::class, 'regenerateRecoveryCodes'])
        ->name('two-factor.recovery-codes');
    Route::patch('/account/notification-preference', [TwoFactorAuthenticationController::class, 'updateNotificationPreference'])
        ->name('account.notification-preference');
    Route::patch('/account/credentials', [TwoFactorAuthenticationController::class, 'updateCredentials'])
        ->name('account.credentials');
});

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
})->middleware('auth')->name('logout');
