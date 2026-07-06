<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class AvailabilityChanged
{
    use Dispatchable;

    public function __construct(
        public int $siteId,
        public string $before,
        public string $after,
        public string $changedAt,
    ) {
    }
}
