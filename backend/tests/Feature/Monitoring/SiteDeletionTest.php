<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\User;
use App\Repositories\EloquentSiteRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class SiteDeletionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_soft_deletes_a_site_with_the_current_valid_key(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 30, 0, 'UTC'));
        // 2026-08-28 20:30 UTC is 2026-08-28 14:30 America/Mexico_City.
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $response = $this->actingAs($user)->deleteJson("/monitoring/sites/{$site->id}", [
            'clave' => '2808202614',
        ]);

        $response->assertNoContent();
        $this->assertSoftDeleted('sites', ['id' => $site->id]);
    }

    #[Test]
    public function it_rejects_an_expired_or_wrong_key_and_keeps_the_site(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 30, 0, 'UTC'));
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $response = $this->actingAs($user)->deleteJson("/monitoring/sites/{$site->id}", [
            'clave' => '2808202699',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('sites', ['id' => $site->id, 'deleted_at' => null]);
    }

    #[Test]
    public function it_rejects_a_missing_or_malformed_key(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 30, 0, 'UTC'));
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $this->actingAs($user)->deleteJson("/monitoring/sites/{$site->id}", [])
            ->assertStatus(422);

        $this->actingAs($user)->deleteJson("/monitoring/sites/{$site->id}", ['clave' => 'abc'])
            ->assertStatus(422);

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'deleted_at' => null]);
    }

    #[Test]
    public function it_forbids_users_without_delete_sites_permission(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 30, 0, 'UTC'));
        $user = User::factory()->create();
        Permission::findOrCreate('monitoring.manage_sites', 'web');
        $user->givePermissionTo('monitoring.manage_sites');

        $site = $this->createSite();

        $response = $this->actingAs($user)->deleteJson("/monitoring/sites/{$site->id}", [
            'clave' => '2808202614',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('sites', ['id' => $site->id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_soft_deleted_site_disappears_from_the_dashboard_listing(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 30, 0, 'UTC'));
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $this->actingAs($user)->deleteJson("/monitoring/sites/{$site->id}", [
            'clave' => '2808202614',
        ])->assertNoContent();

        $repo = new EloquentSiteRepository;
        $domains = $repo->paginate(50)->pluck('domain')->all();

        $this->assertNotContains('eliminar.udg.mx', $domains);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'activitylog.enabled' => false,
            'activitylog.default_log_name' => 'testing',
        ]);
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.delete_sites', 'web');
        $user->givePermissionTo('monitoring.delete_sites');

        return $user;
    }

    private function createSite(): Site
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-deletion',
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio a eliminar',
            'slug' => 'sitio-a-eliminar',
            'domain' => 'eliminar.udg.mx',
            'url' => 'https://eliminar.udg.mx',
        ]);
    }
}
