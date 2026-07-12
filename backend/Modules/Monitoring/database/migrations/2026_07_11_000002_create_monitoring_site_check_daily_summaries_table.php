<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_site_check_daily_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('summary_date');
            $table->unsignedInteger('total_checks');
            $table->unsignedInteger('up_checks');
            $table->unsignedInteger('down_checks');
            $table->unsignedInteger('degraded_checks');
            $table->unsignedInteger('timeout_checks');
            $table->unsignedInteger('avg_response_time_ms')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'summary_date'], 'uq_monitoring_site_check_daily');
            $table->index(['summary_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_site_check_daily_summaries');
    }
};