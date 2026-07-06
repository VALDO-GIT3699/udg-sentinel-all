<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services\Strategies;

use App\Models\Site;
use Modules\Monitoring\Jobs\RunMailServerProbeJob;

final class MailServerMonitoringStrategy implements AssetMonitoringStrategyInterface
{
    public function key(): string
    {
        return 'mail_server';
    }

    public function supports(string $assetType): bool
    {
        return $assetType === 'mail_server';
    }

    public function dispatch(Site $site): void
    {
        RunMailServerProbeJob::dispatch((int) $site->id);
    }
}
