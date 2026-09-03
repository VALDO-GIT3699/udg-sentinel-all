<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use Database\Seeders\MonitoringPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Monitoring\Support\MonitoringPermissionMatrix;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Prueba de integridad de la matriz de roles/permisos: corre el seeder REAL
 * (no una copia) y verifica que cada rol termine con exactamente el set de
 * permisos que MonitoringPermissionMatrix declara -ni de mas, ni de menos-.
 * Si alguien agrega un permiso nuevo a allPermissions() y se le olvida
 * decidir a que rol(es) pertenece, esta prueba lo detecta.
 */
final class MonitoringPermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_permission_is_a_recognized_monitoring_permission(): void
    {
        foreach (MonitoringPermissionMatrix::allPermissions() as $permission) {
            $this->assertStringStartsWith('monitoring.', $permission);
        }

        $this->assertSame(
            MonitoringPermissionMatrix::allPermissions(),
            array_values(array_unique(MonitoringPermissionMatrix::allPermissions())),
            'allPermissions() no debe tener nombres duplicados.',
        );
    }

    #[Test]
    public function the_admin_role_has_every_permission(): void
    {
        $role = Role::findByName(MonitoringPermissionMatrix::ADMIN_ROLE, 'web');

        $this->assertEqualsCanonicalizing(
            MonitoringPermissionMatrix::allPermissions(),
            $role->permissions->pluck('name')->all(),
        );
    }

    #[Test]
    public function the_operator_role_has_exactly_its_declared_permissions(): void
    {
        $role = Role::findByName(MonitoringPermissionMatrix::OPERATOR_ROLE, 'web');

        $this->assertEqualsCanonicalizing(
            MonitoringPermissionMatrix::operatorPermissions(),
            $role->permissions->pluck('name')->all(),
        );

        // Los 5 permisos admin-only NUNCA deben colarse al operador.
        foreach (['monitoring.delete_sites', 'monitoring.manage_users', 'monitoring.view_audit_log', 'monitoring.manage_settings', 'monitoring.view_horizon'] as $adminOnly) {
            $this->assertNotContains($adminOnly, $role->permissions->pluck('name')->all());
        }
    }

    #[Test]
    public function the_viewer_role_is_strictly_read_only(): void
    {
        $role = Role::findByName(MonitoringPermissionMatrix::VIEWER_ROLE, 'web');

        $this->assertEqualsCanonicalizing(
            ['monitoring.view_dashboard', 'monitoring.view_site_detail'],
            $role->permissions->pluck('name')->all(),
        );

        $writePermissions = array_diff(
            MonitoringPermissionMatrix::allPermissions(),
            ['monitoring.view_dashboard', 'monitoring.view_site_detail'],
        );

        foreach ($writePermissions as $permission) {
            $this->assertNotContains($permission, $role->permissions->pluck('name')->all());
        }
    }

    #[Test]
    public function role_labels_and_permission_labels_cover_every_declared_slug(): void
    {
        $roleSlugs = [
            MonitoringPermissionMatrix::ADMIN_ROLE,
            MonitoringPermissionMatrix::OPERATOR_ROLE,
            MonitoringPermissionMatrix::VIEWER_ROLE,
        ];

        foreach ($roleSlugs as $slug) {
            $this->assertArrayHasKey($slug, MonitoringPermissionMatrix::roleLabels());
        }

        foreach (MonitoringPermissionMatrix::allPermissions() as $permission) {
            $this->assertArrayHasKey($permission, MonitoringPermissionMatrix::permissionLabels());
        }
    }

    #[Test]
    public function default_permissions_for_an_unknown_role_is_empty_not_an_error(): void
    {
        $this->assertSame([], MonitoringPermissionMatrix::defaultPermissionsForRole('not-a-real-role'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        (new MonitoringPermissionSeeder)->run();
    }
}
