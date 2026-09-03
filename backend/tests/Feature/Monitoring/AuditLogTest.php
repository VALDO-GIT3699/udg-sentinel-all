<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_forbids_users_without_view_audit_log_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/monitoring/audit')->assertForbidden();
    }

    #[Test]
    public function it_shows_logged_activity_to_an_authorized_user(): void
    {
        Permission::findOrCreate('monitoring.view_audit_log', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('monitoring.view_audit_log');

        activity()->causedBy($user)->log('Accion de prueba para auditoria');

        $this->assertGreaterThan(0, Activity::count());

        $response = $this->actingAs($user)->get('/monitoring/audit');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Audit/Index')
            ->where('entries.data.0.description', 'Accion de prueba para auditoria')
            ->where('entries.data.0.causer', $user->name));
    }

    #[Test]
    public function the_search_filter_works_under_the_test_database_driver_too(): void
    {
        // Regresion: "ilike" es exclusivo de Postgres. Bajo sqlite (el driver
        // que usa esta misma suite) era un error de sintaxis en cuanto se
        // mandaba un termino de busqueda -este es precisamente ese caso-.
        Permission::findOrCreate('monitoring.view_audit_log', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('monitoring.view_audit_log');

        activity()->causedBy($user)->log('Sitio monitoreado eliminado');
        activity()->causedBy($user)->log('Usuario creado desde el panel de administración');

        $response = $this->actingAs($user)->get('/monitoring/audit?search=eliminado');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('entries.data.0.description', 'Sitio monitoreado eliminado'));
    }
}
