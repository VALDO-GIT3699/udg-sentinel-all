<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\AssetClassification;
use App\Models\Site;

interface AssetClassificationRepositoryInterface
{
    public function latestForSite(int $siteId): ?AssetClassification;

    /**
     * @param array<string, mixed> $data
     */
    public function createForSite(Site $site, array $data): AssetClassification;

    public function markPreviousAsNotCurrent(int $siteId): void;
}
