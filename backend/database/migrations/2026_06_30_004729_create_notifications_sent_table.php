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
        Schema::create('notifications_sent', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();
            $table->foreignId('channel_id')
                  ->constrained('notification_channels')
                  ->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->comment('pending/sent/failed');
            $table->timestampTz('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['alert_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications_sent');
    }
};
