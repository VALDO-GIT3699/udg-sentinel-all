<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * docker-compose.prod.yml ya declaraba un healthcheck que corria
 * "php artisan health:check", pero ese comando nunca se escribió -Docker
 * marcaba el contenedor "app" como no saludable para siempre-. Verifica que
 * la base de datos y Redis respondan; exit 0 si ambos estan bien, exit 1 si
 * no (lo que Docker usa para decidir "healthy"/"unhealthy").
 */
final class HealthCheckCommand extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Verifica que la base de datos y Redis respondan; usado como healthcheck de Docker.';

    public function handle(): int
    {
        $healthy = true;

        try {
            DB::connection()->getPdo();
            $this->info('[OK] Base de datos');
        } catch (\Throwable $exception) {
            $this->error('[FALLA] Base de datos: '.$exception->getMessage());
            $healthy = false;
        }

        try {
            Redis::connection()->ping();
            $this->info('[OK] Redis');
        } catch (\Throwable $exception) {
            $this->error('[FALLA] Redis: '.$exception->getMessage());
            $healthy = false;
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
