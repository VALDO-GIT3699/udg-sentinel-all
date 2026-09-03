<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Regresion: Route::resource('monitoring/sites', ...) y
 * Route::resource('monitoring/groups', ...) se registraban SIN middleware de
 * permiso propio -heredaban solo "auth"-, asi que cualquier usuario
 * autenticado (incluido el rol de solo lectura) podia crear/editar sitios y
 * crear/editar/eliminar grupos completos por esta API JSON, sin pasar por
 * monitoring.manage_sites/manage_groups ni por la clave de confirmacion.
 */
final class SitesAndGroupsPermissionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_view_only_user_cannot_create_a_site_via_the_json_api(): void
    {
        $user = $this->viewOnlyUser();
        $group = $this->makeGroup();

        $this->actingAs($user)->postJson('/monitoring/sites', [
            'site_group_id' => $group->id,
            'name' => 'Intento',
            'slug' => 'intento-sitio',
            'domain' => 'intento.udg.mx',
            'url' => 'https://intento.udg.mx',
        ])->assertForbidden();

        $this->assertDatabaseMissing('sites', ['domain' => 'intento.udg.mx']);
    }

    #[Test]
    public function a_view_only_user_cannot_update_a_site_via_the_json_api(): void
    {
        $user = $this->viewOnlyUser();
        $group = $this->makeGroup();
        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio original',
            'slug' => 'sitio-original-perm',
            'domain' => 'original.udg.mx',
            'url' => 'https://original.udg.mx',
        ]);

        $this->actingAs($user)
            ->putJson("/monitoring/sites/{$site->id}", ['name' => 'Nombre alterado'])
            ->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'name' => 'Sitio original']);
    }

    #[Test]
    public function a_user_with_manage_sites_can_create_and_update_a_site(): void
    {
        $user = $this->siteManagerUser();
        $group = $this->makeGroup();

        $this->actingAs($user)->postJson('/monitoring/sites', [
            'site_group_id' => $group->id,
            'name' => 'Sitio permitido',
            'slug' => 'sitio-permitido',
            'domain' => 'permitido.udg.mx',
            'url' => 'https://permitido.udg.mx',
        ])->assertCreated();

        $site = Site::where('domain', 'permitido.udg.mx')->firstOrFail();

        $this->actingAs($user)
            ->putJson("/monitoring/sites/{$site->id}", ['name' => 'Renombrado'])
            ->assertOk();

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'name' => 'Renombrado']);
    }

    #[Test]
    public function a_view_only_user_cannot_create_update_or_delete_a_group(): void
    {
        $user = $this->viewOnlyUser();
        $group = $this->makeGroup();

        $this->actingAs($user)->postJson('/monitoring/groups', [
            'name' => 'Grupo intento',
            'slug' => 'grupo-intento',
            'color' => '#0EA5E9',
        ])->assertForbidden();

        $this->actingAs($user)
            ->putJson("/monitoring/groups/{$group->id}", ['name' => 'Renombrado'])
            ->assertForbidden();

        $this->actingAs($user)
            ->deleteJson("/monitoring/groups/{$group->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('site_groups', ['id' => $group->id, 'name' => 'Grupo de prueba']);
    }

    #[Test]
    public function a_view_only_user_cannot_approve_or_set_an_asset_classification(): void
    {
        // Regresion: estas dos rutas de escritura estaban gateadas por
        // view_dashboard (solo-lectura) -cualquier rol "viewer" podia
        // reclasificar el tipo/rol de un activo desde Asset Intelligence.
        $user = $this->viewOnlyUser();
        $group = $this->makeGroup();
        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio clasificable',
            'slug' => 'sitio-clasificable-perm',
            'domain' => 'clasificable.udg.mx',
            'url' => 'https://clasificable.udg.mx',
        ]);

        $this->actingAs($user)
            ->postJson("/monitoring/sites/{$site->id}/classification/manual", [
                'asset_type' => 'web',
                'asset_role' => 'informativo',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson("/monitoring/sites/{$site->id}/classification/approve")
            ->assertForbidden();
    }

    #[Test]
    public function a_user_with_manage_groups_can_create_update_and_delete_a_group(): void
    {
        $user = $this->groupManagerUser();

        $this->actingAs($user)->postJson('/monitoring/groups', [
            'name' => 'Grupo permitido',
            'slug' => 'grupo-permitido',
            'color' => '#0EA5E9',
        ])->assertCreated();

        $group = SiteGroup::where('slug', 'grupo-permitido')->firstOrFail();

        $this->actingAs($user)
            ->putJson("/monitoring/groups/{$group->id}", ['name' => 'Renombrado'])
            ->assertOk();

        $this->actingAs($user)
            ->deleteJson("/monitoring/groups/{$group->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('site_groups', ['id' => $group->id]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'activitylog.enabled' => false,
            'activitylog.default_log_name' => 'testing',
        ]);
    }

    private function viewOnlyUser(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        Permission::findOrCreate('monitoring.view_site_detail', 'web');
        $user->givePermissionTo(['monitoring.view_dashboard', 'monitoring.view_site_detail']);

        return $user;
    }

    private function siteManagerUser(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('monitoring.manage_sites', 'web');
        $user->givePermissionTo('monitoring.manage_sites');

        return $user;
    }

    private function groupManagerUser(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('monitoring.manage_groups', 'web');
        $user->givePermissionTo('monitoring.manage_groups');

        return $user;
    }

    private function makeGroup(): SiteGroup
    {
        return SiteGroup::query()->create([
            'name' => 'Grupo de prueba',
            'slug' => 'grupo-de-prueba-perm',
            'color' => '#0EA5E9',
        ]);
    }
}
