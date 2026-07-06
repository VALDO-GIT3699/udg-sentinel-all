<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

final readonly class AssetClassified
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $siteId,
        public string $source,
        public array $payload,
        public string $classifiedAt,
    ) {
    }
}
