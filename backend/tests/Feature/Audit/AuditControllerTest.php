<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AuditControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_forbids_a_user_without_the_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/monitoring/audit')
            ->assertForbidden();
    }

    #[Test]
    public function it_lists_activity_log_entries_newest_first(): void
    {
        $user = $this->authorizedUser();
        $causer = User::factory()->create(['name' => 'Persona Auditada']);

        activity()->causedBy($causer)->log('Primera accion');
        activity()->causedBy($causer)->log('Segunda accion');

        $response = $this->actingAs($user)->get('/monitoring/audit');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Audit/Index')
            ->where('entries.data.0.description', 'Segunda accion')
            ->where('entries.data.1.description', 'Primera accion')
            ->where('entries.data.0.causer', 'Persona Auditada'));
    }

    #[Test]
    public function it_filters_by_search_term(): void
    {
        $user = $this->authorizedUser();

        activity()->log('Eliminacion de sitio critico.udg.mx');
        activity()->log('Cambio de permisos de usuario');

        $response = $this->actingAs($user)->get('/monitoring/audit?search=critico');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.description', 'Eliminacion de sitio critico.udg.mx'));
    }

    #[Test]
    public function it_filters_by_causer_id(): void
    {
        $user = $this->authorizedUser();
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        activity()->causedBy($alice)->log('Accion de Alice');
        activity()->causedBy($bob)->log('Accion de Bob');

        $response = $this->actingAs($user)->get('/monitoring/audit?causer_id='.$alice->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.causer', 'Alice'));
    }

    #[Test]
    public function entries_without_a_causer_are_attributed_to_sistema(): void
    {
        $user = $this->authorizedUser();

        activity()->log('Tarea programada');

        $response = $this->actingAs($user)->get('/monitoring/audit');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('entries.data.0.causer', 'Sistema'));
    }

    #[Test]
    public function it_exposes_the_list_of_causers_for_the_filter_dropdown(): void
    {
        $user = $this->authorizedUser();
        User::factory()->create(['name' => 'Zulema']);
        User::factory()->create(['name' => 'Andres']);

        $response = $this->actingAs($user)->get('/monitoring/audit');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('causers', 3)); // Zulema + Andres + $user
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.view_audit_log', 'web');
        $user->givePermissionTo('monitoring.view_audit_log');

        return $user;
    }
}
