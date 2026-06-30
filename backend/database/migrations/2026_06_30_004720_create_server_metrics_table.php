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
        Schema::create('server_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->timestampTz('recorded_at');
            $table->decimal('cpu_usage_pct', 5, 2)->nullable();
            $table->decimal('ram_usage_pct', 5, 2)->nullable();
            $table->unsignedInteger('ram_used_mb')->nullable();
            $table->unsignedInteger('ram_total_mb')->nullable();
            $table->decimal('disk_usage_pct', 5, 2)->nullable();
            $table->decimal('disk_used_gb', 7, 2)->nullable();
            $table->decimal('disk_total_gb', 7, 2)->nullable();
            $table->decimal('load_avg_1', 6, 2)->nullable();
            $table->decimal('load_avg_5', 6, 2)->nullable();
            $table->decimal('load_avg_15', 6, 2)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['server_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
