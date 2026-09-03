<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * isActive() solo se validaba al momento del login: si un administrador
 * desactivaba (o eliminaba) a alguien que ya tenia una sesion abierta, esa
 * persona seguia usando el sistema hasta que la sesion expirara sola. Este
 * middleware corta la sesion en la siguiente peticion en cuanto deja de
 * cumplirse la condicion, sin esperar a que el usuario vuelva a iniciar
 * sesion por su cuenta.
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Tu usuario ha sido desactivado.',
            ]);
        }

        return $next($request);
    }
}
