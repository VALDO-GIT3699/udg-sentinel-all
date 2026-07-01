<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Models\Alert;
use App\Models\SiteGroup;
use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteCheckRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Collection;
use Modules\Monitoring\Services\EvaluateSiteStatusService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EvaluateSiteStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_keeps_site_down_for_priority_one_when_consecutive_failures_threshold_is_met(): void
    {
        $siteCheckRepository = $this->createMock(SiteCheckRepositoryInterface::class);
        $siteRepository = $this->createMock(SiteRepositoryInterface::class);
        $alertRepository = $this->createMock(AlertRepositoryInterface::class);

        $siteCheckRepository
            ->expects($this->once())
            ->method('consecutiveStatusCount')
            ->with(10, 'down', 5)
            ->willReturn(2);

        $siteRepository->expects($this->never())->method('update');
        $alertRepository->expects($this->never())->method('openForSite');

        $service = new EvaluateSiteStatusService($siteCheckRepository, $siteRepository, $alertRepository);

        $site = new Site();
        $site->id = 10;
        $site->priority = 1;
        $site->current_status = 'down';
        $site->name = 'Portal UDG';
        $site->url = 'https://udg.mx';

        $result = $service->apply($site, 'down', 'timeout');

        $this->assertSame('down', $result);
    }

    #[Test]
    public function it_marks_site_as_degraded_when_successes_are_not_enough_to_recover(): void
    {
        $siteCheckRepository = $this->createMock(SiteCheckRepositoryInterface::class);
        $siteRepository = $this->createMock(SiteRepositoryInterface::class);
        $alertRepository = $this->createMock(AlertRepositoryInterface::class);

        $siteCheckRepository
            ->expects($this->once())
            ->method('consecutiveStatusCount')
            ->with(15, 'up', 5)
            ->willReturn(1);

        $siteRepository->expects($this->never())->method('update');

        $service = new EvaluateSiteStatusService($siteCheckRepository, $siteRepository, $alertRepository);

        $site = new Site();
        $site->id = 15;
        $site->priority = 2;
        $site->current_status = 'degraded';
        $site->name = 'Sistema Escolar';
        $site->url = 'https://escolar.udg.mx';

        $result = $service->apply($site, 'up');

        $this->assertSame('degraded', $result);
    }

    #[Test]
    public function it_resolves_open_alerts_when_a_site_recovers_from_degraded_to_up(): void
    {
        config([
            'broadcasting.default' => 'null',
            'broadcasting.connections.null' => ['driver' => 'null'],
            'activitylog.enabled' => false,
        ]);

        $siteCheckRepository = $this->createMock(SiteCheckRepositoryInterface::class);
        $siteRepository = $this->createMock(SiteRepositoryInterface::class);
        $alertRepository = $this->createMock(AlertRepositoryInterface::class);

        $group = SiteGroup::query()->create([
            'name' => 'Portales oficiales',
            'slug' => 'portales-oficiales-recovery',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal UDG',
            'slug' => 'portal-udg-recovery',
            'domain' => 'udg.mx',
            'url' => 'https://udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 2,
            'current_status' => 'degraded',
            'current_score' => 80,
            'current_score_level' => 'medium',
            'last_checked_at' => null,
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        $alert = Alert::query()->create([
            'site_id' => $site->id,
            'alert_rule_id' => null,
            'title' => 'Sitio oficial caido',
            'message' => 'Fallas consecutivas de disponibilidad detectadas.',
            'severity' => 'high',
            'status' => 'open',
            'triggered_at' => now()->subMinutes(10),
            'acknowledged_at' => null,
            'acknowledged_by' => null,
            'resolved_at' => null,
            'resolved_by' => null,
            'context' => ['event' => 'site.down.detected'],
        ]);

        $siteCheckRepository
            ->expects($this->once())
            ->method('consecutiveStatusCount')
            ->with($site->id, 'up', 5)
            ->willReturn(2);

        $siteRepository
            ->expects($this->once())
            ->method('update')
            ->with($site, $this->callback(static function (array $data): bool {
                return ($data['current_status'] ?? null) === 'up';
            }))
            ->willReturn(true);

        $alertRepository
            ->expects($this->once())
            ->method('openForSite')
            ->with($site->id)
            ->willReturn(new Collection([$alert]));

        $service = new EvaluateSiteStatusService($siteCheckRepository, $siteRepository, $alertRepository);

        $result = $service->apply($site, 'up');

        $this->assertSame('up', $result);
        $this->assertSame('resolved', $alert->fresh()->status);
        $this->assertNotNull($alert->fresh()->resolved_at);
    }
}
