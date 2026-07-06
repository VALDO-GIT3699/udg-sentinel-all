<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services\Strategies;

use App\Models\Site;
use Modules\Monitoring\Jobs\RunHeadCheckJob;
use Modules\Monitoring\Jobs\RunSecurityHeadersCheckJob;
use Modules\Monitoring\Jobs\RunSslCheckJob;
use Modules\Monitoring\Jobs\RunTechnologyScanJob;

final class WebsiteMonitoringStrategy implements AssetMonitoringStrategyInterface
{
    public function key(): string
    {
        return 'website';
    }

    public function supports(string $assetType): bool
    {
        return in_array($assetType, ['website', 'web_application', 'unknown'], true);
    }

    public function dispatch(Site $site): void
    {
        RunHeadCheckJob::dispatch((int) $site->id);
        RunSecurityHeadersCheckJob::dispatch((int) $site->id);
        RunSslCheckJob::dispatch((int) $site->id);
        RunTechnologyScanJob::dispatch((int) $site->id);
    }
}
