<?php

declare(strict_types=1);

namespace Modules\Monitoring\Support;

final class DetectedTechnology
{
    private const CATEGORY_LABELS = [
        'cms' => 'Gestor de Contenido (CMS)',
        'framework' => 'Framework',
        'infrastructure' => 'Infraestructura',
        'infra' => 'Infraestructura',
        'web-server' => 'Servidor web',
        'server' => 'Servidor web',
        'language' => 'Lenguaje',
        'database' => 'Base de datos',
        'library' => 'Librería',
        'module' => 'Módulo',
        'theme' => 'Tema',
        'analytics' => 'Analítica',
        'security' => 'Seguridad',
        'other' => 'Otro',
    ];

    public function __construct(
        private readonly string $name,
        private readonly ?string $version,
        private readonly string $category,
        private readonly int $confidence,
        private readonly ?string $vendor = null,
        private readonly ?string $slug = null,
        private readonly bool $isObsolete = false,
        private readonly array $evidence = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $name = self::sanitizeText($payload['name'] ?? null, 'No identificada');
        $version = self::sanitizeText($payload['version'] ?? null, null);
        $category = self::normalizeCategory($payload['category'] ?? 'other');
        $confidence = self::normalizeConfidence($payload['confidence'] ?? $payload['confidence_pct'] ?? 0);
        $vendor = self::sanitizeText($payload['vendor'] ?? null, null);
        $slug = self::sanitizeText($payload['slug'] ?? null, null);
        $isObsolete = self::normalizeBoolean($payload['is_obsolete'] ?? null)
            || self::looksObsolete($version, $payload['version_status'] ?? null);
        $evidence = self::normalizeEvidence($payload['evidence'] ?? $payload['matched'] ?? []);

        return new self(
            name: $name,
            version: $version,
            category: $category,
            confidence: $confidence,
            vendor: $vendor,
            slug: $slug,
            isObsolete: $isObsolete,
            evidence: $evidence,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toFrontendArray(): array
    {
        $categoryLabel = self::CATEGORY_LABELS[$this->category] ?? ucfirst(str_replace(['-', '_'], ' ', $this->category));
        $badgeState = $this->resolveBadgeState();

        return [
            'name' => $this->name,
            'version' => $this->version,
            'category' => $this->category,
            'category_label' => $categoryLabel,
            'confidence' => $this->confidence,
            'vendor' => $this->vendor,
            'slug' => $this->slug,
            'is_obsolete' => $this->isObsolete,
            'badge_state' => $badgeState,
            'badge_label' => $badgeState === 'danger' ? 'Desactualizada' : 'Actualizada',
            'display_name' => $this->version !== null && $this->version !== ''
                ? $this->name . ' ' . $this->version
                : $this->name,
            'evidence' => $this->evidence,
        ];
    }

    private function resolveBadgeState(): string
    {
        if ($this->version === null || $this->version === '' || $this->isObsolete) {
            return 'danger';
        }

        return 'success';
    }

    private static function sanitizeText(mixed $value, ?string $fallback): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $fallback;
        }

        $text = trim(strip_tags((string) $value));

        if ($text === '') {
            return $fallback;
        }

        return mb_substr($text, 0, 160);
    }

    private static function normalizeCategory(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return 'other';
        }

        $category = mb_strtolower(trim((string) $value));
        $category = str_replace([' ', '_'], '-', $category);

        return $category !== '' ? $category : 'other';
    }

    private static function normalizeConfidence(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, min(100, (int) round((float) $value)));
        }

        return 0;
    }

    private static function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function looksObsolete(?string $version, mixed $status): bool
    {
        if ($version === null || $version === '') {
            return true;
        }

        if (is_string($status) && in_array(mb_strtolower(trim($status)), ['obsolete', 'outdated', 'deprecated'], true)) {
            return true;
        }

        return preg_match('/(?:alpha|beta|rc|dev|snapshot)/i', $version) === 1;
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeEvidence(mixed $evidence): array
    {
        if (! is_array($evidence)) {
            return [];
        }

        $normalized = [];

        foreach ($evidence as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            $text = trim(strip_tags((string) $item));

            if ($text !== '') {
                $normalized[] = mb_substr($text, 0, 160);
            }
        }

        return array_values(array_unique($normalized));
    }
}