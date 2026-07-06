<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Reconciliation;

use App\Models\Site;
use Illuminate\Support\Str;

final class InventoryReconciler
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function reconcile(array $row): array
    {
        $domain = $this->normalizeDomain((string) ($row['dominio'] ?? $row['domain'] ?? $row['url'] ?? ''));
        $name = $this->normalizeName((string) ($row['nombre_del_sitio'] ?? $row['nombre'] ?? $row['site_name'] ?? ''));
        $cms = $this->normalizeText((string) ($row['cms'] ?? ''));
        $ip = $this->normalizeText((string) ($row['ip_servidor'] ?? $row['ip'] ?? ''));
        $sourceActive = $this->toBoolean($row['sitio_activo'] ?? $row['activo'] ?? null);

        $candidates = Site::query()
            ->with(['siteGroup'])
            ->where(function ($query) use ($domain, $name, $ip): void {
                if ($domain !== '') {
                    $query->orWhereRaw('LOWER(domain) = ?', [mb_strtolower($domain)]);
                    $query->orWhereRaw('LOWER(url) LIKE ?', ['%' . mb_strtolower($domain) . '%']);
                }

                if ($name !== '') {
                    $query->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
                }

                if ($ip !== '') {
                    $query->orWhereRaw('LOWER(COALESCE(notes, \'\')) LIKE ?', ['%' . mb_strtolower($ip) . '%']);
                }
            })
            ->limit(10)
            ->get();

        $bestCandidate = null;
        $bestScore = 0;
        $evidence = [];

        foreach ($candidates as $candidate) {
            $score = 0;
            $candidateDomain = $this->normalizeDomain((string) $candidate->domain);
            $candidateName = $this->normalizeName((string) $candidate->name);
            $candidateUrl = $this->normalizeDomain((string) $candidate->url);

            if ($domain !== '' && ($domain === $candidateDomain || $domain === $candidateUrl)) {
                $score += 60;
                $evidence[] = 'dominio_exacto:' . $candidate->id;
            }

            if ($name !== '' && $candidateName !== '' && similar_text($name, $candidateName, $similarity) && $similarity >= 75) {
                $score += 25;
                $evidence[] = 'nombre:' . $candidate->id;
            }

            if ($cms !== '' && $this->matchTechnologyLabel($cms, (string) ($candidate->asset_type ?? ''))) {
                $score += 10;
                $evidence[] = 'cms:' . $candidate->id;
            }

            if ($sourceActive && $candidate->is_active) {
                $score += 5;
                $evidence[] = 'activo:' . $candidate->id;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCandidate = $candidate;
            }
        }

        $matchKind = 'new';
        if ($bestCandidate !== null) {
            $matchKind = $bestScore >= 70 ? 'exact' : ($bestScore >= 40 ? 'probable' : 'new');
        }

        $proposedChanges = [
            'source_active' => $sourceActive,
            'source_cms' => $cms !== '' ? $cms : null,
            'source_ip' => $ip !== '' ? $ip : null,
            'source_comments' => $this->normalizeText((string) ($row['comentarios'] ?? $row['comments'] ?? '')),
            'source_status' => $this->normalizeText((string) ($row['estatus_proyecto'] ?? $row['estatus'] ?? '')),
        ];

        return [
            'site_id' => $bestCandidate?->id,
            'match_kind' => $matchKind,
            'match_score' => $bestScore,
            'normalized_domain' => $domain !== '' ? $domain : null,
            'normalized_name' => $name !== '' ? $name : null,
            'normalized_cms' => $cms !== '' ? $cms : null,
            'normalized_ip' => $ip !== '' ? $ip : null,
            'source_active' => $sourceActive,
            'evidence' => array_values(array_unique($evidence)),
            'proposed_changes' => array_filter($proposedChanges, static fn ($value) => $value !== null && $value !== ''),
            'needs_review' => $matchKind !== 'exact',
        ];
    }

    private function normalizeText(string $value): string
    {
        $value = Str::of($value)->squish()->lower()->toString();

        return trim((string) preg_replace('/[^\pL\pN\.\-\_\s]+/u', '', $value));
    }

    private function normalizeName(string $value): string
    {
        return $this->normalizeText($value);
    }

    private function normalizeDomain(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];

        return $this->normalizeText($value);
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = $this->normalizeText((string) $value);

        return in_array($normalized, ['1', 'true', 'si', 'sí', 'activo', 'activa', 'yes', 'y'], true);
    }

    private function matchTechnologyLabel(string $needle, string $haystack): bool
    {
        $needle = $this->normalizeText($needle);
        $haystack = $this->normalizeText($haystack);

        if ($needle === '' || $haystack === '') {
            return false;
        }

        return str_contains($haystack, $needle) || str_contains($needle, $haystack);
    }
}
