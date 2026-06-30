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
        Schema::create('security_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedSmallInteger('score');
            $table->string('level', 20)->comment('critical/low/medium/good/excellent');
            $table->timestampTz('calculated_at');
            $table->jsonb('breakdown')->default('{}');
            $table->jsonb('recommendations')->default('[]');
            $table->timestampTz('created_at')->nullable();

            $table->index(['site_id', 'calculated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_scores');
    }
};
