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
        Schema::create('security_headers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->timestampTz('checked_at');
            $table->boolean('has_hsts')->default(false);
            $table->boolean('has_csp')->default(false);
            $table->boolean('has_x_frame_options')->default(false);
            $table->boolean('has_x_content_type')->default(false);
            $table->boolean('has_referrer_policy')->default(false);
            $table->boolean('has_permissions_policy')->default(false);
            $table->smallInteger('score_contribution')->default(0);
            $table->jsonb('raw_headers')->default('{}');
            $table->timestamps();

            $table->index(['site_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_headers');
    }
};
