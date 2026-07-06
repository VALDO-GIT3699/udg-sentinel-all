<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Support\AssetIntelligenceSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\Monitoring\Services\Strategies\AssetMonitoringStrategyRouter;

final class DispatchAssetMonitoringCommand extends Command
{
    protected $signature = 'monitoring:dispatch-asset-monitoring {--limit=150 : Maximo de activos a despachar por ciclo}';

    protected $description = 'Despacha monitoreo inteligente por estrategia de tipo de activo.';

    public function handle(
        SiteRepositoryInterface $siteRepository,
        AssetMonitoringStrategyRouter $strategyRouter,
        AssetIntelligenceSchema $assetSchema,
    ): int {
        if ((bool) Cache::get('monitoring:single-site-rescan:pause', false) === true) {
            $prioritySiteId = (int) Cache::get('monitoring:single-site-rescan:site_id', 0);

            if ($prioritySiteId <= 0) {
                $this->warn('Despacho general en pausa temporal por reescaneo puntual.');
                return self::SUCCESS;
            }

            $prioritySite = $siteRepository->findById($prioritySiteId);
            if ($prioritySite !== null && $prioritySite->is_active && $prioritySite->is_monitored) {
                $assetType = $assetSchema->isReady() ? (string) ($prioritySite->asset_type ?? 'unknown') : 'unknown';
                $strategy = $strategyRouter->dispatch($prioritySite, $assetType);
                $this->line(sprintf('Site prioritario #%d -> estrategia %s', (int) $prioritySite->id, $strategy));
            }

            Cache::forget('monitoring:single-site-rescan:pause');
            Cache::forget('monitoring:single-site-rescan:site_id');

            $this->info('Reescaneo prioritario completado. El escaneo general puede reanudarse.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        $sites = $siteRepository->dueForCheck($limit);
        $dispatched = 0;

        foreach ($sites as $site) {
            $assetType = $assetSchema->isReady() ? (string) ($site->asset_type ?? 'unknown') : 'unknown';
            $strategy = $strategyRouter->dispatch($site, $assetType);
            $dispatched++;

            $this->line(sprintf('Site #%d -> estrategia %s', (int) $site->id, $strategy));
        }

        $this->info(sprintf('Despachado monitoreo inteligente para %d activos.', $dispatched));

        return self::SUCCESS;
    }
}
