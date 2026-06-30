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
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()
                  ->constrained('sites')
                  ->nullOnDelete();
            $table->foreignId('alert_rule_id')->nullable()
                  ->constrained('alert_rules')
                  ->nullOnDelete();
            $table->string('title', 500);
            $table->text('message')->nullable();
            $table->string('severity', 20)->comment('critical/high/medium/low');
            $table->string('status', 20)->default('open')->comment('open/acknowledged/resolved');
            $table->timestampTz('triggered_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->jsonb('context')->default('{}');
            $table->timestamps();

            $table->index(['site_id', 'status', 'triggered_at']);
            $table->index(['status', 'triggered_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
