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
        Schema::create('cms_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->string('cms_type', 50)->nullable()->comment('drupal/wordpress/laravel/joomla/other');
            $table->string('cms_version', 50)->nullable();
            $table->string('db_type', 50)->nullable();
            $table->string('db_version', 50)->nullable();
            $table->string('php_version', 20)->nullable();
            $table->boolean('php_is_vulnerable')->default(false);
            $table->string('server_software')->nullable();
            $table->string('theme_name')->nullable();
            $table->string('theme_version', 50)->nullable();
            $table->unsignedSmallInteger('modules_count')->default(0);
            $table->boolean('has_updates')->default(false);
            $table->boolean('has_security_updates')->default(false);
            $table->timestampTz('last_scanned_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cms_details');
    }
};
