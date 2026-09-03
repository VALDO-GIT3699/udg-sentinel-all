<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Monitoring\Support\MassScanProgress;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class MassScanCancellationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_view_only_user_cannot_cancel_a_scan(): void
    {
        $user = $this->viewOnlyUser();
        $site = $this->createSite();
        $progress = MassScanProgress::start(totalSites: 1, siteIds: [(int) $site->id]);

        $this->actingAs($user)
            ->postJson('/monitoring/scans/'.$progress['run_id'].'/cancel')
            ->assertForbidden();
    }

    #[Test]
    public function an_operator_can_cancel_a_running_scan(): void
    {
        $user = $this->operatorUser();
        $site = $this->createSite();
        $progress = MassScanProgress::start(totalSites: 1, siteIds: [(int) $site->id]);

        $response = $this->actingAs($user)
            ->postJson('/monitoring/scans/'.$progress['run_id'].'/cancel');

        $response->assertOk();
        $response->assertJson(['cancelled' => true]);
        $this->assertSame('cancelled', $response->json('progress.status'));
    }

    #[Test]
    public function cancelling_a_run_that_already_finished_returns_a_conflict(): void
    {
        $user = $this->operatorUser();
        $site = $this->createSite();
        $progress = MassScanProgress::start(totalSites: 1, siteIds: [(int) $site->id]);
        MassScanProgress::completeTask((string) $progress['run_id'], 'inspection', (int) $site->id);

        $response = $this->actingAs($user)
            ->postJson('/monitoring/scans/'.$progress['run_id'].'/cancel');

        $response->assertStatus(409);
        $response->assertJson(['cancelled' => false]);
    }

    private function viewOnlyUser(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user->givePermissionTo('monitoring.view_dashboard');

        return $user;
    }

    private function operatorUser(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        Permission::findOrCreate('monitoring.run_mass_scan', 'web');
        $user->givePermissionTo(['monitoring.view_dashboard', 'monitoring.run_mass_scan']);

        return $user;
    }

    private function createSite(): Site
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-cancel-endpoint',
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio de pruebas',
            'slug' => 'sitio-de-pruebas-cancel-endpoint',
            'domain' => 'cancel-endpoint.udg.mx',
            'url' => 'https://cancel-endpoint.udg.mx',
        ]);
    }
}
