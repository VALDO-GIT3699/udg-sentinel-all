<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class AlertResolved
{
    use Dispatchable;

    public function __construct(
        public int $alertId,
        public ?int $siteId,
        public string $event,
        public string $resolvedAt,
    ) {
    }
}
