<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Modules\Monitoring\Support\EnsureLocalMonitoringUser;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            MonitoringPermissionSeeder::class,
            OfficialBaselineSeeder::class,
        ]);

        $this->seedOfficialInventory();

        // User::factory(10)->create();

        // Cuenta de conveniencia para desarrollo/tests (password conocida del
        // UserFactory). NUNCA debe crearse fuera de local/testing: un seeder
        // corrido en produccion (p. ej. "migrate --seed --force" en un primer
        // deploy) no debe dejar una cuenta admin con contraseña predecible.
        if (app()->environment(['local', 'testing'])) {
            User::firstOrCreate(
                ['email' => 'test@example.com'],
                User::factory()->make(['name' => 'Test User', 'email' => 'test@example.com'])->toArray(),
            );
        }

        app(EnsureLocalMonitoringUser::class)->handle();
    }

    private function seedOfficialInventory(): void
    {
        try {
            Artisan::call('monitoring:sync-official-inventory', ['--replace' => true]);
        } catch (\Throwable $exception) {
            $this->command?->warn('No se pudo sincronizar el inventario oficial: '.$exception->getMessage());
        }
    }
}
