<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\AssetClassificationRepositoryInterface;
use App\Models\AssetClassification;
use App\Models\Site;

final class EloquentAssetClassificationRepository implements AssetClassificationRepositoryInterface
{
    public function latestForSite(int $siteId): ?AssetClassification
    {
        return AssetClassification::query()
            ->where('site_id', $siteId)
            ->where('is_current', true)
            ->orderByDesc('classified_at')
            ->first();
    }

    public function createForSite(Site $site, array $data): AssetClassification
    {
        $this->markPreviousAsNotCurrent((int) $site->id);

        return AssetClassification::query()->create(array_merge([
            'site_id' => (int) $site->id,
            'source' => 'automatic',
            'asset_type' => 'unknown',
            'asset_role' => 'unknown',
            'confidence_pct' => 0,
            'classified_at' => now(),
            'is_current' => true,
        ], $data));
    }

    public function markPreviousAsNotCurrent(int $siteId): void
    {
        AssetClassification::query()
            ->where('site_id', $siteId)
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }
}
