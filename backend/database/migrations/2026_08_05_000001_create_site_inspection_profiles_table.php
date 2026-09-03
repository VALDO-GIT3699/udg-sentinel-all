<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_inspection_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('scan_run_id')->nullable();
            $table->string('analysis_version', 40)->default('vertical-inspector-v1');

            $table->string('dns_status', 30)->default('pending');
            $table->text('dns_error')->nullable();

            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'pgsql') {
                $table->jsonb('dns_records')->nullable();
                $table->jsonb('redirect_chain')->nullable();
                $table->jsonb('ssl_payload')->nullable();
                $table->jsonb('security_headers_payload')->nullable();
                $table->jsonb('fingerprint_payload')->nullable();
                $table->jsonb('js_frameworks')->nullable();
                $table->jsonb('analysis_errors')->nullable();
            } else {
                $table->json('dns_records')->nullable();
                $table->json('redirect_chain')->nullable();
                $table->json('ssl_payload')->nullable();
                $table->json('security_headers_payload')->nullable();
                $table->json('fingerprint_payload')->nullable();
                $table->json('js_frameworks')->nullable();
                $table->json('analysis_errors')->nullable();
            }

            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedSmallInteger('https_status')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedInteger('ttfb_ms')->nullable();

            $table->string('ssl_status', 30)->default('pending');
            $table->text('ssl_error')->nullable();

            $table->string('security_headers_status', 30)->default('pending');
            $table->string('body_status', 30)->default('pending');
            $table->text('body_error')->nullable();
            $table->string('fingerprint_status', 30)->default('pending');

            $table->string('cms_name', 80)->default('No determinado');
            $table->string('cms_version', 80)->default('No determinado');
            $table->string('cms_confidence', 20)->default('low');
            $table->string('server_signature', 160)->default('No determinado');
            $table->string('runtime_name', 40)->default('No determinado');
            $table->string('runtime_version', 40)->default('No determinado');

            $table->unsignedSmallInteger('risk_score')->default(100);
            $table->string('risk_level', 20)->default('Crítico');
            $table->boolean('essential_checks_complete')->default(false);
            $table->timestampTz('inspected_at')->nullable();
            $table->timestamps();

            $table->unique('site_id');
            $table->index(['risk_level', 'essential_checks_complete']);
            $table->index('scan_run_id');
            $table->index('inspected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_inspection_profiles');
    }
};
