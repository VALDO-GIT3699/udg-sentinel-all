<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Site;
use Modules\Monitoring\Services\Strategies\AssetMonitoringStrategyInterface;
use Modules\Monitoring\Services\Strategies\AssetMonitoringStrategyRouter;
use Tests\TestCase;

final class TestApiStrategy implements AssetMonitoringStrategyInterface
{
    /**
     * @param array<int, string> $dispatched
     */
    public function __construct(private array &$dispatched)
    {
    }

    public function key(): string
    {
        return 'api';
    }

    public function supports(string $assetType): bool
    {
        return $assetType === 'rest_api';
    }

    public function dispatch(Site $site): void
    {
        $this->dispatched[] = 'api:' . $site->id;
    }
}

final class TestFallbackStrategy implements AssetMonitoringStrategyInterface
{
    /**
     * @param array<int, string> $dispatched
     */
    public function __construct(private array &$dispatched)
    {
    }

    public function key(): string
    {
        return 'fallback';
    }

    public function supports(string $assetType): bool
    {
        return true;
    }

    public function dispatch(Site $site): void
    {
        $this->dispatched[] = 'fallback:' . $site->id;
    }
}

final class AssetMonitoringStrategyRouterTest extends TestCase
{
    public function test_it_routes_to_matching_strategy(): void
    {
        $dispatched = [];

        $router = new AssetMonitoringStrategyRouter([
            new TestApiStrategy($dispatched),
            new TestFallbackStrategy($dispatched),
        ]);

        $site = new Site();
        $site->id = 10;
        $site->asset_type = 'rest_api';

        $resolved = $router->dispatch($site, 'rest_api');

        $this->assertSame('api', $resolved);
        $this->assertSame(['api:10'], $dispatched);
    }
}
