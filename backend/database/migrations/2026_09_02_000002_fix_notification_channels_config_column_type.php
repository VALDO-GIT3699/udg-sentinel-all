<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // El modelo NotificationChannel cifra "config" antes de guardarlo
        // (Crypt::encryptString) — el resultado es texto opaco, no JSON
        // valido. La columna se creo como "json", que Postgres valida en
        // cada insert/update: guardar el string cifrado ahi truena siempre
        // con "invalid input syntax for type json". Nunca se detecto porque
        // la tabla seguia en cero filas. "text" es el tipo correcto para un
        // valor cifrado opaco (mismo patron que two_factor_secret en users).
        // SQL crudo (no Schema::change(), que requiere doctrine/dbal) porque
        // sqlite -usado en tests- no soporta ALTER COLUMN TYPE de todas
        // formas y ya trata "json" como texto sin validar.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notification_channels ALTER COLUMN config TYPE text');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notification_channels ALTER COLUMN config TYPE json USING config::json');
        }
    }
};
