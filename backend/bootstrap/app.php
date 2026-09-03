<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Ngrok / nginx / reverse proxies: respeta X-Forwarded-* para esquema HTTPS
        // y host real, pero SOLO viniendo de saltos de red privada conocidos (el
        // propio nginx/docker). Confiar en "*" (cualquier IP) permite que un
        // visitante externo inyecte su propio header "X-Forwarded-For: 127.0.0.1"
        // y que Laravel crea que la peticion viene de la red local -bypaseando
        // el candado de IP confiable del autologin local y, mas grave aun,
        // permitiendo evadir el rate limiter de login (la clave de throttle usa
        // request()->ip()) rotando el header en cada intento. Verificado en este
        // mismo entorno: con "*" un X-Forwarded-For falsificado resuelve como
        // 127.0.0.1 sin importar la IP real del atacante; acotado a estos rangos,
        // resuelve correctamente la IP real.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);
        $middleware->append(SecurityHeaders::class);

        // Comparte auth.user/auth.permissions en TODAS las paginas Inertia (antes
        // cada controlador que necesitaba una bandera de permiso la repetia a
        // mano) y corta en caliente la sesion de un usuario que un admin acaba
        // de desactivar, sin esperar a que la sesion expire sola.
        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnsureUserIsActive::class,
        ]);

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
