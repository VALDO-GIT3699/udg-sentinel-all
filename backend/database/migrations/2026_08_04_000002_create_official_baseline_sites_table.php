<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_baseline_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('official_baseline_snapshots')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('normalized_domain', 255)->nullable();
            $table->string('classification', 50)->nullable();
            $table->string('entity')->nullable();
            $table->string('site_name')->nullable();
            $table->string('domain', 500)->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('cms_label', 120)->nullable();
            $table->string('server_ip', 80)->nullable();
            $table->string('certificate_label', 120)->nullable();
            $table->string('project_status', 120)->nullable();
            $table->text('comments')->nullable();
            $table->string('ticket_number', 80)->nullable();

            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'pgsql') {
                $table->jsonb('raw_payload')->nullable();
            } else {
                $table->json('raw_payload')->nullable();
            }

            $table->timestamps();

            $table->index(['snapshot_id', 'row_number']);
            $table->index(['snapshot_id', 'normalized_domain']);
            $table->index(['normalized_domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_baseline_sites');
    }
};
