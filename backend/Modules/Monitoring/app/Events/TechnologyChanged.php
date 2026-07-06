<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class TechnologyChanged
{
    use Dispatchable;

    /**
     * @param array<int, string> $added
     * @param array<int, string> $removed
     */
    public function __construct(
        public int $siteId,
        public array $added,
        public array $removed,
        public string $detectedAt,
    ) {
    }
}
