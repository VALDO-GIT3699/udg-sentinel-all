<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Guarda el timestamp del ultimo codigo TOTP aceptado, para que
            // Google2FA::verifyKeyNewer() rechace reusar el mismo codigo dos
            // veces dentro de su ventana de validez (proteccion anti-replay).
            $table->unsignedInteger('two_factor_last_verified_timestamp')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_last_verified_timestamp');
        });
    }
};
