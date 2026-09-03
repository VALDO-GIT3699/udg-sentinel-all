<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Alert;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\SiteGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class ExecutiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_forbids_a_user_without_view_dashboard_permission(): void
    {
        // Regresion: este modulo solo exigia sesion iniciada, sin ningun
        // permiso de monitoreo -a diferencia de Analytics, que ya protege el
        // mismo tipo de datos con "monitoring.view_dashboard".
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboards')
            ->assertForbidden();
    }

    #[Test]
    public function it_renders_the_executive_dashboard(): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->get('/dashboards');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->has('summary.kpis')
            ->has('chartDataUrl')
            ->has('reportUrl'));
    }

    #[Test]
    public function it_computes_uptime_from_recent_site_checks(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        SiteCheck::query()->create([
            'site_id' => $site->id,
            'checked_at' => now()->subHours(2),
            'status' => 'up',
            'http_code' => 200,
        ]);
        SiteCheck::query()->create([
            'site_id' => $site->id,
            'checked_at' => now()->subHours(1),
            'status' => 'down',
            'http_code' => 500,
        ]);

        $response = $this->actingAs($user)->get('/dashboards');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('summary.kpis.uptime_7d_pct', 50));
    }

    #[Test]
    public function open_alerts_are_counted_but_resolved_ones_are_not(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        Alert::query()->create([
            'site_id' => $site->id,
            'title' => 'Alerta abierta',
            'message' => 'Detalle',
            'severity' => 'high',
            'status' => 'open',
            'triggered_at' => now(),
        ]);
        Alert::query()->create([
            'site_id' => $site->id,
            'title' => 'Alerta resuelta',
            'message' => 'Detalle',
            'severity' => 'low',
            'status' => 'resolved',
            'triggered_at' => now(),
            'resolved_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/dashboards');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('summary.kpis.open_alerts', 1)
            ->where('summary.top_alerts.0.title', 'Alerta abierta'));
    }

    #[Test]
    public function the_chart_data_endpoint_requires_the_same_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/dashboards/chart-data')
            ->assertForbidden();
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user->givePermissionTo('monitoring.view_dashboard');

        return $user;
    }

    private function createSite(): Site
    {
        static $counter = 0;
        $counter++;

        $group = SiteGroup::query()->create([
            'name' => 'Grupo Dashboard '.$counter,
            'slug' => 'grupo-dashboard-'.$counter,
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio dashboard '.$counter,
            'slug' => 'sitio-dashboard-'.$counter,
            'domain' => 'dashboard'.$counter.'.udg.mx',
            'url' => 'https://dashboard'.$counter.'.udg.mx',
        ]);
    }
}
