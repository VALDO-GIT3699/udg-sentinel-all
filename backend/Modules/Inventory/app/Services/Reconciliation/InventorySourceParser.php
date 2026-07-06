<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Reconciliation;

use Modules\Inventory\Services\Reconciliation\Parsing\CsvInventorySourceParser;
use Modules\Inventory\Services\Reconciliation\Parsing\ExcelInventorySourceParser;
use Modules\Inventory\Services\Reconciliation\Parsing\InventorySourceParserInterface;
use Modules\Inventory\Services\Reconciliation\Parsing\MarkdownInventorySourceParser;
use RuntimeException;

final class InventorySourceParser
{
    /**
     * @param array<int, InventorySourceParserInterface> $parsers
     */
    public function __construct(
        private readonly array $parsers = [],
    ) {
    }

    public static function default(): self
    {
        return new self([
            new ExcelInventorySourceParser(),
            new CsvInventorySourceParser(),
            new MarkdownInventorySourceParser(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $filePath): array
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($filePath)) {
                return $parser->parse($filePath);
            }
        }

        throw new RuntimeException('Formato de inventario no soportado.');
    }
}
