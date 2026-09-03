<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_inspection_profiles', function (Blueprint $table): void {
            $table->timestampTz('scan_interrupted_at')->nullable()->after('inspected_at');
        });
    }

    public function down(): void
    {
        Schema::table('site_inspection_profiles', function (Blueprint $table): void {
            $table->dropColumn('scan_interrupted_at');
        });
    }
};
