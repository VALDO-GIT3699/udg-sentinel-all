<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_can_create_a_user_with_a_role_and_custom_permissions(): void
    {
        $admin = $this->adminUser();
        Role::findOrCreate('monitoring-operator', 'web');
        Permission::findOrCreate('monitoring.run_mass_scan', 'web');
        Permission::findOrCreate('monitoring.delete_sites', 'web');

        $response = $this->actingAs($admin)->postJson('/monitoring/admin/users', [
            'name' => 'Nuevo Operador',
            'email' => 'operador@udg.mx',
            'password' => 'Clave-Segura9!',
            'department' => 'CGTA',
            'role' => 'monitoring-operator',
            'permissions' => ['monitoring.run_mass_scan', 'monitoring.delete_sites'],
        ]);

        $response->assertCreated();

        $created = User::where('email', 'operador@udg.mx')->firstOrFail();
        $this->assertTrue($created->hasRole('monitoring-operator'));
        $this->assertTrue($created->can('monitoring.run_mass_scan'));
        $this->assertTrue($created->can('monitoring.delete_sites'));
        $this->assertNotSame('Clave-Segura9!', $created->getAuthPassword());
        $this->assertTrue(Hash::check('Clave-Segura9!', $created->getAuthPassword()));
    }

    #[Test]
    public function it_rejects_a_weak_password(): void
    {
        $admin = $this->adminUser();
        Role::findOrCreate('monitoring-viewer', 'web');

        $response = $this->actingAs($admin)->postJson('/monitoring/admin/users', [
            'name' => 'Debil',
            'email' => 'debil@udg.mx',
            'password' => 'password',
            'role' => 'monitoring-viewer',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'debil@udg.mx']);
    }

    #[Test]
    public function it_forbids_non_admin_users(): void
    {
        $user = User::factory()->create();
        Role::findOrCreate('monitoring-viewer', 'web');

        $response = $this->actingAs($user)->postJson('/monitoring/admin/users', [
            'name' => 'Intento',
            'email' => 'intento@udg.mx',
            'password' => 'Clave-Segura9!',
            'role' => 'monitoring-viewer',
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function an_admin_cannot_deactivate_or_demote_their_own_account(): void
    {
        $admin = $this->adminUser();
        Role::findOrCreate('monitoring-admin', 'web');
        $admin->assignRole('monitoring-admin');

        $this->actingAs($admin)
            ->patchJson("/monitoring/admin/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->patchJson("/monitoring/admin/users/{$admin->id}", ['role' => 'monitoring-viewer'])
            ->assertStatus(422);

        $admin->refresh();
        $this->assertTrue($admin->isActive());
    }

    #[Test]
    public function an_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->deleteJson("/monitoring/admin/users/{$admin->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    #[Test]
    public function an_admin_can_soft_delete_another_user(): void
    {
        $admin = $this->adminUser();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->deleteJson("/monitoring/admin/users/{$target->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    #[Test]
    public function updating_permissions_takes_effect_immediately(): void
    {
        $admin = $this->adminUser();
        $target = User::factory()->create();
        Permission::findOrCreate('monitoring.run_mass_scan', 'web');

        $this->assertFalse($target->can('monitoring.run_mass_scan'));

        $this->actingAs($admin)
            ->patchJson("/monitoring/admin/users/{$target->id}", [
                'permissions' => ['monitoring.run_mass_scan'],
            ])
            ->assertOk();

        $target->refresh();
        $this->assertTrue($target->can('monitoring.run_mass_scan'));
    }

    #[Test]
    public function accepting_a_roles_default_permissions_never_500s_even_if_the_catalog_is_not_seeded(): void
    {
        // Regresion: sin "permissions" explicito, el controlador cae al preset
        // del rol (MonitoringPermissionMatrix::defaultPermissionsForRole) y lo
        // pasaba directo a syncPermissions(), que lanza PermissionDoesNotExist
        // para cualquier nombre que no sea ya una fila en la tabla permissions.
        // Deliberadamente NO se siembra ningun permiso del rol aqui: es
        // exactamente el flujo real mas comun (elegir un rol y guardar).
        $admin = $this->adminUser();
        Role::findOrCreate('monitoring-operator', 'web');

        $response = $this->actingAs($admin)->postJson('/monitoring/admin/users', [
            'name' => 'Operador Default',
            'email' => 'operador-default@udg.mx',
            'password' => 'Clave-Segura9!',
            'role' => 'monitoring-operator',
        ]);

        $response->assertCreated();

        $created = User::where('email', 'operador-default@udg.mx')->firstOrFail();
        $this->assertTrue($created->can('monitoring.manage_sites'));
    }

    #[Test]
    public function it_accepts_a_username_style_identifier_that_is_not_a_real_email(): void
    {
        // A proposito: el login de este sistema acepta identificadores tipo
        // usuario (ver MONITORING_LOGIN_DEFAULT_USER = "udgmonitoreo26B"), no
        // solo direcciones de correo reales. El panel de administracion debe
        // poder crear cuentas con ese mismo estilo de identificador.
        $admin = $this->adminUser();
        Role::findOrCreate('monitoring-viewer', 'web');

        $response = $this->actingAs($admin)->postJson('/monitoring/admin/users', [
            'name' => 'Cuenta de servicio',
            'email' => 'udgServicioBackup01',
            'password' => 'Clave-Segura9!',
            'role' => 'monitoring-viewer',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'udgServicioBackup01']);
    }

    #[Test]
    public function it_can_explicitly_clear_the_department_field(): void
    {
        $admin = $this->adminUser();
        $target = User::factory()->create(['department' => 'CGTA']);

        $this->actingAs($admin)
            ->patchJson("/monitoring/admin/users/{$target->id}", ['department' => null])
            ->assertOk();

        $this->assertNull($target->fresh()->department);
    }

    #[Test]
    public function it_prevents_deactivating_or_demoting_the_last_active_admin(): void
    {
        Role::findOrCreate('monitoring-admin', 'web');
        Permission::findOrCreate('monitoring.manage_users', 'web');

        $lastAdmin = User::factory()->create();
        $lastAdmin->assignRole('monitoring-admin');
        $lastAdmin->givePermissionTo('monitoring.manage_users');

        // Alguien con permiso monitoring.manage_users pero SIN el rol admin
        // (p. ej. un operador al que se le otorgo el permiso individualmente)
        // intenta desactivar/degradar al unico administrador activo restante.
        $actingAdmin = $this->adminUser();

        $this->actingAs($actingAdmin)
            ->patchJson("/monitoring/admin/users/{$lastAdmin->id}", ['is_active' => false])
            ->assertStatus(422);

        $this->actingAs($actingAdmin)
            ->patchJson("/monitoring/admin/users/{$lastAdmin->id}", ['role' => 'monitoring-viewer'])
            ->assertStatus(422);

        $this->actingAs($actingAdmin)
            ->deleteJson("/monitoring/admin/users/{$lastAdmin->id}")
            ->assertStatus(422);

        $lastAdmin->refresh();
        $this->assertTrue($lastAdmin->isActive());
        $this->assertTrue($lastAdmin->hasRole('monitoring-admin'));
    }

    #[Test]
    public function deactivating_an_admin_is_allowed_when_another_active_admin_remains(): void
    {
        Role::findOrCreate('monitoring-admin', 'web');

        $actingAdmin = $this->adminUser();
        $actingAdmin->assignRole('monitoring-admin');

        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('monitoring-admin');

        $this->actingAs($actingAdmin)
            ->patchJson("/monitoring/admin/users/{$otherAdmin->id}", ['is_active' => false])
            ->assertOk();

        $this->assertFalse($otherAdmin->fresh()->isActive());
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'activitylog.enabled' => false,
            'activitylog.default_log_name' => 'testing',
        ]);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.manage_users', 'web');
        $user->givePermissionTo('monitoring.manage_users');

        return $user;
    }
}
