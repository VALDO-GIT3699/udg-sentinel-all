<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Comparte datos con TODAS las paginas Inertia sin que cada controlador tenga
 * que repetirlos a mano (antes canManageSites/canManageSettings se armaban
 * por separado en cada accion de DashboardController). auth.permissions trae
 * la lista completa de permission-strings del usuario actual, para que
 * componentes compartidos como TopNav decidan que pestañas mostrar sin
 * depender de que cada pagina les pase la bandera correcta.
 */
final class HandleInertiaRequests extends Middleware
{
    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
                'permissions' => $user instanceof User
                    ? $user->getAllPermissions()->pluck('name')->values()->all()
                    : [],
            ],
        ]);
    }
}
