<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Reconciliation\Parsing;

interface InventorySourceParserInterface
{
    public function supports(string $filePath): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $filePath): array;
}
