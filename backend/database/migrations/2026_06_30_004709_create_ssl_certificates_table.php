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
        Schema::create('ssl_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')
                  ->constrained('sites')
                  ->cascadeOnDelete();
            $table->string('common_name')->nullable();
            $table->string('issuer')->nullable();
            $table->string('issuer_org')->nullable();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->integer('days_remaining')->nullable();
            $table->boolean('is_valid')->default(false);
            $table->boolean('is_expired')->default(false);
            $table->string('algorithm', 50)->nullable();
            $table->unsignedSmallInteger('key_size')->nullable();
            $table->string('signature_alg', 100)->nullable();
            $table->json('san_domains')->default('[]');
            $table->string('fingerprint_sha256', 95)->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'valid_until']);
            $table->index(['days_remaining']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ssl_certificates');
    }
};

