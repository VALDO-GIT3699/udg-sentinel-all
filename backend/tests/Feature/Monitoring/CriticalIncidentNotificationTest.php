<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\User;
use App\Notifications\SiteDownNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Monitoring\Events\SiteStatusChanged;
use Modules\Monitoring\Listeners\PersistSiteIncidentListener;
use Modules\Monitoring\Support\MonitoringPermissionMatrix;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CriticalIncidentNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_notify_when_the_global_setting_is_disabled(): void
    {
        Notification::fake();
        Setting::set('monitoring.notifications_enabled', false);

        $admin = $this->createAdmin();
        $site = $this->createPrioritySite();

        app(PersistSiteIncidentListener::class)->handle($this->downEvent($site->id));

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_notifies_opted_in_admins_but_not_opted_out_ones(): void
    {
        Notification::fake();
        Setting::set('monitoring.notifications_enabled', true);

        $optedIn = $this->createAdmin();
        $optedOut = $this->createAdmin();
        $optedOut->forceFill(['notify_on_critical_incidents' => false])->save();

        $site = $this->createPrioritySite();

        app(PersistSiteIncidentListener::class)->handle($this->downEvent($site->id));

        Notification::assertSentTo($optedIn, SiteDownNotification::class);
        Notification::assertNotSentTo($optedOut, SiteDownNotification::class);
    }

    private function downEvent(int $siteId): SiteStatusChanged
    {
        return new SiteStatusChanged($siteId, [
            'statusBeforeCode' => 'up',
            'statusAfterCode' => 'down',
            'severity' => 'critical',
            'cause' => 'Timeout de conexión',
            'detectedAt' => now()->toIso8601String(),
        ]);
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Role::findOrCreate(MonitoringPermissionMatrix::ADMIN_ROLE, 'web');
        $user->assignRole(MonitoringPermissionMatrix::ADMIN_ROLE);

        return $user;
    }

    private function createPrioritySite(): Site
    {
        $group = SiteGroup::query()->create([
            'name' => 'Grupo Incidentes',
            'slug' => 'grupo-incidentes',
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio prioritario',
            'slug' => 'sitio-prioritario',
            'domain' => 'prioritario.udg.mx',
            'url' => 'https://prioritario.udg.mx',
            'priority' => 1,
        ]);
    }
}
