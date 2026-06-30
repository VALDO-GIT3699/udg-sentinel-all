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
        Schema::create('broken_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('found_on', 2048)->nullable();
            $table->unsignedSmallInteger('http_code')->nullable();
            $table->timestampTz('first_detected_at');
            $table->timestampTz('last_checked_at')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'is_resolved']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broken_links');
    }
};
