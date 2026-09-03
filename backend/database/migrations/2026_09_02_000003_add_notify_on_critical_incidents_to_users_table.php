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
            // Preferencia personal: si esta cuenta (cuando es administrador)
            // quiere recibir el correo individual de incidente critico que
            // manda SiteDownNotification. Default true para no cambiar el
            // comportamiento de nadie que ya estuviera recibiendolos.
            $table->boolean('notify_on_critical_incidents')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notify_on_critical_incidents');
        });
    }
};
