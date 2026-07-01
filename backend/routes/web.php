<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
	if (app()->environment('local') && Auth::guest()) {
		return redirect()->route('monitoring.local-login');
	}

	return redirect('/monitoring');
});

Route::middleware('guest')->group(function () {
	Route::get('/login', function () {
		if (app()->environment('local')) {
			return redirect()->route('monitoring.local-login');
		}

		return view('auth.quick-login', [
			'defaultUser' => 'udgmonitoreo26B',
		]);
	})->name('login');

	Route::post('/login', function (Request $request) {
		$credentials = $request->validate([
			'email' => ['required', 'string'],
			'password' => ['required', 'string'],
		]);

		if (! Auth::attempt($credentials, true)) {
			return back()->withErrors([
				'email' => 'Credenciales invalidas.',
			])->onlyInput('email');
		}

		$request->session()->regenerate();

		return redirect()->intended('/monitoring/dashboard');
	})->name('login.perform');
});

Route::post('/logout', function (Request $request) {
	Auth::logout();
	$request->session()->invalidate();
	$request->session()->regenerateToken();

	return redirect('/');
})->middleware('auth')->name('logout');
