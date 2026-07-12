<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_server_metric_daily_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->date('summary_date');
            $table->decimal('avg_cpu_usage_pct', 5, 2)->nullable();
            $table->decimal('avg_ram_usage_pct', 5, 2)->nullable();
            $table->decimal('avg_disk_usage_pct', 5, 2)->nullable();
            $table->decimal('avg_load_avg_1', 6, 2)->nullable();
            $table->decimal('avg_load_avg_5', 6, 2)->nullable();
            $table->decimal('avg_load_avg_15', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'summary_date'], 'uq_monitoring_server_metric_daily');
            $table->index(['summary_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_server_metric_daily_summaries');
    }
};