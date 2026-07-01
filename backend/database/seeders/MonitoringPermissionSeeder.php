<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Monitoring\Support\MonitoringPermissionMatrix;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class MonitoringPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = MonitoringPermissionMatrix::allPermissions();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $adminRole = Role::findOrCreate(MonitoringPermissionMatrix::ADMIN_ROLE, 'web');
        $adminRole->syncPermissions(MonitoringPermissionMatrix::adminPermissions());

        $operatorRole = Role::findOrCreate(MonitoringPermissionMatrix::OPERATOR_ROLE, 'web');
        $operatorRole->syncPermissions(MonitoringPermissionMatrix::operatorPermissions());

        $viewerRole = Role::findOrCreate(MonitoringPermissionMatrix::VIEWER_ROLE, 'web');
        $viewerRole->syncPermissions(MonitoringPermissionMatrix::viewerPermissions());

        $testUser = User::query()->where('email', 'test@example.com')->first();

        if ($testUser instanceof User) {
            $testUser->assignRole(MonitoringPermissionMatrix::ADMIN_ROLE);
        }

        $fixedMonitoringUser = User::query()->where('email', 'udgmonitoreo26B')->first();

        if ($fixedMonitoringUser instanceof User) {
            $fixedMonitoringUser->assignRole(MonitoringPermissionMatrix::ADMIN_ROLE);
        }
    }
}
