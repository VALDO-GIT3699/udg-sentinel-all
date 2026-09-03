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
        // notesTimeline (SiteDetailController) filtra site_id + event_type y
        // ordena por occurred_at juntos; los indices existentes cubren esos
        // pares por separado, no combinados.
        Schema::table('site_events', function (Blueprint $table): void {
            $table->index(['site_id', 'event_type', 'occurred_at'], 'site_events_site_type_occurred_idx');
        });

        // El registro de auditoria (Modules/Audit) ordena por created_at en
        // todas las vistas y ademas filtra por causer_id cuando se elige un
        // usuario — la tabla no tenia ningun indice sobre created_at.
        Schema::connection(config('activitylog.database_connection'))->table(
            config('activitylog.table_name'),
            function (Blueprint $table): void {
                $table->index('created_at', 'activity_log_created_at_idx');
                $table->index(['causer_id', 'created_at'], 'activity_log_causer_created_idx');
            },
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_events', function (Blueprint $table): void {
            $table->dropIndex('site_events_site_type_occurred_idx');
        });

        Schema::connection(config('activitylog.database_connection'))->table(
            config('activitylog.table_name'),
            function (Blueprint $table): void {
                $table->dropIndex('activity_log_created_at_idx');
                $table->dropIndex('activity_log_causer_created_idx');
            },
        );
    }
};
