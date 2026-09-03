<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Monitoring\Support\MassScanProgress;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

final class MassScanProgressReconciliationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reconciliation_only_marks_down_the_sites_that_belong_to_this_run(): void
    {
        // Sitio A: parte de la corrida y se quedo sin terminar de escanear.
        $siteInRun = $this->createSite('run-site');
        // Sitio B: 'unknown' por otra razon (p. ej. recien creado), pero fuera de esta corrida.
        $siteOutsideRun = $this->createSite('other-site');

        $progress = MassScanProgress::start(
            totalSites: 1,
            siteIds: [(int) $siteInRun->id],
        );

        $runId = (string) $progress['run_id'];
        $this->assertNotSame('', $runId);

        $method = new ReflectionMethod(MassScanProgress::class, 'reconcilePendingSites');
        $reconciledCount = $method->invoke(null, $runId);

        $this->assertSame(1, $reconciledCount);

        $siteInRun->refresh();
        $siteOutsideRun->refresh();

        $this->assertSame('down', $siteInRun->current_status);
        // El sitio fuera de esta corrida nunca debe tocarse.
        $this->assertSame('unknown', $siteOutsideRun->current_status);
    }

    #[Test]
    public function reconciliation_does_nothing_when_the_run_site_list_is_unknown(): void
    {
        $site = $this->createSite('orphan-run-site');

        $method = new ReflectionMethod(MassScanProgress::class, 'reconcilePendingSites');
        $reconciledCount = $method->invoke(null, 'run-id-with-no-cached-site-list');

        $this->assertSame(0, $reconciledCount);

        $site->refresh();
        $this->assertSame('unknown', $site->current_status);
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
