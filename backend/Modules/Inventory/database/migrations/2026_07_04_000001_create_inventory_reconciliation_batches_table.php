<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_reconciliation_batches')) {
            return;
        }

        Schema::create('inventory_reconciliation_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_name');
            $table->string('source_type', 20);
            $table->string('source_hash', 64)->unique();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('exact_matches')->default(0);
            $table->unsignedInteger('probable_matches')->default(0);
            $table->unsignedInteger('new_rows')->default(0);
            $table->unsignedInteger('obsolete_sites')->default(0);
            $table->unsignedInteger('conflicts')->default(0);

            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'pgsql') {
                $table->jsonb('summary')->nullable();
            } else {
                $table->json('summary')->nullable();
            }

            $table->timestampTz('analyzed_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['uploaded_by']);
            $table->index(['analyzed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reconciliation_batches');
    }
};
