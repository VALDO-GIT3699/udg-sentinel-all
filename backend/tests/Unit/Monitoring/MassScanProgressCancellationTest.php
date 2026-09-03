<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\SiteInspectionProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Monitoring\Support\MassScanProgress;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MassScanProgressCancellationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cancelling_preserves_previously_detected_technology_and_only_flags_pending_sites(): void
    {
        // Sitio con tecnologia real detectada en una corrida anterior.
        $siteWithHistory = $this->createSite('with-history');
        SiteInspectionProfile::query()->create([
            'site_id' => $siteWithHistory->id,
            'cms_name' => 'WordPress',
            'cms_version' => '6.4',
            'runtime_name' => 'PHP',
            'runtime_version' => '8.2',
            'risk_level' => 'Bajo',
            'risk_score' => 10,
            'essential_checks_complete' => true,
            'inspected_at' => now()->subDay(),
        ]);
        $siteWithHistory->forceFill(['current_status' => 'up'])->save();

        // Sitio que nunca se habia inspeccionado antes.
        $siteNeverScanned = $this->createSite('never-scanned');

        // Sitio que SI alcanzo a terminar antes de que se cancelara la corrida.
        $siteAlreadyDone = $this->createSite('already-done');

        $progress = MassScanProgress::start(totalSites: 3, siteIds: [
            (int) $siteWithHistory->id,
            (int) $siteNeverScanned->id,
            (int) $siteAlreadyDone->id,
        ]);

        $runId = (string) $progress['run_id'];
        MassScanProgress::completeTask($runId, 'inspection', (int) $siteAlreadyDone->id);

        $cancelled = MassScanProgress::cancel($runId);

        $this->assertNotNull($cancelled);
        $this->assertSame('cancelled', $cancelled['status']);

        // El sitio con historial conserva su tecnologia y su status tal cual estaban.
        $siteWithHistory->refresh();
        $profileWithHistory = $siteWithHistory->inspectionProfile()->first();
        $this->assertSame('up', $siteWithHistory->current_status);
        $this->assertSame('WordPress', $profileWithHistory->cms_name);
        $this->assertSame('6.4', $profileWithHistory->cms_version);
        $this->assertSame('Bajo', $profileWithHistory->risk_level);
        $this->assertNotNull($profileWithHistory->scan_interrupted_at);

        // El sitio nunca escaneado queda marcado, sin tecnologia que inventar.
        $siteNeverScanned->refresh();
        $profileNeverScanned = $siteNeverScanned->inspectionProfile()->first();
        $this->assertSame('unknown', $siteNeverScanned->current_status);
        $this->assertNotNull($profileNeverScanned);
        $this->assertNotNull($profileNeverScanned->scan_interrupted_at);

        // El sitio que SI termino antes de cancelar no debe quedar marcado.
        $siteAlreadyDone->refresh();
        $profileAlreadyDone = $siteAlreadyDone->inspectionProfile()->first();
        $this->assertNull($profileAlreadyDone?->scan_interrupted_at);
    }

    #[Test]
    public function cancelling_a_run_that_is_not_running_does_nothing(): void
    {
        $site = $this->createSite('finished-run');

        $progress = MassScanProgress::start(totalSites: 1, siteIds: [(int) $site->id]);
        $runId = (string) $progress['run_id'];

        MassScanProgress::completeTask($runId, 'inspection', (int) $site->id);

        $result = MassScanProgress::cancel($runId);

        $this->assertNull($result);
    }

    private function createSite(string $slug): Site
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-cancel-'.$slug,
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
