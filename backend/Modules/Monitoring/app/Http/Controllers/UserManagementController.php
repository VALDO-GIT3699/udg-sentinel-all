<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Monitoring\Support\MonitoringPermissionMatrix;
use Spatie\Permission\Models\Permission;

final class UserManagementController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(function (User $user): array {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'department' => $user->department,
                    'is_active' => (bool) $user->is_active,
                    'role' => $user->roles->first()?->name,
                    'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                    'last_login_at' => optional($user->last_login_at)?->toIso8601String(),
                    'created_at' => optional($user->created_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'roles' => MonitoringPermissionMatrix::roleLabels(),
            'permissions' => MonitoringPermissionMatrix::permissionLabels(),
            'defaultRole' => MonitoringPermissionMatrix::VIEWER_ROLE,
            'rolePermissionPresets' => collect($this->roleSlugs())
                ->mapWithKeys(fn (string $role): array => [$role => MonitoringPermissionMatrix::defaultPermissionsForRole($role)])
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // No se exige formato de correo real a proposito: el login de este
            // sistema usa identificadores tipo usuario (p. ej. "udgmonitoreo26B",
            // ver MONITORING_LOGIN_DEFAULT_USER), no solo direcciones de email.
            'email' => ['required', 'string', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(10)->mixedCase()->numbers()->symbols()],
            'department' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['required', 'string', Rule::in($this->roleSlugs())],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(MonitoringPermissionMatrix::allPermissions())],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'department' => $validated['department'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'email_verified_at' => now(),
        ]);

        $permissions = $validated['permissions'] ?? MonitoringPermissionMatrix::defaultPermissionsForRole($validated['role']);
        $this->ensurePermissionsExist($permissions);

        $user->syncRoles([$validated['role']]);
        $user->syncPermissions($permissions);

        if (function_exists('activity')) {
            activity()
                ->causedBy($request->user())
                ->performedOn($user)
                ->withProperties(['action' => 'user.created', 'role' => $validated['role']])
                ->log('Usuario creado desde el panel de administración');
        }

        return response()->json(['message' => 'Usuario creado correctamente.'], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', Password::min(10)->mixedCase()->numbers()->symbols()],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'required', 'string', Rule::in($this->roleSlugs())],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(MonitoringPermissionMatrix::allPermissions())],
        ]);

        $isSelf = $user->id === $request->user()?->id;

        if ($isSelf && array_key_exists('is_active', $validated) && $validated['is_active'] === false) {
            return response()->json(['message' => 'No puedes desactivar tu propia cuenta.'], 422);
        }

        if ($isSelf && array_key_exists('role', $validated) && $validated['role'] !== MonitoringPermissionMatrix::ADMIN_ROLE) {
            return response()->json(['message' => 'No puedes quitarte a ti mismo el rol de administrador.'], 422);
        }

        if ($isSelf && array_key_exists('permissions', $validated) && ! in_array('monitoring.manage_users', $validated['permissions'], true)) {
            return response()->json(['message' => 'No puedes quitarte a ti mismo el permiso de administrar usuarios.'], 422);
        }

        $demotingOrDeactivatingAdmin = (array_key_exists('is_active', $validated) && $validated['is_active'] === false)
            || (array_key_exists('role', $validated) && $validated['role'] !== MonitoringPermissionMatrix::ADMIN_ROLE);

        if ($demotingOrDeactivatingAdmin && $this->isLastActiveAdmin($user)) {
            return response()->json([
                'message' => 'No puedes desactivar ni degradar al último administrador activo del sistema.',
            ], 422);
        }

        $attributes = [];

        foreach (['name', 'email', 'department'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        if (array_key_exists('is_active', $validated)) {
            $attributes['is_active'] = $validated['is_active'];
        }

        if (! empty($validated['password'])) {
            $attributes['password'] = $validated['password'];
        }

        $user->fill($attributes);
        $user->save();

        if (array_key_exists('role', $validated)) {
            $user->syncRoles([$validated['role']]);
        }

        if (array_key_exists('permissions', $validated)) {
            $this->ensurePermissionsExist($validated['permissions']);
            $user->syncPermissions($validated['permissions']);
        }

        if (function_exists('activity')) {
            activity()
                ->causedBy($request->user())
                ->performedOn($user)
                ->withProperties(['action' => 'user.updated'])
                ->log('Usuario actualizado desde el panel de administración');
        }

        return response()->json(['message' => 'Usuario actualizado correctamente.']);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()?->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 422);
        }

        if ($this->isLastActiveAdmin($user)) {
            return response()->json([
                'message' => 'No puedes eliminar al último administrador activo del sistema.',
            ], 422);
        }

        $user->delete();

        if (function_exists('activity')) {
            activity()
                ->causedBy($request->user())
                ->withProperties(['action' => 'user.deleted', 'user_id' => $user->id, 'email' => $user->email])
                ->log('Usuario eliminado desde el panel de administración');
        }

        return response()->json(status: 204);
    }

    /**
     * @return list<string>
     */
    private function roleSlugs(): array
    {
        return [
            MonitoringPermissionMatrix::ADMIN_ROLE,
            MonitoringPermissionMatrix::OPERATOR_ROLE,
            MonitoringPermissionMatrix::VIEWER_ROLE,
        ];
    }

    /**
     * syncPermissions() lanza PermissionDoesNotExist para cualquier nombre que
     * no sea ya una fila en la tabla permissions -algo que puede pasar si el
     * seeder de permisos no se ha vuelto a correr tras agregar permisos
     * nuevos a la matriz-. Como la lista de nombres validos siempre viene de
     * MonitoringPermissionMatrix (nunca texto libre del cliente), es seguro
     * asegurarlas aqui antes de sincronizar.
     *
     * @param  list<string>  $names
     */
    private function ensurePermissionsExist(array $names): void
    {
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    /**
     * Un usuario con rol admin activo no puede ser desactivado, degradado ni
     * eliminado si es el ultimo admin activo que queda -de lo contrario nadie
     * podria volver a entrar al panel de administracion-.
     */
    private function isLastActiveAdmin(User $user): bool
    {
        if (! $user->is_active || ! $user->hasRole(MonitoringPermissionMatrix::ADMIN_ROLE)) {
            return false;
        }

        return ! User::query()
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->role(MonitoringPermissionMatrix::ADMIN_ROLE)
            ->exists();
    }
}
