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
        Schema::create('site_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('event_type', 100)
                  ->comment('ssl_renewed/site_down/site_up/php_updated/cms_updated/vuln_found/vuln_resolved/scan_completed/manual_note');
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->string('severity', 20)->default('info')->comment('info/warning/error/critical');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('occurred_at');
            $table->foreignId('created_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->index(['site_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_events');
    }
};
