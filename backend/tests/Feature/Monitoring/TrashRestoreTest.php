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

final class TrashRestoreTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_forbids_a_user_without_manage_users_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/monitoring/trash')->assertForbidden();
    }

    #[Test]
    public function it_lists_soft_deleted_sites_and_users(): void
    {
        $admin = $this->authorizedUser();
        $site = $this->createSite();
        $site->delete();

        $deletedUser = User::factory()->create(['name' => 'Persona Eliminada']);
        $deletedUser->delete();

        $response = $this->actingAs($admin)->get('/monitoring/trash');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Trash')
            ->where('sites.0.domain', $site->domain)
            ->where('users.0.name', 'Persona Eliminada'));
    }

    #[Test]
    public function it_restores_a_deleted_site(): void
    {
        $admin = $this->authorizedUser();
        $site = $this->createSite();
        $site->delete();

        $this->assertSoftDeleted('sites', ['id' => $site->id]);

        $response = $this->actingAs($admin)->post("/monitoring/trash/sites/{$site->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('sites', ['id' => $site->id, 'deleted_at' => null]);
    }

    #[Test]
    public function it_restores_a_deleted_user(): void
    {
        $admin = $this->authorizedUser();
        $deletedUser = User::factory()->create();
        $deletedUser->delete();

        $response = $this->actingAs($admin)->post("/monitoring/trash/users/{$deletedUser->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $deletedUser->id, 'deleted_at' => null]);
    }

    #[Test]
    public function restoring_a_site_that_is_not_deleted_returns_404(): void
    {
        $admin = $this->authorizedUser();
        $site = $this->createSite();

        $this->actingAs($admin)
            ->post("/monitoring/trash/sites/{$site->id}/restore")
            ->assertNotFound();
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.manage_users', 'web');
        $user->givePermissionTo('monitoring.manage_users');

        return $user;
    }

    private function createSite(): Site
    {
        static $counter = 0;
        $counter++;

        $group = SiteGroup::query()->create([
            'name' => 'Grupo Papelera '.$counter,
            'slug' => 'grupo-papelera-'.$counter,
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio papelera '.$counter,
            'slug' => 'sitio-papelera-'.$counter,
            'domain' => 'papelera'.$counter.'.udg.mx',
            'url' => 'https://papelera'.$counter.'.udg.mx',
        ]);
    }
}
