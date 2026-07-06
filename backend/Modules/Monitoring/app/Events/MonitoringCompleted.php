<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class MonitoringCompleted
{
    use Dispatchable;

    public function __construct(
        public int $siteId,
        public string $status,
        public ?int $httpCode,
        public ?int $responseTimeMs,
        public string $checkedAt,
    ) {
    }
}
