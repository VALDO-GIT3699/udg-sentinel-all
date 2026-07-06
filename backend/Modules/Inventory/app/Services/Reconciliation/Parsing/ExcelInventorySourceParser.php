<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Reconciliation\Parsing;

use PhpOffice\PhpSpreadsheet\IOFactory;

final class ExcelInventorySourceParser implements InventorySourceParserInterface
{
    public function supports(string $filePath): bool
    {
        return in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['xlsx', 'xls', 'ods'], true);
    }

    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = [];
        $headers = [];

        foreach ($worksheet->toArray(null, true, true, false) as $index => $values) {
            if ($index === 0) {
                $headers = array_map([$this, 'normalizeHeader'], $values);
                continue;
            }

            if ($values === [null] || $values === []) {
                continue;
            }

            $row = [];
            foreach ($headers as $position => $header) {
                $row[$header] = $values[$position] ?? null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function normalizeHeader(string $value): string
    {
        return trim(mb_strtolower((string) preg_replace('/\s+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value)), '_');
    }
}
