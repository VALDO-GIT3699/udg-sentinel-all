<?php

declare(strict_types=1);

namespace Modules\Monitoring\Support;

use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class EnsureLocalMonitoringUser
{
    public const EMAIL = 'udgmonitoreo26B';

    public const NAME = 'udgmonitoreo26B';

    public function handle(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (MonitoringPermissionMatrix::allPermissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $adminRole = Role::findOrCreate(MonitoringPermissionMatrix::ADMIN_ROLE, 'web');
        $adminRole->syncPermissions(MonitoringPermissionMatrix::adminPermissions());

        $user = User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => self::NAME,
                'password' => Str::random(40),
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );

        $updates = [];

        if ($user->name !== self::NAME) {
            $updates['name'] = self::NAME;
        }

        if ($user->email_verified_at === null) {
            $updates['email_verified_at'] = now();
        }

        if ($user->is_active !== true) {
            $updates['is_active'] = true;
        }

        if (blank($user->getRawOriginal('password'))) {
            $updates['password'] = Str::random(40);
        }

        if ($updates !== []) {
            $user->forceFill($updates);
            $user->save();
        }

        if (! $user->hasRole(MonitoringPermissionMatrix::ADMIN_ROLE)) {
            $user->assignRole(MonitoringPermissionMatrix::ADMIN_ROLE);
        }

        return $user->fresh();
    }
}
