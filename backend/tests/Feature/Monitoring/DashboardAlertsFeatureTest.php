<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Alert;
use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class DashboardAlertsFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'activitylog.enabled' => false,
            'activitylog.default_log_name' => 'testing',
        ]);
    }

    #[Test]
    public function dashboard_shows_open_alerts_for_authorized_user(): void
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user->givePermissionTo('monitoring.view_dashboard');

        $group = SiteGroup::query()->create([
            'name' => 'Portales oficiales',
            'slug' => 'portales-oficiales',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal oficial UDG',
            'slug' => 'portal-oficial-udg',
            'domain' => 'udg.mx',
            'url' => 'https://udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'down',
            'current_score' => 80,
            'current_score_level' => 'medium',
            'last_checked_at' => now(),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        Alert::query()->create([
            'site_id' => $site->id,
            'alert_rule_id' => null,
            'title' => 'Caida del portal',
            'message' => 'Sitio oficial no responde por HEAD.',
            'severity' => 'critical',
            'status' => 'open',
            'triggered_at' => now(),
            'acknowledged_at' => null,
            'acknowledged_by' => null,
            'resolved_at' => null,
            'resolved_by' => null,
            'context' => ['event' => 'site.down.detected'],
        ]);

        $response = $this->actingAs($user)->get(route('monitoring.dashboard'), [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertOk();
        $response->assertHeader('X-Inertia', 'true');
        $response->assertJsonPath('props.statusCounts.down', 1);
        $response->assertJsonPath('props.sites.data.0.name', 'Portal oficial UDG');

        $props = $response->json('props');

        $this->assertIsArray($props);
        $this->assertArrayNotHasKey('trafficOverview', $props);
        $this->assertArrayNotHasKey('timeline', $props);
        $this->assertArrayNotHasKey('siteTelemetry', $props);
        $this->assertArrayNotHasKey('openAlerts', $props);
    }

    #[Test]
    public function dashboard_marks_stale_sites_as_unknown_even_when_their_raw_status_is_up(): void
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user->givePermissionTo('monitoring.view_dashboard');

        $group = SiteGroup::query()->create([
            'name' => 'Servicios web',
            'slug' => 'servicios-web',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio operativo sin actualizacion',
            'slug' => 'sitio-operativo-sin-actualizacion',
            'domain' => 'stale.udg.mx',
            'url' => 'https://stale.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'up',
            'current_score' => 95,
            'current_score_level' => 'good',
            'last_checked_at' => now()->subHours(2),
            'check_interval_min' => 10,
            'notes' => null,
            'tags' => [],
        ]);

        $response = $this->actingAs($user)->get(route('monitoring.dashboard'), [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertOk();
        $response->assertJsonPath('props.statusCounts.unknown', 1);
        $response->assertJsonPath('props.statusCounts.up', 0);
        $response->assertJsonPath('props.sites.data.0.display_status_code', 'unknown');
        $response->assertJsonPath('props.sites.data.0.diagnostic_label', 'Sin actualizar');
    }

    #[Test]
    public function dashboard_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('monitoring.dashboard'));

        $response->assertForbidden();
    }

    #[Test]
    public function dashboard_uses_strict_zero_fallbacks_for_metrics_without_samples(): void
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user->givePermissionTo('monitoring.view_dashboard');

        $group = SiteGroup::query()->create([
            'name' => 'Servicios academicos',
            'slug' => 'servicios-academicos',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Plataforma escolar',
            'slug' => 'plataforma-escolar',
            'domain' => 'escolar.udg.mx',
            'url' => 'https://escolar.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'down',
            'current_score' => 70,
            'current_score_level' => 'low',
            'last_checked_at' => now(),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        $response = $this->actingAs($user)->get(route('monitoring.dashboard'), [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertOk();
        $response->assertJsonPath('props.pipelineMetrics.totalChecks', 0);
        $response->assertJsonPath('props.pipelineMetrics.downChecks', 0);
        $response->assertJsonPath('props.pipelineMetrics.errorRatePct', 0);
        $response->assertJsonPath('props.pipelineMetrics.avgLatencyMs', 0);
        $response->assertJsonPath('props.sites.data.0.name', 'Plataforma escolar');
    }

    #[Test]
    public function dashboard_triggers_telemetry_warmup_once_in_local_environments(): void
    {
        $this->app['env'] = 'local';

        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user->givePermissionTo('monitoring.view_dashboard');

        Cache::shouldReceive('add')
            ->once()
            ->with('monitoring:dashboard:warmup', \Mockery::type('int'), 60)
            ->andReturnTrue();

        Artisan::shouldReceive('call')->times(4)->andReturn(0);

        $response = $this->actingAs($user)->get(route('monitoring.dashboard'), [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertOk();
    }

    #[Test]
    public function group_view_filters_sites_by_status(): void
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user->givePermissionTo('monitoring.view_dashboard');

        $group = SiteGroup::query()->create([
            'name' => 'Portales institucionales',
            'slug' => 'portales-institucionales',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#22C55E',
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal rectoria',
            'slug' => 'portal-rectoria',
            'domain' => 'rectoria.udg.mx',
            'url' => 'https://rectoria.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'down',
            'current_score' => 65,
            'current_score_level' => 'low',
            'last_checked_at' => now(),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal noticias',
            'slug' => 'portal-noticias',
            'domain' => 'noticias.udg.mx',
            'url' => 'https://noticias.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 2,
            'current_status' => 'up',
            'current_score' => 92,
            'current_score_level' => 'good',
            'last_checked_at' => now(),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        $response = $this->actingAs($user)->get(route('monitoring.groups.view', [
            'group' => $group->id,
            'status' => 'down',
        ]), [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertOk();
        $response->assertJsonPath('props.sites.data.0.name', 'Portal rectoria');
        $response->assertJsonPath('props.sites.data.0.current_status', 'down');
    }
}
