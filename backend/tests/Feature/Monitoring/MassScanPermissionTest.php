<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Regresion: antes, cualquier usuario autenticado con acceso de solo lectura
 * al dashboard (monitoring.view_dashboard) podia disparar un escaneo masivo o
 * un reescaneo individual -las rutas nunca exigian un permiso de escritura.
 */
final class MassScanPermissionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_view_only_user_cannot_trigger_a_mass_scan(): void
    {
        $user = $this->viewOnlyUser();

        $this->actingAs($user)->post('/monitoring/dashboard/scan-all')->assertForbidden();
        $this->actingAs($user)->post('/monitoring/dashboard/scan-selected', ['site_ids' => [1]])->assertForbidden();
    }

    #[Test]
    public function a_view_only_user_cannot_trigger_a_single_site_rescan(): void
    {
        $user = $this->viewOnlyUser();
        $site = $this->createSite();

        $this->actingAs($user)
            ->post("/monitoring/sites/{$site->id}/scan")
            ->assertForbidden();
    }

    #[Test]
    public function an_operator_with_run_mass_scan_can_trigger_scans(): void
    {
        $user = $this->operatorUser();
        $site = $this->createSite();

        $this->actingAs($user)
            ->post("/monitoring/sites/{$site->id}/scan")
            ->assertRedirect();
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
            'slug' => 'portales-udg-scan-perm',
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio de pruebas',
            'slug' => 'sitio-de-pruebas-scan-perm',
            'domain' => 'scan-perm.udg.mx',
            'url' => 'https://scan-perm.udg.mx',
        ]);
    }
}
