<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Support\AssetIntelligenceSchema;
use Illuminate\Console\Command;
use Modules\Inventory\Jobs\RunAssetClassificationJob;

final class DispatchAssetClassificationsCommand extends Command
{
    protected $signature = 'inventory:dispatch-asset-classifications {--limit=200 : Maximo de activos a clasificar}';

    protected $description = 'Despacha clasificacion automatica de activos digitales.';

    public function handle(SiteRepositoryInterface $siteRepository, AssetIntelligenceSchema $assetSchema): int
    {
        if (! $assetSchema->isReady()) {
            $this->warn('Asset Intelligence no esta disponible en el esquema. Ejecuta migraciones para habilitarlo.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sites = $siteRepository->dueForAssetClassification($limit);

        foreach ($sites as $site) {
            $this->dispatchJob((int) $site->id);
        }

        $this->info(sprintf('Despachadas %d clasificaciones de activos.', $sites->count()));

        return self::SUCCESS;
    }

    private function dispatchJob(int $siteId): void
    {
        if ($this->shouldDispatchSynchronously()) {
            RunAssetClassificationJob::dispatchSync($siteId);

            return;
        }

        RunAssetClassificationJob::dispatch($siteId);
    }

    private function shouldDispatchSynchronously(): bool
    {
        return app()->environment(['local', 'development', 'testing']);
    }
}
