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
        Schema::create('access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->string('action', 50)->comment('login/logout/failed_login');
            $table->ipAddress('ip_address');
            $table->string('user_agent', 500)->nullable();
            $table->string('endpoint', 500)->nullable();
            $table->string('request_method', 10)->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};
