<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $enableCsp = (bool) env('SECURITY_HEADERS_ENABLE_CSP', true);

        $cspDirectives = [
            "default-src 'self'",
            // Sin 'unsafe-inline': todo el JS (SPA via Vite + login) vive en
            // archivos externos con mismo origen, no hay <script> inline en
            // ninguna vista. style-src si necesita 'unsafe-inline' porque
            // Vue/Tailwind aplican estilos inline en varios componentes.
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data: https:",
            "connect-src 'self' https: wss:",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        if ($request->isSecure()) {
            $cspDirectives[] = 'upgrade-insecure-requests';
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), bluetooth=()');
        // Aisla esta pestaña de otras ventanas/pestañas con distinto origen
        // (mitiga ataques tipo Spectre/side-channel y "tabnabbing" cruzado) y
        // evita que otros orígenes carguen las respuestas de esta app como
        // recurso propio.
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        if ($enableCsp) {
            // Keep CSP compatible with Inertia/Vite built assets while blocking mixed/embedded content.
            $response->headers->set('Content-Security-Policy', implode('; ', $cspDirectives));
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
