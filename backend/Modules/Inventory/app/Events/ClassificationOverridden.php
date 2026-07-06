<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

final readonly class ClassificationOverridden
{
    public function __construct(
        public int $siteId,
        public string $previousSource,
        public string $newSource,
        public string $reason,
        public ?int $userId,
        public string $overriddenAt,
    ) {
    }
}
