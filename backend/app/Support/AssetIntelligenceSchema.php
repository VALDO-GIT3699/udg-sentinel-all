<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class AssetIntelligenceSchema
{
    /**
     * @var array<string, bool>
     */
    private array $memo = [];

    public function isReady(): bool
    {
        if (isset($this->memo['is_ready'])) {
            return $this->memo['is_ready'];
        }

        return $this->memo['is_ready'] = $this->hasSitesColumns([
            'asset_type',
            'asset_role',
            'asset_confidence_pct',
            'asset_classification_source',
            'asset_last_classified_at',
            'asset_classification_locked_at',
        ]) && $this->hasClassificationsTable();
    }

    public function hasClassificationsTable(): bool
    {
        if (isset($this->memo['has_classifications_table'])) {
            return $this->memo['has_classifications_table'];
        }

        try {
            return $this->memo['has_classifications_table'] = Schema::hasTable('asset_classifications');
        } catch (\Throwable) {
            return $this->memo['has_classifications_table'] = false;
        }
    }

    /**
     * @param array<int, string> $columns
     */
    public function hasSitesColumns(array $columns): bool
    {
        foreach ($columns as $column) {
            if (! $this->hasSiteColumn($column)) {
                return false;
            }
        }

        return true;
    }

    public function hasSiteColumn(string $column): bool
    {
        $key = 'sites.' . $column;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        try {
            return $this->memo[$key] = Schema::hasColumn('sites', $column);
        } catch (\Throwable) {
            return $this->memo[$key] = false;
        }
    }
}
