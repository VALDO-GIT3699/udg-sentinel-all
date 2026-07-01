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
        Schema::table('sites', function (Blueprint $table): void {
            $table->index(
                ['is_active', 'is_monitored', 'current_status', 'site_group_id', 'priority'],
                'idx_sites_dashboard_filters'
            );
            $table->index(
                ['is_active', 'is_monitored', 'last_checked_at', 'priority'],
                'idx_sites_check_dispatch'
            );
        });

        Schema::table('site_checks', function (Blueprint $table): void {
            $table->index(
                ['checked_at', 'site_id', 'status'],
                'idx_site_checks_recent_timeline'
            );
        });

        Schema::table('alerts', function (Blueprint $table): void {
            $table->index(
                ['status', 'severity', 'triggered_at', 'site_id'],
                'idx_alerts_open_feed'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropIndex('idx_sites_dashboard_filters');
            $table->dropIndex('idx_sites_check_dispatch');
        });

        Schema::table('site_checks', function (Blueprint $table): void {
            $table->dropIndex('idx_site_checks_recent_timeline');
        });

        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropIndex('idx_alerts_open_feed');
        });
    }
};
