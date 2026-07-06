<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            if (! Schema::hasColumn('sites', 'asset_type')) {
                $table->string('asset_type', 60)->default('unknown')->after('url');
            }

            if (! Schema::hasColumn('sites', 'asset_role')) {
                $table->string('asset_role', 80)->default('unknown')->after('asset_type');
            }

            if (! Schema::hasColumn('sites', 'asset_confidence_pct')) {
                $table->unsignedSmallInteger('asset_confidence_pct')->default(0)->after('asset_role');
            }

            if (! Schema::hasColumn('sites', 'asset_classification_source')) {
                $table->string('asset_classification_source', 20)->default('none')->after('asset_confidence_pct');
            }

            if (! Schema::hasColumn('sites', 'asset_classifier_version')) {
                $table->string('asset_classifier_version', 32)->nullable()->after('asset_classification_source');
            }

            if (! Schema::hasColumn('sites', 'asset_last_classified_at')) {
                $table->timestampTz('asset_last_classified_at')->nullable()->after('asset_classifier_version');
            }

            if (! Schema::hasColumn('sites', 'asset_classification_locked_at')) {
                $table->timestampTz('asset_classification_locked_at')->nullable()->after('asset_last_classified_at');
            }

            if (! Schema::hasColumn('sites', 'asset_classification_evidence')) {
                $driver = Schema::getConnection()->getDriverName();

                if ($driver === 'pgsql') {
                    $table->jsonb('asset_classification_evidence')->nullable()->after('asset_classification_locked_at');
                } else {
                    $table->json('asset_classification_evidence')->nullable()->after('asset_classification_locked_at');
                }
            }

        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS sites_asset_type_index ON sites (asset_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS sites_asset_role_index ON sites (asset_role)');
            DB::statement('CREATE INDEX IF NOT EXISTS sites_asset_classification_source_index ON sites (asset_classification_source)');
            DB::statement('CREATE INDEX IF NOT EXISTS sites_asset_last_classified_at_index ON sites (asset_last_classified_at)');
        } else {
            try {
                Schema::table('sites', function (Blueprint $table): void {
                    $table->index(['asset_type']);
                    $table->index(['asset_role']);
                    $table->index(['asset_classification_source']);
                    $table->index(['asset_last_classified_at']);
                });
            } catch (\Throwable) {
                // Compatibilidad con re-ejecuciones parciales en drivers sin IF NOT EXISTS.
            }
        }

        if (Schema::hasTable('asset_classifications')) {
            return;
        }

        Schema::create('asset_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('source', 20)->default('automatic');
            $table->string('asset_type', 60)->default('unknown');
            $table->string('asset_role', 80)->default('unknown');
            $table->unsignedSmallInteger('confidence_pct')->default(0);

            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'pgsql') {
                $table->jsonb('evidence')->nullable();
                $table->jsonb('scores')->nullable();
            } else {
                $table->json('evidence')->nullable();
                $table->json('scores')->nullable();
            }

            $table->string('classifier_version', 32)->nullable();
            $table->boolean('is_current')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestampTz('classified_at');
            $table->timestamps();

            $table->index(['site_id', 'classified_at']);
            $table->index(['site_id', 'is_current']);
            $table->index(['source']);
            $table->index(['asset_type']);
            $table->index(['asset_role']);
            $table->index(['confidence_pct']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_classifications');

        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            try {
                $table->dropIndex(['asset_type']);
                $table->dropIndex(['asset_role']);
                $table->dropIndex(['asset_classification_source']);
                $table->dropIndex(['asset_last_classified_at']);
            } catch (\Throwable) {
                // Compatibilidad con estados parciales.
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('sites', 'asset_type') ? 'asset_type' : null,
                Schema::hasColumn('sites', 'asset_role') ? 'asset_role' : null,
                Schema::hasColumn('sites', 'asset_confidence_pct') ? 'asset_confidence_pct' : null,
                Schema::hasColumn('sites', 'asset_classification_source') ? 'asset_classification_source' : null,
                Schema::hasColumn('sites', 'asset_classifier_version') ? 'asset_classifier_version' : null,
                Schema::hasColumn('sites', 'asset_last_classified_at') ? 'asset_last_classified_at' : null,
                Schema::hasColumn('sites', 'asset_classification_locked_at') ? 'asset_classification_locked_at' : null,
                Schema::hasColumn('sites', 'asset_classification_evidence') ? 'asset_classification_evidence' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
