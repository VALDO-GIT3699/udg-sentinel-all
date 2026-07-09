<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('monitoring_mass_scan_runs')) {
            return;
        }

        Schema::create('monitoring_mass_scan_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id')->unique();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger_mode', 20)->default('manual');
            $table->string('status', 40)->default('running');
            $table->unsignedInteger('total_sites')->default(0);
            $table->unsignedInteger('total_tasks')->default(0);
            $table->unsignedInteger('completed_tasks')->default(0);
            $table->unsignedInteger('failed_tasks')->default(0);
            $table->timestampTz('started_at');
            $table->timestampTz('last_progress_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at'], 'idx_monitoring_mass_scan_runs_status_started');
            $table->index(['initiated_by_user_id', 'started_at'], 'idx_monitoring_mass_scan_runs_user_started');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_mass_scan_runs');
    }
};
