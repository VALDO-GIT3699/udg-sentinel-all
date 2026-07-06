<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services\Strategies;

use App\Models\Site;

final class AssetMonitoringStrategyRouter
{
    /**
     * @var array<int, AssetMonitoringStrategyInterface>
     */
    private array $strategies;

    /**
     * @param iterable<int, AssetMonitoringStrategyInterface> $strategies
     */
    public function __construct(iterable $strategies)
    {
        $this->strategies = is_array($strategies) ? $strategies : iterator_to_array($strategies);
    }

    public function dispatch(Site $site, ?string $assetType = null): string
    {
        $resolvedType = trim((string) ($assetType ?? $site->asset_type ?? 'unknown'));

        foreach ($this->strategies as $strategy) {
            if (! $strategy->supports($resolvedType)) {
                continue;
            }

            $strategy->dispatch($site);

            return $strategy->key();
        }

        $fallback = $this->strategies[0] ?? null;

        if ($fallback instanceof AssetMonitoringStrategyInterface) {
            $fallback->dispatch($site);

            return $fallback->key();
        }

        return 'none';
    }
}
