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
        Schema::create('site_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')
                  ->constrained('sites')
                  ->cascadeOnDelete();
            $table->timestampTz('checked_at');
            $table->string('status', 20)->comment('up/down/degraded/timeout');
            $table->unsignedSmallInteger('http_code')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedInteger('response_size_bytes')->nullable();
            $table->ipAddress('ip_resolved')->nullable();
            $table->string('redirect_url', 500)->nullable();
            $table->text('error_message')->nullable();
            $table->string('checked_from', 100)->default('sentinel');
            $table->timestampTz('created_at')->nullable();

            // Índice principal para queries del dashboard
            $table->index(['site_id', 'checked_at']);
            $table->index(['status', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_checks');
    }
};
