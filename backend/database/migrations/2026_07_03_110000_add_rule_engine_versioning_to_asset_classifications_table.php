<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_classifications')) {
            return;
        }

        Schema::table('asset_classifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('asset_classifications', 'rule_engine_version')) {
                $table->string('rule_engine_version', 32)->nullable()->after('classifier_version');
            }

            if (! Schema::hasColumn('asset_classifications', 'result_hash')) {
                $table->string('result_hash', 64)->nullable()->after('rule_engine_version');
            }

            if (! Schema::hasColumn('asset_classifications', 'rules_used')) {
                $driver = Schema::getConnection()->getDriverName();
                if ($driver === 'pgsql') {
                    $table->jsonb('rules_used')->nullable()->after('result_hash');
                } else {
                    $table->json('rules_used')->nullable()->after('result_hash');
                }
            }

            if (! Schema::hasColumn('asset_classifications', 'observations')) {
                $driver = Schema::getConnection()->getDriverName();
                if ($driver === 'pgsql') {
                    $table->jsonb('observations')->nullable()->after('rules_used');
                } else {
                    $table->json('observations')->nullable()->after('rules_used');
                }
            }

            if (! Schema::hasColumn('asset_classifications', 'recommendations')) {
                $driver = Schema::getConnection()->getDriverName();
                if ($driver === 'pgsql') {
                    $table->jsonb('recommendations')->nullable()->after('observations');
                } else {
                    $table->json('recommendations')->nullable()->after('observations');
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_classifications')) {
            return;
        }

        Schema::table('asset_classifications', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('asset_classifications', 'rule_engine_version') ? 'rule_engine_version' : null,
                Schema::hasColumn('asset_classifications', 'result_hash') ? 'result_hash' : null,
                Schema::hasColumn('asset_classifications', 'rules_used') ? 'rules_used' : null,
                Schema::hasColumn('asset_classifications', 'observations') ? 'observations' : null,
                Schema::hasColumn('asset_classifications', 'recommendations') ? 'recommendations' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
