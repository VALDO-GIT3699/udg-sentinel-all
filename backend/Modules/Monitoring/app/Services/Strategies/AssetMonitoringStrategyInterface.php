<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services\Strategies;

use App\Models\Site;

interface AssetMonitoringStrategyInterface
{
    public function key(): string;

    public function supports(string $assetType): bool;

    public function dispatch(Site $site): void;
}
