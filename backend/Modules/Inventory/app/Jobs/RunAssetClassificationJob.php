<?php

declare(strict_types=1);

namespace Modules\Inventory\Jobs;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Support\AssetIntelligenceSchema;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Services\Classification\AssetClassificationService;

final class RunAssetClassificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(private readonly int $siteId)
    {
        $this->onQueue((string) config('inventory.classification.queue', 'inventory-asset-classification'));
    }

    public function handle(
        SiteRepositoryInterface $siteRepository,
        AssetClassificationService $assetClassificationService,
        AssetIntelligenceSchema $assetSchema,
    ): void {
        if (! $assetSchema->isReady()) {
            return;
        }

        $site = $siteRepository->findById($this->siteId);

        if (! $site instanceof Site || ! $site->is_active) {
            return;
        }

        $assetClassificationService->classifyAutomatically($site);
    }
}
