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
        Schema::create('site_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('responsible_name')->nullable();
            $table->string('responsible_email')->nullable();
            $table->string('color', 7)->default('#3B82F6');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_groups');
    }
};
