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
        Schema::create('site_technologies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('technology_id')->constrained('technologies')->cascadeOnDelete();
            $table->string('version', 50)->nullable();
            $table->unsignedSmallInteger('confidence_pct')->default(100);
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('detected_at');
            $table->string('detection_method', 50)->default('automatic');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->index(['site_id', 'technology_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_technologies');
    }
};
