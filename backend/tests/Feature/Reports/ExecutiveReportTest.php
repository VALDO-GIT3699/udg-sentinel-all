<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Alert;
use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExecutiveReportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_guest_cannot_reach_the_report_summary(): void
    {
        $this->get('/reports/summary')->assertRedirect('/login');
    }

    #[Test]
    public function it_returns_the_executive_summary_with_kpis(): void
    {
        $user = User::factory()->create();
        $this->createSite(assetType: 'website', status: 'up');

        $response = $this->actingAs($user)->getJson('/reports/summary');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'generated_at',
                'summary' => ['kpis'],
                'sites',
                'open_alerts',
            ],
        ]);
    }

    #[Test]
    public function it_filters_the_site_list_by_asset_type(): void
    {
        $user = User::factory()->create();
        $this->createSite(name: 'Portal web', assetType: 'website', status: 'up');
        $this->createSite(name: 'API institucional', assetType: 'rest_api', status: 'up');

        $response = $this->actingAs($user)->getJson('/reports/summary?asset_type=rest_api');

        $response->assertOk();
        $sites = $response->json('data.sites');
        $this->assertCount(1, $sites);
        $this->assertSame('API institucional', $sites[0]['name']);
    }

    #[Test]
    public function it_filters_open_alerts_by_severity(): void
    {
        $user = User::factory()->create();
        $site = $this->createSite();

        Alert::query()->create([
            'site_id' => $site->id,
            'title' => 'Certificado por expirar',
            'message' => 'Detalle',
            'severity' => 'critical',
            'status' => 'open',
            'triggered_at' => now(),
        ]);

        Alert::query()->create([
            'site_id' => $site->id,
            'title' => 'Latencia elevada',
            'message' => 'Detalle',
            'severity' => 'low',
            'status' => 'open',
            'triggered_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/reports/summary?severity=critical');

        $response->assertOk();
        $alerts = $response->json('data.open_alerts');
        $this->assertCount(1, $alerts);
        $this->assertSame('critical', $alerts[0]['severity']);
    }

    #[Test]
    public function resolved_alerts_are_excluded_from_the_report(): void
    {
        $user = User::factory()->create();
        $site = $this->createSite();

        Alert::query()->create([
            'site_id' => $site->id,
            'title' => 'Incidente ya resuelto',
            'message' => 'Detalle',
            'severity' => 'high',
            'status' => 'resolved',
            'triggered_at' => now(),
            'resolved_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/reports/summary');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.open_alerts'));
    }

    #[Test]
    public function the_csv_export_is_downloadable_and_reflects_the_kpis(): void
    {
        $user = User::factory()->create();
        $this->createSite(status: 'up');

        $response = $this->actingAs($user)->get('/reports/export/executive.csv');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertDownload('udg-sentinel-report.csv');
        $this->assertStringContainsString('disponibilidad_24h', $response->getContent());
    }

    #[Test]
    public function the_csv_export_is_rate_limited_per_user(): void
    {
        $user = User::factory()->create();
        $this->createSite();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->get('/reports/export/executive.csv')->assertOk();
        }

        // El limitador "exports" permite 10/min por usuario — la 11a debe cortar.
        $this->actingAs($user)->get('/reports/export/executive.csv')->assertStatus(429);
    }

    private function createSite(string $name = 'Sitio de reporte', string $assetType = 'website', string $status = 'unknown'): Site
    {
        static $counter = 0;
        $counter++;

        $group = SiteGroup::query()->create([
            'name' => 'Grupo Reportes '.$counter,
            'slug' => 'grupo-reportes-'.$counter,
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => $name,
            'slug' => 'sitio-reporte-'.$counter,
            'domain' => 'reporte'.$counter.'.udg.mx',
            'url' => 'https://reporte'.$counter.'.udg.mx',
            'asset_type' => $assetType,
            'current_status' => $status,
        ]);
    }
}
