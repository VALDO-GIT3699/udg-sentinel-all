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
        Schema::create('servers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('hostname')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('os', 100)->nullable();
            $table->string('provider', 100)->nullable();
            $table->string('location', 100)->nullable();
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_user', 100)->nullable();
            $table->boolean('is_accessible')->default(false);
            $table->unsignedSmallInteger('cpu_cores')->nullable();
            $table->decimal('ram_gb', 5, 2)->nullable();
            $table->decimal('disk_gb', 7, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
