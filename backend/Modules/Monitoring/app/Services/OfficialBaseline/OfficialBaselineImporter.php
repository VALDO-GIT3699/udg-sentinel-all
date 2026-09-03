<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services\OfficialBaseline;

use App\Models\OfficialBaselineSite;
use App\Models\OfficialBaselineSnapshot;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\Reconciliation\InventorySourceParser;

final class OfficialBaselineImporter
{
    /**
     * @return array{total_rows:int, unique_domains:int, rows:array<int, array<string, mixed>>}
     */
    public function summarize(string $sourcePath): array
    {
        $rows = $this->parseRows($sourcePath);
        $normalizedDomains = [];

        foreach ($rows as $row) {
            $domain = $this->normalizeDomain((string) ($row['dominio'] ?? ''));

            if ($domain !== '') {
                $normalizedDomains[] = $domain;
            }
        }

        return [
            'total_rows' => count($rows),
            'unique_domains' => count(array_values(array_unique($normalizedDomains))),
            'rows' => $rows,
        ];
    }

    public function import(string $sourcePath, ?int $importedBy = null, ?string $sourceName = null): OfficialBaselineSnapshot
    {
        $summary = $this->summarize($sourcePath);
        $rows = $summary['rows'];

        if ($rows === []) {
            throw new \RuntimeException('La fuente de baseline no contiene filas importables.');
        }

        $sourceHash = hash_file('sha256', $sourcePath);

        if (! is_string($sourceHash) || $sourceHash === '') {
            throw new \RuntimeException('No se pudo calcular hash de la fuente baseline.');
        }

        $snapshot = DB::transaction(function () use ($sourcePath, $sourceName, $sourceHash, $importedBy, $summary, $rows): OfficialBaselineSnapshot {
            OfficialBaselineSnapshot::query()->where('is_current', true)->update(['is_current' => false]);

            $snapshot = OfficialBaselineSnapshot::query()->create([
                'source_name' => $sourceName ?? basename($sourcePath),
                'source_path' => $sourcePath,
                'source_type' => strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'unknown'),
                'source_hash' => $sourceHash,
                'imported_by' => $importedBy,
                'is_current' => true,
                'total_rows' => (int) $summary['total_rows'],
                'unique_domains' => (int) $summary['unique_domains'],
                'notes' => 'Baseline inicial importada desde CSV oficial. No se utiliza como fuente runtime.',
                'imported_at' => now(),
            ]);

            $now = now();
            $payload = [];

            foreach ($rows as $index => $row) {
                $status = $this->normalizeText((string) ($row['estatus_proyecto'] ?? ''));
                $comments = $this->normalizeText((string) ($row['comentarios'] ?? ''));
                $ticketNumber = $this->extractTicketNumber($status.' '.$comments);

                $payload[] = [
                    'snapshot_id' => (int) $snapshot->id,
                    'row_number' => $index + 1,
                    'normalized_domain' => $this->normalizeDomain((string) ($row['dominio'] ?? '')),
                    'classification' => $this->normalizeText((string) ($row['clasificacion'] ?? '')),
                    'entity' => $this->normalizeText((string) ($row['entidad'] ?? '')),
                    'site_name' => $this->normalizeText((string) ($row['nombre_del_sitio'] ?? '')),
                    'domain' => $this->normalizeText((string) ($row['dominio'] ?? '')),
                    'is_active' => $this->toBoolean($row['sitio_activo'] ?? null),
                    'cms_label' => $this->normalizeText((string) ($row['cms'] ?? '')),
                    'server_ip' => $this->normalizeText((string) ($row['ip_servidor'] ?? '')),
                    'certificate_label' => $this->normalizeText((string) ($row['certificado_de_seguridad'] ?? '')),
                    'project_status' => $status,
                    'comments' => $comments,
                    'ticket_number' => $ticketNumber,
                    'raw_payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($payload, 500) as $chunk) {
                OfficialBaselineSite::query()->insert($chunk);
            }

            return $snapshot;
        });

        return $snapshot->fresh(['sites']) ?? $snapshot;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseRows(string $sourcePath): array
    {
        try {
            return InventorySourceParser::default()->parse($sourcePath);
        } catch (\RuntimeException) {
            return $this->parseCsvFallback($sourcePath);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsvFallback(string $sourcePath): array
    {
        $handle = fopen($sourcePath, 'r');

        if ($handle === false) {
            return [];
        }

        $headers = null;
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(fn (string $value): string => $this->normalizeHeader($value), $data);
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

    private function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function normalizeDomain(string $value): string
    {
        $value = trim($value);

        if ($value === '' || mb_strtolower($value) === 'nd') {
            return '';
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];

        return mb_strtolower(trim($value));
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = mb_strtolower($this->normalizeText((string) $value));

        return in_array($normalized, ['1', 'true', 'si', 'sí', 'activo', 'activa', 'yes', 'y'], true);
    }

    private function extractTicketNumber(string $value): ?string
    {
        if (preg_match('/\bticket\s*#?\s*([a-z0-9\-]+)\b/i', $value, $matches) !== 1) {
            return null;
        }

        $ticket = strtoupper(trim((string) ($matches[1] ?? '')));

        return $ticket !== '' ? $ticket : null;
    }
}
