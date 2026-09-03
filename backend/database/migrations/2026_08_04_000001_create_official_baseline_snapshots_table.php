<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_baseline_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('source_name');
            $table->string('source_path');
            $table->string('source_type', 20);
            $table->string('source_hash', 64);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_current')->default(true);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('unique_domains')->default(0);
            $table->text('notes')->nullable();
            $table->timestampTz('imported_at')->nullable();
            $table->timestamps();

            $table->index(['is_current', 'imported_at']);
            $table->index(['source_hash']);
            $table->index(['imported_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_baseline_snapshots');
    }
};
