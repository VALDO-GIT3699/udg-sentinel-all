<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class SiteRegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_registers_a_site_when_url_and_current_key_are_valid(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 30, 0, 'UTC'));
        // 2026-08-28 20:30 UTC is 2026-08-28 14:30 America/Mexico_City.
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->postJson('/monitoring/sites/register', [
            'url' => 'https://nuevo-sitio.udg.mx/portal',
            'clave' => '2808202614',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.domain', 'nuevo-sitio.udg.mx');

        $this->assertDatabaseHas('sites', [
            'domain' => 'nuevo-sitio.udg.mx',
            'url' => 'https://nuevo-sitio.udg.mx/portal',
        ]);

        $this->assertDatabaseHas('site_groups', [
            'slug' => 'altas-manuales',
        ]);
    }

    #[Test]
    public function it_accepts_the_previous_hour_key_as_a_grace_window(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 2, 0, 'UTC'));
        // Just after the hour rolled over in Mexico City (13:00 -> 14:02).
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->postJson('/monitoring/sites/register', [
            'url' => 'https://otro-sitio.udg.mx',
            'clave' => '2808202613',
        ]);

        $response->assertCreated();
    }

    #[Test]
    public function it_rejects_an_expired_or_wrong_key(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 30, 0, 'UTC'));
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->postJson('/monitoring/sites/register', [
            'url' => 'https://nuevo-sitio.udg.mx',
            'clave' => '2808202599',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('sites', ['domain' => 'nuevo-sitio.udg.mx']);
    }

    #[Test]
    public function it_rejects_a_malformed_url(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 30, 0, 'UTC'));
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->postJson('/monitoring/sites/register', [
            'url' => 'not-a-url',
            'clave' => '2808202614',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_rejects_urls_pointing_to_private_or_local_hosts(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 30, 0, 'UTC'));
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->postJson('/monitoring/sites/register', [
            'url' => 'http://127.0.0.1/admin',
            'clave' => '2808202614',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('sites', ['domain' => '127.0.0.1']);
    }

    #[Test]
    public function it_rejects_a_duplicate_domain(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 30, 0, 'UTC'));
        $user = $this->authorizedUser();

        $group = SiteGroup::query()->create([
            'name' => 'Portales oficiales',
            'slug' => 'portales-oficiales',
            'color' => '#0EA5E9',
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'nuevo-sitio.udg.mx',
            'slug' => 'nuevo-sitio-udg-mx',
            'domain' => 'nuevo-sitio.udg.mx',
            'url' => 'https://nuevo-sitio.udg.mx',
        ]);

        $response = $this->actingAs($user)->postJson('/monitoring/sites/register', [
            'url' => 'https://nuevo-sitio.udg.mx/otra-ruta',
            'clave' => '2808202614',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_forbids_users_without_manage_sites_permission(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 28, 20, 30, 0, 'UTC'));
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/monitoring/sites/register', [
            'url' => 'https://nuevo-sitio.udg.mx',
            'clave' => '2808202614',
        ]);

        $response->assertStatus(403);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'activitylog.enabled' => false,
            'activitylog.default_log_name' => 'testing',
        ]);
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.manage_sites', 'web');
        $user->givePermissionTo('monitoring.manage_sites');

        return $user;
    }
}
