<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

if (! function_exists('isTrustedLocalMonitoringOrigin')) {
	function isTrustedLocalMonitoringOrigin(string $ip): bool
	{
		return IpUtils::checkIp($ip, [
			'127.0.0.1',
			'::1',
			'10.0.0.0/8',
			'172.16.0.0/12',
			'192.168.0.0/16',
		]);
	}
}

Route::get('/', function () {
	$requestIp = (string) request()->ip();
	$isTrustedLocalIp = isTrustedLocalMonitoringOrigin($requestIp);

	if (app()->environment('local') && Auth::guest() && $isTrustedLocalIp) {
		return redirect()->route('monitoring.local-login');
	}

	return redirect('/monitoring');
});

Route::middleware('guest')->group(function () {
	Route::get('/login', function () {
		$requestIp = (string) request()->ip();
		$isTrustedLocalIp = isTrustedLocalMonitoringOrigin($requestIp);

		if (app()->environment('local') && $isTrustedLocalIp) {
			return redirect()->route('monitoring.local-login');
		}

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
		$throttleKey = 'auth:login:' . Str::lower((string) $credentials['email']) . '|' . $request->ip();

		if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
			$seconds = RateLimiter::availableIn($throttleKey);
			return back()->withErrors([
				'email' => 'Demasiados intentos. Espera ' . max(1, $seconds) . ' segundos para volver a intentar.',
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

		$request->session()->regenerate();
		$request->session()->put('auth.profile', $user->can('monitoring.manage_settings') ? 'admin' : 'mortal');

		return redirect()->intended('/monitoring/dashboard');
	})->name('login.perform');
});

Route::post('/logout', function (Request $request) {
	Auth::logout();
	$request->session()->invalidate();
	$request->session()->regenerateToken();

	return redirect('/');
})->middleware('auth')->name('logout');
