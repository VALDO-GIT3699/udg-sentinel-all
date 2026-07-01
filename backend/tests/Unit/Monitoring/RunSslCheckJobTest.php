<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Models\Alert;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SiteGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Monitoring\Jobs\RunSslCheckJob;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

final class RunSslCheckJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_emits_ssl_expired_even_when_an_expiring_soon_alert_is_already_open(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales oficiales',
            'slug' => 'portales-oficiales-ssl',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal UDG',
            'slug' => 'portal-udg-ssl',
            'domain' => 'udg.mx',
            'url' => 'https://udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'up',
            'current_score' => 100,
            'current_score_level' => 'excellent',
            'last_checked_at' => null,
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        $warningAlert = Alert::query()->create([
            'site_id' => $site->id,
            'alert_rule_id' => null,
            'title' => 'SSL por vencer',
            'message' => 'Certificado SSL en aviso: 20 dias restantes.',
            'severity' => 'high',
            'status' => 'open',
            'triggered_at' => now()->subDay(),
            'acknowledged_at' => null,
            'acknowledged_by' => null,
            'resolved_at' => null,
            'resolved_by' => null,
            'context' => ['event' => 'ssl.expiring.soon'],
        ]);

        $alertRepository = $this->createMock(AlertRepositoryInterface::class);
        $alertRepository
            ->expects($this->once())
            ->method('openForSite')
            ->with($site->id)
            ->willReturn(new Collection([$warningAlert]));
        $alertRepository
            ->expects($this->once())
            ->method('create')
            ->willReturnCallback(static function (array $data): Alert {
                return Alert::query()->create($data);
            });

        $job = new RunSslCheckJob($site->id);

        $method = new ReflectionMethod($job, 'openSslAlertIfNeeded');
        $method->setAccessible(true);
        $method->invoke(
            $job,
            $alertRepository,
            $site,
            'SSL expirado',
            'Certificado SSL expirado hace 3 dias.',
            'critical',
            'ssl.expired'
        );

        $warningAlert->refresh();
        $sslAlerts = Alert::query()->where('site_id', $site->id)->get();

        $this->assertSame('resolved', $warningAlert->status);
        $this->assertNotNull($warningAlert->resolved_at);
        $this->assertSame(1, SiteEvent::query()->where('site_id', $site->id)->where('event_type', 'ssl.expired')->count());
        $this->assertCount(1, $sslAlerts->filter(static fn (Alert $alert): bool => ($alert->context['event'] ?? null) === 'ssl.expired' && $alert->status === 'open'));
    }
}
