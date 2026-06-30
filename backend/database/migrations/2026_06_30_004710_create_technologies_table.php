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
        Schema::create('technologies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->string('category', 50)->comment('cms/framework/language/database/server/cdn/other');
            $table->string('vendor')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technologies');
    }
};
