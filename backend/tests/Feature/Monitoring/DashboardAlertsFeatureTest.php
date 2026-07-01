<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Alert;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\SiteGroup;
use App\Models\TrafficMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response->assertJsonPath('props.openAlerts.0.title', 'Caida del portal');
        $response->assertJsonPath('props.openAlertsCount', 1);
    }

    #[Test]
    public function dashboard_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('monitoring.dashboard'));

        $response->assertForbidden();
    }

    #[Test]
    public function dashboard_exposes_group_summary_and_recent_timeline(): void
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

        SiteCheck::query()->create([
            'site_id' => $site->id,
            'checked_at' => now()->subMinutes(5),
            'status' => 'down',
            'http_code' => 503,
            'response_time_ms' => 930,
            'response_size_bytes' => 512,
            'ip_resolved' => null,
            'redirect_url' => null,
            'error_message' => 'service unavailable',
            'checked_from' => 'test',
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($user)->get(route('monitoring.dashboard'), [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertOk();
        $response->assertJsonPath('props.timeline.0.status', 'CAÍDO');
        $response->assertJsonPath('props.timeline.0.site.name', 'Plataforma escolar');
        $response->assertJsonPath('props.statusByGroup.0.name', 'Servicios academicos');
    }

    #[Test]
    public function dashboard_exposes_heatmap_and_realtime_timelines_for_traffic(): void
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user->givePermissionTo('monitoring.view_dashboard');

        $group = SiteGroup::query()->create([
            'name' => 'Portales de alta demanda',
            'slug' => 'portales-alta-demanda',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#F97316',
        ]);

        $slowSite = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Biblioteca digital',
            'slug' => 'biblioteca-digital',
            'domain' => 'biblioteca.udg.mx',
            'url' => 'https://biblioteca.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'degraded',
            'current_score' => 77,
            'current_score_level' => 'medium',
            'last_checked_at' => now(),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        $healthySite = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal noticias',
            'slug' => 'portal-noticias-traffic',
            'domain' => 'noticias.udg.mx',
            'url' => 'https://noticias.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 2,
            'current_status' => 'up',
            'current_score' => 93,
            'current_score_level' => 'good',
            'last_checked_at' => now(),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        foreach ([15, 10, 5] as $minutesAgo) {
            TrafficMetric::query()->create([
                'site_id' => $slowSite->id,
                'recorded_at' => now()->subMinutes($minutesAgo),
                'requests_per_min' => 180 - $minutesAgo,
                'unique_visitors' => null,
                'bandwidth_bytes' => null,
                'error_rate_pct' => 12.5,
                'avg_response_time_ms' => 920 - ($minutesAgo * 5),
            ]);

            TrafficMetric::query()->create([
                'site_id' => $healthySite->id,
                'recorded_at' => now()->subMinutes($minutesAgo),
                'requests_per_min' => 55 - intdiv($minutesAgo, 5),
                'unique_visitors' => null,
                'bandwidth_bytes' => null,
                'error_rate_pct' => 0.0,
                'avg_response_time_ms' => 120 - $minutesAgo,
            ]);
        }

        $response = $this->actingAs($user)->get(route('monitoring.dashboard'), [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertOk();
        $response->assertJsonPath('props.refreshIntervalMs', 5000);
        $response->assertJsonPath('props.trafficOverview.heatmap.0.name', 'Biblioteca digital');
        $response->assertJsonPath('props.trafficOverview.heatmap.0.status', 'DEGRADADO');
        $response->assertJsonPath('props.trafficOverview.timelines.0.name', 'Biblioteca digital');
        $response->assertJsonPath('props.trafficOverview.timelines.0.points.0.latencyMs', 845);
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
