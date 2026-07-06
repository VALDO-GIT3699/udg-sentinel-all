<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services\Strategies;

use App\Models\Site;
use Modules\Monitoring\Jobs\RunApiContractCheckJob;
use Modules\Monitoring\Jobs\RunHeadCheckJob;
use Modules\Monitoring\Jobs\RunSslCheckJob;

final class RestApiMonitoringStrategy implements AssetMonitoringStrategyInterface
{
    public function key(): string
    {
        return 'rest_api';
    }

    public function supports(string $assetType): bool
    {
        return in_array($assetType, ['rest_api', 'graphql', 'soap_api'], true);
    }

    public function dispatch(Site $site): void
    {
        RunHeadCheckJob::dispatch((int) $site->id);
        RunApiContractCheckJob::dispatch((int) $site->id);
        RunSslCheckJob::dispatch((int) $site->id);
    }
}
