<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\NotificationChannel;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_forbids_a_user_without_manage_settings_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/monitoring/admin/notifications')->assertForbidden();
    }

    #[Test]
    public function it_shows_the_global_toggle_and_existing_channels(): void
    {
        $admin = $this->authorizedUser();
        Setting::set('monitoring.notifications_enabled', true);
        NotificationChannel::query()->create([
            'name' => 'Correo de guardia',
            'type' => 'email',
            'config' => ['to' => 'guardia@udg.mx'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get('/monitoring/admin/notifications');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Notifications')
            ->where('globalEnabled', true)
            ->where('channels.0.name', 'Correo de guardia')
            ->where('channels.0.destination', 'guardia@udg.mx'));
    }

    #[Test]
    public function it_toggles_the_global_setting(): void
    {
        $admin = $this->authorizedUser();

        $this->actingAs($admin)
            ->patchJson('/monitoring/admin/notifications/global', ['enabled' => true])
            ->assertOk();

        $this->assertTrue((bool) Setting::get('monitoring.notifications_enabled'));
    }

    #[Test]
    public function it_creates_an_email_channel_with_encrypted_config(): void
    {
        $admin = $this->authorizedUser();

        $response = $this->actingAs($admin)->postJson('/monitoring/admin/notifications/channels', [
            'name' => 'Correo de guardia',
            'type' => 'email',
            'destination' => 'guardia@udg.mx',
        ]);

        $response->assertStatus(201);

        $channel = NotificationChannel::query()->first();
        $this->assertNotNull($channel);
        $this->assertSame('guardia@udg.mx', $channel->config['to']);

        // El valor crudo en BD debe ser el texto cifrado, no el correo en claro.
        $this->assertStringNotContainsString('guardia@udg.mx', (string) $channel->getRawOriginal('config'));
    }

    #[Test]
    public function it_toggles_and_deletes_a_channel(): void
    {
        $admin = $this->authorizedUser();
        $channel = NotificationChannel::query()->create([
            'name' => 'Slack ops',
            'type' => 'slack',
            'config' => ['webhook_url' => 'https://hooks.slack.test/abc'],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patchJson("/monitoring/admin/notifications/channels/{$channel->id}/toggle")
            ->assertOk()
            ->assertJson(['is_active' => false]);

        $this->actingAs($admin)
            ->deleteJson("/monitoring/admin/notifications/channels/{$channel->id}")
            ->assertOk();

        $this->assertDatabaseMissing('notification_channels', ['id' => $channel->id]);
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.manage_settings', 'web');
        $user->givePermissionTo('monitoring.manage_settings');

        return $user;
    }
}
