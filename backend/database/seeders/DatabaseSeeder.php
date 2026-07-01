<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            MonitoringPermissionSeeder::class,
        ]);

        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->make(['name' => 'Test User', 'email' => 'test@example.com'])->toArray(),
        );

        $monitoringAdmin = User::query()->firstOrCreate(
            ['email' => 'udgmonitoreo26B'],
            [
                'name' => 'udgmonitoreo26B',
                'password' => 'sentinela2607',
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );

        if (! $monitoringAdmin->hasRole('monitoring-admin') && Role::query()->where('name', 'monitoring-admin')->exists()) {
            $monitoringAdmin->assignRole('monitoring-admin');
        }
    }
}
