<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class EnsureUserIsActiveMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lets_an_active_user_through(): void
    {
        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('monitoring.view_dashboard');

        $this->actingAs($user)
            ->get('/monitoring/dashboard/scan-progress')
            ->assertOk();
    }

    #[Test]
    public function it_logs_out_a_user_deactivated_mid_session(): void
    {
        // Antes, is_active solo se validaba al momento del login: si un admin
        // desactivaba a alguien con sesion abierta, esa persona seguia
        // usando el sistema hasta que la sesion expirara sola.
        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('monitoring.view_dashboard');

        $this->actingAs($user)->get('/monitoring/dashboard/scan-progress')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $response = $this->get('/monitoring/dashboard/scan-progress');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function it_is_a_no_op_for_guests(): void
    {
        $middleware = new EnsureUserIsActive;

        $response = $middleware->handle(Request::create('/'), function ($request) {
            return response('ok');
        });

        $this->assertSame('ok', $response->getContent());
        $this->assertGuest();
    }
}
