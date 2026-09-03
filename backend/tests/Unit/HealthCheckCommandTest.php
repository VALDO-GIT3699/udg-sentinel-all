<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docker-compose.prod.yml declara un healthcheck que corre
 * "php artisan health:check" pero ese comando nunca existió -el contenedor
 * "app" quedaba marcado "unhealthy" para siempre-.
 */
final class HealthCheckCommandTest extends TestCase
{
    #[Test]
    public function it_succeeds_when_the_database_and_redis_are_reachable(): void
    {
        $this->artisan('health:check')->assertSuccessful();
    }
}
