<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AnalyticsOverviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_forbids_a_user_without_view_dashboard_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/analytics/overview')
            ->assertForbidden();
    }

    #[Test]
    public function it_renders_the_executive_summary_page(): void
    {
        $user = $this->authorizedUser();
        $this->createSite(status: 'up', assetType: 'website');
        $this->createSite(status: 'down', assetType: 'unknown');

        $response = $this->actingAs($user)->get('/analytics/overview');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Analytics/Overview')
            ->where('summary.kpis.assets_total', 2)
            ->where('summary.kpis.institutional_health_pct', 50)
            ->where('summary.kpis.inventory_coverage_pct', 50));
    }

    #[Test]
    public function inactive_sites_are_excluded_from_the_asset_totals(): void
    {
        $user = $this->authorizedUser();
        $this->createSite(status: 'up');
        $inactive = $this->createSite(status: 'up');
        $inactive->forceFill(['is_active' => false])->save();

        $response = $this->actingAs($user)->get('/analytics/overview');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('summary.kpis.assets_total', 1));
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user->givePermissionTo('monitoring.view_dashboard');

        return $user;
    }

    private function createSite(string $status = 'unknown', string $assetType = 'unknown'): Site
    {
        static $counter = 0;
        $counter++;

        $group = SiteGroup::query()->create([
            'name' => 'Grupo Analytics '.$counter,
            'slug' => 'grupo-analytics-'.$counter,
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio analytics '.$counter,
            'slug' => 'sitio-analytics-'.$counter,
            'domain' => 'analytics'.$counter.'.udg.mx',
            'url' => 'https://analytics'.$counter.'.udg.mx',
            'current_status' => $status,
            'asset_type' => $assetType,
            'is_monitored' => true,
        ]);
    }
}
