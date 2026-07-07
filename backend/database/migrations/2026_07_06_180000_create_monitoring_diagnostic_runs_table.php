<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('monitoring_diagnostic_runs')) {
            return;
        }

        Schema::create('monitoring_diagnostic_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->string('summary', 1000);
            $table->text('reason');

            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'pgsql') {
                $table->jsonb('steps')->nullable();
                $table->jsonb('issues')->nullable();
                $table->jsonb('queue_before')->nullable();
                $table->jsonb('queue_after')->nullable();
            } else {
                $table->json('steps')->nullable();
                $table->json('issues')->nullable();
                $table->json('queue_before')->nullable();
                $table->json('queue_after')->nullable();
            }

            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_monitoring_diagnostic_runs_status_created');
            $table->index(['user_id', 'created_at'], 'idx_monitoring_diagnostic_runs_user_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_diagnostic_runs');
    }
};
