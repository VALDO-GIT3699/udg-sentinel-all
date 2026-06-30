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
        Schema::create('drupal_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cms_detail_id')
                  ->constrained('cms_details')
                  ->cascadeOnDelete();
            $table->string('module_name');
            $table->string('module_version', 50)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_core')->default(false);
            $table->string('project_url', 500)->nullable();
            $table->boolean('has_update_available')->default(false);
            $table->boolean('security_update_available')->default(false);
            $table->timestamps();

            $table->index(['cms_detail_id', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drupal_modules');
    }
};
