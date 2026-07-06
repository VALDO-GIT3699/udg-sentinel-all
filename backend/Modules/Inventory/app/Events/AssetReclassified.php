<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

final readonly class AssetReclassified
{
    public function __construct(
        public int $siteId,
        public string $previousType,
        public string $previousRole,
        public string $newType,
        public string $newRole,
        public string $classifiedAt,
    ) {
    }
}
