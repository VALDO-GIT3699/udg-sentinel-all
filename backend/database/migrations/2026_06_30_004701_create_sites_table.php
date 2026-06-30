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
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_group_id')
                  ->constrained('site_groups')
                  ->restrictOnDelete();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->string('domain');
            $table->string('url', 500);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_monitored')->default(true);
            $table->unsignedTinyInteger('priority')->default(2)->comment('1=crítico, 2=normal, 3=bajo');
            $table->string('current_status', 20)->default('unknown')->comment('up/down/degraded/unknown');
            $table->unsignedSmallInteger('current_score')->default(100);
            $table->string('current_score_level', 20)->default('unknown');
            $table->timestampTz('last_checked_at')->nullable();
            $table->unsignedSmallInteger('check_interval_min')->default(5);
            $table->text('notes')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_group_id']);
            $table->index(['domain']);
            $table->index(['current_status', 'current_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
