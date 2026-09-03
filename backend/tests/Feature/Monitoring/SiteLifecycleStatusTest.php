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

final class SiteLifecycleStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_accepts_a_free_text_custom_status_from_the_otro_option(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $response = $this->actingAs($user)->patchJson(
            "/monitoring/sites/{$site->id}/lifecycle-status",
            ['lifecycle_status' => 'Migrando a AWS'],
        );

        $response->assertOk();
        $this->assertSame('Migrando a AWS', $site->fresh()->lifecycle_status);
    }

    #[Test]
    public function it_still_accepts_a_standard_lifecycle_status(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $response = $this->actingAs($user)->patchJson(
            "/monitoring/sites/{$site->id}/lifecycle-status",
            ['lifecycle_status' => '2da Etapa'],
        );

        $response->assertOk();
        $this->assertSame('2da Etapa', $site->fresh()->lifecycle_status);
    }

    #[Test]
    public function it_still_requires_a_ticket_when_marking_as_eliminado(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $response = $this->actingAs($user)->patchJson(
            "/monitoring/sites/{$site->id}/lifecycle-status",
            ['lifecycle_status' => 'Eliminado'],
        );

        $response->assertStatus(422);
        $this->assertNotSame('Eliminado', $site->fresh()->lifecycle_status);
    }

    #[Test]
    public function it_rejects_an_empty_status(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $response = $this->actingAs($user)->patchJson(
            "/monitoring/sites/{$site->id}/lifecycle-status",
            ['lifecycle_status' => ''],
        );

        $response->assertStatus(422);
    }

    #[Test]
    public function it_forbids_users_without_manage_sites_permission(): void
    {
        $user = User::factory()->create();
        $site = $this->createSite();

        $response = $this->actingAs($user)->patchJson(
            "/monitoring/sites/{$site->id}/lifecycle-status",
            ['lifecycle_status' => 'Migrando a AWS'],
        );

        $response->assertStatus(403);
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.manage_sites', 'web');
        $user->givePermissionTo('monitoring.manage_sites');

        return $user;
    }

    private function createSite(): Site
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-lifecycle',
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio de pruebas',
            'slug' => 'sitio-de-pruebas-lifecycle',
            'domain' => 'lifecycle.udg.mx',
            'url' => 'https://lifecycle.udg.mx',
        ]);
    }
}
