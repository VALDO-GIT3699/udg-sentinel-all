<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Reconciliation\Parsing;

use Modules\Inventory\Services\Reconciliation\Parsing\InventorySourceParserInterface;

final class MarkdownInventorySourceParser implements InventorySourceParserInterface
{
    public function supports(string $filePath): bool
    {
        return in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['md', 'markdown', 'txt'], true);
    }

    public function parse(string $filePath): array
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return [];
        }

        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = str_replace(['<br>', '<br/>', '<br />'], "\n", $content);
        $content = preg_replace("/\r\n|\r/", "\n", $content) ?? $content;

        $rows = $this->parsePipeRows($content);

        if ($this->looksLikeInventoryTable($rows)) {
            return $rows;
        }

        return $this->parsePlainTextRows($content);
    }

    private function normalizeHeader(string $value): string
    {
        return trim(mb_strtolower((string) preg_replace('/\s+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value)), '_');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parsePipeRows(string $content): array
    {
        $lines = preg_split('/\n+/', $content) ?: [];
        $header = null;
        $rows = [];

        foreach ($lines as $line) {
            if (strpos($line, '|') === false) {
                continue;
            }

            $cells = array_values(array_map('trim', explode('|', trim($line, "| \t\n\r\0\x0B"))));

            if ($cells === [] || $this->isSeparatorRow($cells)) {
                continue;
            }

            if ($header === null) {
                $header = array_map([$this, 'normalizeHeader'], $cells);
                continue;
            }

            $row = [];
            foreach ($header as $index => $column) {
                $row[$column] = $cells[$index] ?? null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parsePlainTextRows(string $content): array
    {
        $lines = preg_split('/\n+/', $content) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '**') || str_starts_with($line, '|')) {
                continue;
            }

            if (! preg_match('#https?://#i', $line)) {
                continue;
            }

            if (! preg_match('/^(?<classification>[A-ZÁÉÍÓÚÑ]{1,6})\s+(?<entity>.+?)\s+(?<name>.+?)\s+(?<domain>https?:\/\/\S+)\s+(?<tail>.+)$/u', $line, $matches)) {
                continue;
            }

            $tailTokens = preg_split('/\s+/u', trim((string) $matches['tail'])) ?: [];
            $siteActive = $tailTokens[0] ?? null;
            $tailTokens = array_values(array_slice($tailTokens, 1));

            $cms = $tailTokens[0] ?? null;
            if ($cms !== null) {
                $tailTokens = array_values(array_slice($tailTokens, 1));
            }

            $serverValue = null;
            if ($tailTokens !== []) {
                $firstToken = (string) ($tailTokens[0] ?? '');
                $secondToken = (string) ($tailTokens[1] ?? '');

                if ($this->looksLikeIpOrMarker($firstToken)) {
                    $serverValue = $firstToken;
                    $tailTokens = array_values(array_slice($tailTokens, 1));
                } elseif (mb_strtolower($firstToken) === 'no' && mb_strtolower($secondToken) === 'tiene') {
                    $serverValue = 'No tiene';
                    $tailTokens = array_values(array_slice($tailTokens, 2));
                }
            }

            $status = null;
            $statusTokenCount = 0;
            foreach ($this->knownStatuses() as $candidate) {
                $candidateTokens = preg_split('/\s+/u', $candidate) ?: [];
                if ($candidateTokens === []) {
                    continue;
                }

                $window = array_slice($tailTokens, 0, count($candidateTokens));
                if (mb_strtolower(trim(implode(' ', $window))) === mb_strtolower($candidate)) {
                    $status = $candidate;
                    $statusTokenCount = count($candidateTokens);
                    break;
                }
            }

            if ($status !== null && $statusTokenCount > 0) {
                $tailTokens = array_values(array_slice($tailTokens, $statusTokenCount));
            }

            $rows[] = [
                'clasificacion' => $matches['classification'],
                'entidad' => trim((string) $matches['entity']),
                'nombre_del_sitio' => trim((string) $matches['name']),
                'dominio' => trim((string) $matches['domain']),
                'sitio_activo' => $siteActive,
                'cms' => $cms,
                'ip_servidor' => $serverValue,
                'certificado_de_seguridad' => null,
                'estatus_proyecto' => $status,
                'comentarios' => trim(implode(' ', $tailTokens)),
                'raw_line' => $line,
            ];
        }

        return $rows;
    }

    private function isSeparatorRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (! preg_match('/^:?[-\s]+:?$/', $cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function looksLikeInventoryTable(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        $firstRow = $rows[0] ?? [];

        return array_key_exists('dominio', $firstRow)
            || array_key_exists('domain', $firstRow)
            || array_key_exists('nombre_del_sitio', $firstRow)
            || array_key_exists('site_name', $firstRow);
    }

    private function looksLikeIpOrMarker(string $value): bool
    {
        return (bool) preg_match('/^(?:\d{1,3}\.){3}\d{1,3}$/', $value)
            || in_array(mb_strtolower(trim($value)), ['externo', 'no tiene', 'sin dato'], true);
    }

    /**
     * @return array<int, string>
     */
    private function knownStatuses(): array
    {
        return [
            'Activo',
            'Migrado y publicado',
            'En proceso de migración',
            'Solicitud de baja',
            '2da etapa',
            '3ra etapa',
            'Pendiente',
            'No tiene',
        ];
    }
}
