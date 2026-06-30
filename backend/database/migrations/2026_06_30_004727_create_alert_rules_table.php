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
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('metric_type', 100)->comment('uptime/ssl_expiry/response_time/score/vulnerability');
            $table->string('condition_operator', 10)->comment('lt/lte/gt/gte/eq/neq');
            $table->string('condition_value', 100);
            $table->string('severity', 20)->comment('critical/high/medium/low');
            $table->boolean('is_active')->default(true);
            $table->string('applies_to', 20)->default('all')->comment('all/group/site');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedInteger('cooldown_minutes')->default(60);
            $table->jsonb('channel_ids')->default('[]');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
