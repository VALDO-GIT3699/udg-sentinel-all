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
        Schema::create('scan_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('scan_type', 50)->comment('security/headers/links/technology');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending/running/completed/failed');
            $table->unsignedInteger('findings_count')->default(0);
            $table->unsignedSmallInteger('critical_count')->default(0);
            $table->unsignedSmallInteger('high_count')->default(0);
            $table->unsignedSmallInteger('medium_count')->default(0);
            $table->unsignedSmallInteger('low_count')->default(0);
            $table->json('raw_output')->default('{}');
            $table->timestamps();

            $table->index(['site_id', 'scan_type', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scan_results');
    }
};

