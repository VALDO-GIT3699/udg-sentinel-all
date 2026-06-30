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
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 50)->comment('daily/weekly/monthly/custom');
            $table->string('scope', 20)->comment('global/group/site/server');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('generated_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('file_path', 500)->nullable();
            $table->string('status', 20)->default('pending')->comment('pending/generating/ready/failed');
            $table->timestamps();

            $table->index(['scope', 'scope_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
