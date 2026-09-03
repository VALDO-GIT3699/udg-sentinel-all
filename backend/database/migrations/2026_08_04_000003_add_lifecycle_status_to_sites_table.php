<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            if (! Schema::hasColumn('sites', 'lifecycle_status')) {
                $table->string('lifecycle_status', 80)->default('N/A')->after('current_status');
                $table->index('lifecycle_status');
            }

            if (! Schema::hasColumn('sites', 'elimination_ticket')) {
                $table->string('elimination_ticket', 120)->nullable()->after('lifecycle_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            if (Schema::hasColumn('sites', 'elimination_ticket')) {
                $table->dropColumn('elimination_ticket');
            }

            if (Schema::hasColumn('sites', 'lifecycle_status')) {
                $table->dropIndex(['lifecycle_status']);
                $table->dropColumn('lifecycle_status');
            }
        });
    }
};
