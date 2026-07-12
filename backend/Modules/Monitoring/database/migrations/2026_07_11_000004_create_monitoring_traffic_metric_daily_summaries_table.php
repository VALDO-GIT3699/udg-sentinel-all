<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_traffic_metric_daily_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('summary_date');
            $table->unsignedInteger('total_requests')->nullable();
            $table->unsignedInteger('unique_visitors')->nullable();
            $table->unsignedBigInteger('bandwidth_bytes')->nullable();
            $table->decimal('avg_error_rate_pct', 5, 2)->nullable();
            $table->unsignedInteger('avg_response_time_ms')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'summary_date'], 'uq_monitoring_traffic_metric_daily');
            $table->index(['summary_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_traffic_metric_daily_summaries');
    }
};