<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class LocalAutologinFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'activitylog.enabled' => false,
            'activitylog.default_log_name' => 'testing',
        ]);

        $this->app['env'] = 'local';
    }

    #[Test]
    public function local_autologin_creates_the_monitoring_user_on_a_clean_database_and_redirects_successfully(): void
    {
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Permission::query()->count());
        $this->assertSame(0, Role::query()->count());

        $response = $this->get(route('monitoring.local-login'));

        $response->assertRedirect(route('monitoring.dashboard'));
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'udgmonitoreo26B')->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->isActive());
        $this->assertTrue($user->hasRole('monitoring-admin'));
        $this->assertTrue($user->can('monitoring.view_dashboard'));
        $this->assertTrue($user->can('monitoring.view_site_detail'));

        $dashboardResponse = $this->get(route('monitoring.dashboard'), [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $dashboardResponse->assertOk();
    }
}
