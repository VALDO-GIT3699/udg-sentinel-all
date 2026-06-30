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
        Schema::create('traffic_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->timestampTz('recorded_at');
            $table->unsignedInteger('requests_per_min')->nullable();
            $table->unsignedInteger('unique_visitors')->nullable();
            $table->unsignedBigInteger('bandwidth_bytes')->nullable();
            $table->decimal('error_rate_pct', 5, 2)->nullable();
            $table->unsignedInteger('avg_response_time_ms')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['site_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('traffic_metrics');
    }
};
