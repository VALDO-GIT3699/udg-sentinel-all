<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Monitoring\Jobs\RunHeadCheckJob;
use Modules\Monitoring\Jobs\RunSecurityHeadersCheckJob;
use Modules\Monitoring\Jobs\RunSiteInspectionJob;
use Modules\Monitoring\Jobs\RunSslCheckJob;
use Modules\Monitoring\Jobs\RunTechnologyScanJob;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DispatchAssetMonitoringCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function routine_dispatch_queues_the_unified_inspection_job_for_a_due_website(): void
    {
        Queue::fake();

        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-routine',
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio rutinario',
            'slug' => 'sitio-rutinario',
            'domain' => 'rutinario.udg.mx',
            'url' => 'https://rutinario.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'asset_type' => 'website',
            'current_status' => 'unknown',
            'check_interval_min' => 5,
            'last_checked_at' => null,
        ]);

        $this->artisan('monitoring:dispatch-asset-monitoring', ['--limit' => 10])
            ->assertExitCode(0);

        // El pipeline unificado debe quedar encolado para el ciclo automatico...
        Queue::assertPushed(RunSiteInspectionJob::class, function (RunSiteInspectionJob $job) use ($site): bool {
            return $this->privateSiteId($job) === (int) $site->id;
        });

        // ...y ninguno de los jobs legacy, que ya no alimentan el dashboard.
        Queue::assertNotPushed(RunHeadCheckJob::class);
        Queue::assertNotPushed(RunSecurityHeadersCheckJob::class);
        Queue::assertNotPushed(RunSslCheckJob::class);
        Queue::assertNotPushed(RunTechnologyScanJob::class);
    }

    private function privateSiteId(RunSiteInspectionJob $job): int
    {
        $property = new \ReflectionProperty(RunSiteInspectionJob::class, 'siteId');

        return (int) $property->getValue($job);
    }
}
