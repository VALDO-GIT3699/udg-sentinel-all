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
        Schema::create('report_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('report_type', 50);
            $table->string('scope', 20);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('frequency', 20)->comment('daily/weekly/monthly');
            $table->jsonb('delivery_channels')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
