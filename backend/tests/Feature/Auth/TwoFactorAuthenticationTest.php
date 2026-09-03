<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_can_enable_and_confirm_two_factor_authentication(): void
    {
        $user = $this->userWithDashboardAccess();

        $setup = $this->actingAs($user)
            ->postJson('/account/two-factor/enable')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($setup['secret']);
        $this->assertStringContainsString('<svg', $setup['qr_code_svg']);

        $user->refresh();
        $this->assertFalse($user->hasVerifiedTwoFactor());

        $code = (new Google2FA)->getCurrentOtp($user->two_factor_secret);

        $response = $this->actingAs($user)->postJson('/account/two-factor/confirm', ['code' => $code]);

        $response->assertOk();
        $response->assertJsonCount(8, 'recovery_codes');

        $user->refresh();
        $this->assertTrue($user->hasVerifiedTwoFactor());
    }

    #[Test]
    public function confirming_with_a_wrong_code_does_not_activate_it(): void
    {
        $user = $this->userWithDashboardAccess();

        $this->actingAs($user)->postJson('/account/two-factor/enable')->assertOk();

        $this->actingAs($user)
            ->postJson('/account/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $user->refresh();
        $this->assertFalse($user->hasVerifiedTwoFactor());
    }

    #[Test]
    public function login_requires_a_second_factor_when_it_is_enabled(): void
    {
        $user = $this->userWithConfirmedTwoFactor();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        // No debe iniciar sesion todavia: redirige al reto de 2FA.
        $response->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    #[Test]
    public function a_valid_totp_code_completes_the_login(): void
    {
        $user = $this->userWithConfirmedTwoFactor();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $code = (new Google2FA)->getCurrentOtp($user->two_factor_secret);

        $response = $this->post('/two-factor-challenge', ['code' => $code]);

        $response->assertRedirect('/monitoring/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_recovery_code_can_be_used_instead_of_a_totp_code(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $recoveryCode = $user->two_factor_recovery_codes[0];

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response = $this->post('/two-factor-challenge', ['code' => $recoveryCode]);

        $response->assertRedirect('/monitoring/dashboard');
        $this->assertAuthenticatedAs($user);

        // Un codigo de recuperacion es de un solo uso.
        $user->refresh();
        $this->assertNotContains($recoveryCode, $user->two_factor_recovery_codes);
    }

    #[Test]
    public function an_invalid_code_does_not_complete_the_login(): void
    {
        $user = $this->userWithConfirmedTwoFactor();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response = $this->post('/two-factor-challenge', ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    #[Test]
    public function disabling_two_factor_requires_the_current_password(): void
    {
        $user = $this->userWithConfirmedTwoFactor();

        $this->actingAs($user)
            ->deleteJson('/account/two-factor', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $user->refresh();
        $this->assertTrue($user->hasVerifiedTwoFactor());

        $this->actingAs($user)
            ->deleteJson('/account/two-factor', ['password' => 'password'])
            ->assertOk();

        $user->refresh();
        $this->assertFalse($user->hasVerifiedTwoFactor());
    }

    #[Test]
    public function the_security_page_only_shows_the_notification_preference_to_admins(): void
    {
        $viewer = $this->userWithDashboardAccess();

        $response = $this->actingAs($viewer)->get('/account/security');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('isAdmin', false));
    }

    #[Test]
    public function an_admin_can_opt_out_of_critical_incident_emails(): void
    {
        $admin = $this->userWithDashboardAccess()->fresh();
        Permission::findOrCreate('monitoring.manage_users', 'web');
        $admin->givePermissionTo('monitoring.manage_users');

        $this->assertTrue($admin->notify_on_critical_incidents);

        $response = $this->actingAs($admin)
            ->patchJson('/account/notification-preference', ['enabled' => false]);

        $response->assertOk();
        $this->assertFalse($admin->refresh()->notify_on_critical_incidents);
    }

    private function userWithDashboardAccess(): User
    {
        $user = User::factory()->create(['password' => 'password']);

        Permission::findOrCreate('monitoring.view_dashboard', 'web');
        $user->givePermissionTo('monitoring.view_dashboard');

        return $user;
    }

    private function userWithConfirmedTwoFactor(): User
    {
        $user = $this->userWithDashboardAccess();

        app(TwoFactorAuthenticationService::class)->generateSecret($user);
        $user->refresh();

        $code = (new Google2FA)->getCurrentOtp($user->two_factor_secret);
        app(TwoFactorAuthenticationService::class)->confirm($user, $code);

        return $user->refresh();
    }
}
