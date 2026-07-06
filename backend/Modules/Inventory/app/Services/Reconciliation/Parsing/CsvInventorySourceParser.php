<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Reconciliation\Parsing;

final class CsvInventorySourceParser implements InventorySourceParserInterface
{
    public function supports(string $filePath): bool
    {
        return in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['csv'], true);
    }

    public function parse(string $filePath): array
    {
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return [];
        }

        $headers = null;
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map([$this, 'normalizeHeader'], $data);
                continue;
            }

            if ($data === [null] || $data === []) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $data[$index] ?? null;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeHeader(string $value): string
    {
        return trim(mb_strtolower((string) preg_replace('/\s+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value)), '_');
    }
}
