<?php

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
        Schema::table('site_checks', function (Blueprint $table): void {
            $table->index(['site_id', 'checked_at', 'status'], 'idx_site_checks_site_checked_status');
        });

        Schema::table('alerts', function (Blueprint $table): void {
            $table->index(['site_id', 'status', 'created_at'], 'idx_alerts_site_status_created');
        });

        Schema::table('ssl_certificates', function (Blueprint $table): void {
            $table->index(['site_id', 'last_checked_at'], 'idx_ssl_site_last_checked');
        });

        Schema::table('site_technologies', function (Blueprint $table): void {
            $table->index(['site_id', 'detected_at'], 'idx_site_technologies_site_detected');
        });

        Schema::table('security_scores', function (Blueprint $table): void {
            $table->index(['site_id', 'calculated_at', 'score'], 'idx_security_scores_site_calculated_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_checks', function (Blueprint $table): void {
            $table->dropIndex('idx_site_checks_site_checked_status');
        });

        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropIndex('idx_alerts_site_status_created');
        });

        Schema::table('ssl_certificates', function (Blueprint $table): void {
            $table->dropIndex('idx_ssl_site_last_checked');
        });

        Schema::table('site_technologies', function (Blueprint $table): void {
            $table->dropIndex('idx_site_technologies_site_detected');
        });

        Schema::table('security_scores', function (Blueprint $table): void {
            $table->dropIndex('idx_security_scores_site_calculated_score');
        });
    }
};
