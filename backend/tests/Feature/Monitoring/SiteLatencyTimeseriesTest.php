<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\SiteGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class SiteLatencyTimeseriesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_forbids_a_user_without_view_site_detail_permission(): void
    {
        $user = User::factory()->create();
        $site = $this->createSite();

        $this->actingAs($user)
            ->getJson("/monitoring/sites/{$site->id}/latency-timeseries")
            ->assertForbidden();
    }

    #[Test]
    public function it_returns_only_this_sites_recent_checks(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();
        $otherSite = $this->createSite();

        SiteCheck::query()->create([
            'site_id' => $site->id,
            'checked_at' => now()->subMinutes(5),
            'status' => 'up',
            'http_code' => 200,
            'response_time_ms' => 120,
        ]);
        SiteCheck::query()->create([
            'site_id' => $otherSite->id,
            'checked_at' => now()->subMinutes(5),
            'status' => 'up',
            'http_code' => 200,
            'response_time_ms' => 999,
        ]);

        $response = $this->actingAs($user)->getJson("/monitoring/sites/{$site->id}/latency-timeseries");

        $response->assertOk();
        $points = $response->json('points');
        $this->assertCount(1, $points);
        $this->assertSame(120, $points[0]['response_time_ms']);
    }

    #[Test]
    public function it_excludes_checks_outside_the_requested_window(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        SiteCheck::query()->create([
            'site_id' => $site->id,
            'checked_at' => now()->subHours(5),
            'status' => 'up',
            'http_code' => 200,
            'response_time_ms' => 100,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/monitoring/sites/{$site->id}/latency-timeseries?minutes=30");

        $response->assertOk();
        $this->assertCount(0, $response->json('points'));
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.view_site_detail', 'web');
        $user->givePermissionTo('monitoring.view_site_detail');

        return $user;
    }

    private function createSite(): Site
    {
        static $counter = 0;
        $counter++;

        $group = SiteGroup::query()->create([
            'name' => 'Grupo Latencia '.$counter,
            'slug' => 'grupo-latencia-'.$counter,
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio latencia '.$counter,
            'slug' => 'sitio-latencia-'.$counter,
            'domain' => 'latencia'.$counter.'.udg.mx',
            'url' => 'https://latencia'.$counter.'.udg.mx',
        ]);
    }
}
