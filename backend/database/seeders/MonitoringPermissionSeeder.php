<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class MonitoringPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'monitoring.view_dashboard',
            'monitoring.view_site_detail',
            'monitoring.manage_sites',
            'monitoring.manage_groups',
            'monitoring.manage_alerts',
            'monitoring.manage_settings',
            'monitoring.view_horizon',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $adminRole = Role::findOrCreate('monitoring-admin', 'web');
        $adminRole->syncPermissions($permissions);

        $operatorRole = Role::findOrCreate('monitoring-operator', 'web');
        $operatorRole->syncPermissions([
            'monitoring.view_dashboard',
            'monitoring.view_site_detail',
            'monitoring.manage_sites',
            'monitoring.manage_groups',
            'monitoring.manage_alerts',
        ]);

        $viewerRole = Role::findOrCreate('monitoring-viewer', 'web');
        $viewerRole->syncPermissions([
            'monitoring.view_dashboard',
            'monitoring.view_site_detail',
        ]);

        $testUser = User::query()->where('email', 'test@example.com')->first();

        if ($testUser instanceof User) {
            $testUser->assignRole('monitoring-admin');
        }

        $fixedMonitoringUser = User::query()->where('email', 'udgmonitoreo26B')->first();

        if ($fixedMonitoringUser instanceof User) {
            $fixedMonitoringUser->assignRole('monitoring-admin');
        }
    }
}
