<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Monitoring\Support\MassScanProgress;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MassScanProgressDashboardCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private const DASHBOARD_CACHE_KEYS = [
        'monitoring:dashboard:status-counts:all',
        'monitoring:dashboard:diagnostic-breakdown',
        'monitoring:dashboard:pipeline-metrics',
        'monitoring:dashboard:preventive-expirations',
        'monitoring:dashboard:search-suggestions',
    ];

    #[Test]
    public function finishing_a_mass_scan_evicts_the_stale_dashboard_aggregates(): void
    {
        $site = $this->createSite('scan-finishes');
        $this->primeStaleDashboardCache();

        $progress = MassScanProgress::start(totalSites: 1, siteIds: [(int) $site->id]);
        $runId = (string) $progress['run_id'];

        // Antes del fix, el dashboard seguia sirviendo estos valores cacheados
        // hasta 45s despues de que el escaneo ya habia terminado de verdad.
        MassScanProgress::completeTask($runId, 'inspection', (int) $site->id);

        foreach (self::DASHBOARD_CACHE_KEYS as $key) {
            $this->assertFalse(Cache::has($key), "El cache '{$key}' debio invalidarse al terminar el escaneo.");
        }
    }

    #[Test]
    public function aborting_a_mass_scan_also_evicts_the_stale_dashboard_aggregates(): void
    {
        $site = $this->createSite('scan-aborts');
        $this->primeStaleDashboardCache();

        $progress = MassScanProgress::start(totalSites: 1, siteIds: [(int) $site->id]);
        $runId = (string) $progress['run_id'];

        MassScanProgress::abortRun($runId, 'fallo simulado del batch');

        foreach (self::DASHBOARD_CACHE_KEYS as $key) {
            $this->assertFalse(Cache::has($key), "El cache '{$key}' debio invalidarse al abortar el escaneo.");
        }
    }

    private function primeStaleDashboardCache(): void
    {
        foreach (self::DASHBOARD_CACHE_KEYS as $key) {
            Cache::put($key, ['stale' => true], 45);
        }
    }

    private function createSite(string $slug): Site
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-'.$slug,
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio '.$slug,
            'slug' => $slug,
            'domain' => $slug.'.udg.mx',
            'url' => 'https://'.$slug.'.udg.mx',
            'current_status' => 'unknown',
        ]);
    }
}
