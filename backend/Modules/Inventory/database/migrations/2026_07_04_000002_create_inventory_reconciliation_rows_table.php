<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_reconciliation_rows')) {
            return;
        }

        Schema::create('inventory_reconciliation_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('inventory_reconciliation_batches')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('match_kind', 20)->default('new');
            $table->unsignedSmallInteger('match_score')->default(0);
            $table->string('normalized_domain', 255)->nullable();
            $table->string('normalized_name', 255)->nullable();
            $table->string('normalized_cms', 120)->nullable();
            $table->string('normalized_ip', 45)->nullable();
            $table->boolean('source_active')->default(false);
            $table->boolean('needs_review')->default(true);

            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'pgsql') {
                $table->jsonb('source_payload');
                $table->jsonb('evidence')->nullable();
                $table->jsonb('proposed_changes')->nullable();
                $table->jsonb('notes')->nullable();
            } else {
                $table->json('source_payload');
                $table->json('evidence')->nullable();
                $table->json('proposed_changes')->nullable();
                $table->json('notes')->nullable();
            }

            $table->timestampTz('matched_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'row_number']);
            $table->index(['batch_id', 'match_kind']);
            $table->index(['site_id']);
            $table->index(['normalized_domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reconciliation_rows');
    }
};
