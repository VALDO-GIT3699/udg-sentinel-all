<?php

declare(strict_types=1);

namespace Modules\Monitoring\Support;

final class DrupalFingerprint
{
    private const DRUPAL_MIN_MAJOR = 6;

    private const DRUPAL_MAX_MAJOR = 10;

    private const MIN_STRUCTURAL_SIGNATURES = 2;

    /**
     * @param  array<string, array<int, string>>  $headers
     * @param  array<int, array<string, mixed>>  $probes
     * @return array<string, mixed>|null
     */
    public static function detect(array $headers, string $bodyRaw, array $probes = []): ?array
    {
        $generator = self::extractGeneratorMeta($bodyRaw);
        $xGenerator = self::headerValue($headers, 'x-generator');
        $xDrupalCache = self::headerValue($headers, 'x-drupal-cache');
        $scriptSources = self::extractScriptSources($bodyRaw);
        $probeText = implode("\n", array_map(static fn (array $probe): string => (string) ($probe['body_raw'] ?? ''), $probes));
        $probePaths = array_map(static fn (array $probe): string => (string) ($probe['path'] ?? ''), $probes);
        $probeMap = self::probeMap($probes);

        $hasGeneratorSignal = $generator !== '' && str_contains(mb_strtolower($generator), 'drupal');
        $hasXGeneratorSignal = $xGenerator !== '' && str_contains(mb_strtolower($xGenerator), 'drupal');
        $hasXDrupalCacheSignal = $xDrupalCache !== '';
        $legacyDrupal = self::hasLegacyDrupalSignals($bodyRaw, $probeText, $probePaths, $scriptSources);
        $coreSignals = self::hasDrupalCoreSignals($bodyRaw, $probeText, $probePaths, $scriptSources);
        $structuralSignalCount = self::countStructuralSignatures($bodyRaw, $probeText, $probePaths, $scriptSources);
        $era = self::detectDrupalEra($bodyRaw, $probeText, $probeMap, $scriptSources);
        $hasHighIntegritySignal = self::hasHighIntegrityDrupalSignal(
            $hasGeneratorSignal,
            $hasXGeneratorSignal,
            $hasXDrupalCacheSignal,
            $bodyRaw,
            $probes,
        );

        if (
            ! $hasGeneratorSignal
            && ! $hasXGeneratorSignal
            && ! $hasXDrupalCacheSignal
            && ! $coreSignals
            && ! $legacyDrupal
            && $era === null
        ) {
            return null;
        }

        if ($structuralSignalCount < self::MIN_STRUCTURAL_SIGNATURES) {
            return null;
        }

        if (! $hasHighIntegritySignal) {
            return null;
        }

        $evidence = [];

        if ($hasGeneratorSignal) {
            $evidence[] = 'generator-meta';
        }

        if ($hasXGeneratorSignal) {
            $evidence[] = 'x-generator';
        }

        if ($hasXDrupalCacheSignal) {
            $evidence[] = 'x-drupal-cache';
        }

        if ($coreSignals || $legacyDrupal) {
            $evidence[] = 'active-script-src';
        }

        $version = self::firstNonNullVersion([
            self::extractPriorityVersion($generator),
            self::extractPriorityVersion($xGenerator),
            self::extractPriorityVersion($xDrupalCache),
            self::extractExactVersionFromCleanProbe($probes),
            self::extractExactVersion($probeText),
        ]);

        if ($version === null) {
            $version = match ($era) {
                '10' => '10',
                '9' => '9',
                '8' => '8',
                'modern' => '10',
                'legacy' => '7',
                default => null,
            };
        }

        $confidence = 84;

        if ($generator !== '') {
            $confidence = 96;
        } elseif ($xGenerator !== '' || $xDrupalCache !== '') {
            $confidence = 91;
        } elseif ($era === '10') {
            $confidence = 93;
        } elseif ($era === '9') {
            $confidence = 91;
        } elseif ($era === '8') {
            $confidence = 90;
        } elseif ($era === 'modern') {
            $confidence = 86;
        } elseif ($legacyDrupal) {
            $confidence = 87;
        } elseif ($scriptSources !== []) {
            $confidence = 88;
        }

        return [
            'name' => 'Drupal',
            'version' => $version,
            'confidence' => $confidence,
            'evidence' => array_values(array_unique($evidence)),
        ];
    }

    private static function extractGeneratorMeta(string $bodyRaw): string
    {
        if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)["\']/i', $bodyRaw, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return '';
    }

    private static function extractPriorityVersion(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // 1. Captura "Drupal 10", "Drupal CMS 10" o "Drupal version 10" con hasta 2 decimales (ej. 10.3.2)
        if (preg_match('/\bDrupal\s+(?:CMS\s+)?(?:version\s+)?([0-9]+(?:\.[0-9]+){0,2})\b/i', $value, $matches) === 1) {
            return self::normalizeDrupalVersion((string) $matches[1]);
        }

        // 2. Si el texto menciona Drupal en cualquier lado, extrae el primer número de versión que encuentre
        if (preg_match('/\b([0-9]+(?:\.[0-9]+){0,2})\b/', $value, $matches) === 1 && str_contains(mb_strtolower($value), 'drupal')) {
            return self::normalizeDrupalVersion((string) $matches[1]);
        }

        return null;
    }

    private static function extractExactVersion(string $text): ?string
    {
        // 1. Busca "Drupal" seguido directamente por la versión (ej. Drupal 10.2)
        if (preg_match('/\bdrupal\s+([0-9]+(?:\.[0-9]+){0,2})\b/i', $text, $matches) === 1) {
            return self::normalizeDrupalVersion((string) $matches[1]);
        }

        // 2. Busca la versión primero y luego "drupal" con texto intermedio (evitando saltos de línea)
        if (preg_match('/\b([0-9]+(?:\.[0-9]+){0,2})\b[^\n]{0,40}?\bdrupal\b/i', $text, $matches) === 1) {
            return self::normalizeDrupalVersion((string) $matches[1]);
        }

        return null;
    }

    /**
     * @param  array<int, string>  $scriptSources
     */
    private static function inferVersionFromScripts(array $scriptSources): ?string
    {
        return null;
    }

    private static function normalizeDrupalVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $version = trim($version);

        if ($version === '') {
            return null;
        }

        if (preg_match('/^([0-9]+)(?:\.[0-9]+){0,2}$/', $version, $matches) !== 1) {
            return null;
        }

        $major = (int) $matches[1];

        if ($major < self::DRUPAL_MIN_MAJOR) {
            return null;
        }

        if ($major > self::DRUPAL_MAX_MAJOR) {
            // Politica operativa: nunca reportar Drupal 11+ en el dashboard.
            return (string) self::DRUPAL_MAX_MAJOR;
        }

        return (string) $major;
    }

    /**
     * @param  array<int, array<string, mixed>>  $probes
     */
    private static function extractExactVersionFromCleanProbe(array $probes): ?string
    {
        foreach ($probes as $probe) {
            if ((int) ($probe['status'] ?? 0) !== 200) {
                continue;
            }

            $body = (string) ($probe['body_raw'] ?? '');
            $path = mb_strtolower((string) ($probe['path'] ?? ''));

            if ($body === '' || ! str_contains(mb_strtolower($body), 'drupal')) {
                continue;
            }

            if (
                ! str_contains($path, '/core/')
                && ! str_contains($path, '/changelog')
                && ! str_contains($path, '/version')
            ) {
                continue;
            }

            $version = self::extractExactVersion($body);

            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $probes
     */
    private static function hasHighIntegrityDrupalSignal(
        bool $hasGeneratorSignal,
        bool $hasXGeneratorSignal,
        bool $hasXDrupalCacheSignal,
        string $bodyRaw,
        array $probes,
    ): bool {
        if ($hasGeneratorSignal || $hasXGeneratorSignal || $hasXDrupalCacheSignal) {
            return true;
        }

        if (str_contains(mb_strtolower($bodyRaw), 'drupal-settings-json')) {
            return true;
        }

        foreach ($probes as $probe) {
            if ((int) ($probe['status'] ?? 0) !== 200) {
                continue;
            }

            $path = mb_strtolower((string) ($probe['path'] ?? ''));
            $body = mb_strtolower((string) ($probe['body_raw'] ?? ''));

            if (
                in_array($path, ['/core/lib/drupal.php', '/core/changelog.txt', '/changelog.txt'], true)
                && str_contains($body, 'drupal')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, ?string>  $versions
     */
    private static function firstNonNullVersion(array $versions): ?string
    {
        foreach ($versions as $version) {
            $normalized = self::normalizeDrupalVersion($version);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $probes
     * @return array<string, array{status:int,path:string,body:string}>
     */
    private static function probeMap(array $probes): array
    {
        $map = [];

        foreach ($probes as $probe) {
            $path = mb_strtolower((string) ($probe['path'] ?? ''));

            if ($path === '') {
                continue;
            }

            $map[$path] = [
                'status' => (int) ($probe['status'] ?? 0),
                'path' => $path,
                'body' => mb_strtolower((string) ($probe['body_raw'] ?? '')),
            ];
        }

        return $map;
    }

    /**
     * @param  array<string, array{status:int,path:string,body:string}>  $probeMap
     * @param  array<int, string>  $scriptSources
     */
    private static function detectDrupalEra(string $bodyRaw, string $probeText, array $probeMap, array $scriptSources): ?string
    {
        $stable11Status = self::probeStatus($probeMap, '/core/themes/stable11/VERSION');
        $stable10Status = self::probeStatus($probeMap, '/core/themes/stable10/VERSION');
        $stable9Status = self::probeStatus($probeMap, '/core/themes/stable9/VERSION');
        $starterkitStatus = self::probeStatus($probeMap, '/core/themes/starterkit_theme/README.md');
        $ckeditor5Status = self::probeStatus($probeMap, '/core/assets/vendor/ckeditor5/README.md');
        $hasCoreSignals = self::hasDrupalCoreSignals($bodyRaw, $probeText, array_keys($probeMap), $scriptSources);
        $hasDrupal8Signals = self::hasDrupal8Signals($bodyRaw, $probeText, $scriptSources);
        $hasDrupal11Signals = $stable11Status === 200 || self::containsAny($probeText, ['/core/themes/stable11/']);
        $hasDrupal10Signals = $starterkitStatus === 200 || $ckeditor5Status === 200 || self::containsAny($probeText, ['/core/themes/starterkit_theme/', '/core/assets/vendor/ckeditor5/']);

        if (! $hasCoreSignals) {
            return $hasDrupal8Signals ? 'legacy' : null;
        }

        if ($stable9Status === 404 || $hasDrupal8Signals) {
            return '8';
        }

        if ($hasDrupal11Signals) {
            // Política de seguridad operativa: cualquier señal >=11 se normaliza a rama 10.
            return '10';
        }

        if ($stable10Status === 200 || ($stable9Status === 200 && $hasDrupal10Signals)) {
            return '10';
        }

        if ($stable9Status === 200) {
            return '9';
        }

        return 'modern';
    }

    /**
     * @param  array<int, string>  $probePaths
     * @param  array<int, string>  $scriptSources
     */
    private static function countStructuralSignatures(string $bodyRaw, string $probeText, array $probePaths, array $scriptSources): int
    {
        $body = mb_strtolower($bodyRaw."\n".$probeText."\n".implode("\n", $probePaths)."\n".implode("\n", $scriptSources));
        $signatures = [
            str_contains($body, '/core/'),
            str_contains($body, '/sites/default/'),
            str_contains($body, 'drupal-settings-json') || str_contains($body, 'drupal.settings'),
            str_contains($body, '/misc/drupal.js'),
            str_contains($body, '/sites/all/themes/') || str_contains($body, '/sites/all/modules/'),
        ];

        $count = 0;

        foreach ($signatures as $matched) {
            if ($matched) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, array{status:int,path:string,body:string}>  $probeMap
     */
    private static function probeStatus(array $probeMap, string $path): ?int
    {
        $normalized = mb_strtolower($path);

        return $probeMap[$normalized]['status'] ?? null;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function containsAny(string $text, array $needles): bool
    {
        $haystack = mb_strtolower($text);

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $scriptSources
     */
    private static function hasDrupalCoreSignals(string $bodyRaw, string $probeText, array $probePaths, array $scriptSources): bool
    {
        $haystacks = [mb_strtolower($bodyRaw), mb_strtolower($probeText), mb_strtolower(implode("\n", $probePaths))];

        foreach ($haystacks as $haystack) {
            if ($haystack === '') {
                continue;
            }

            if (str_contains($haystack, '/core/') || str_contains($haystack, 'drupal-settings-json') || str_contains($haystack, '/sites/default/files/js/')) {
                return true;
            }
        }

        foreach ($scriptSources as $scriptSource) {
            $normalized = mb_strtolower($scriptSource);

            if (str_contains($normalized, '/core/') || str_contains($normalized, 'drupal-settings-json') || str_contains($normalized, '/sites/default/files/js/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $scriptSources
     */
    private static function hasDrupal8Signals(string $bodyRaw, string $probeText, array $scriptSources): bool
    {
        $haystacks = [mb_strtolower($bodyRaw), mb_strtolower($probeText)];

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && str_contains($haystack, 'core/assets/vendor/jquery.ui/')) {
                return true;
            }
        }

        foreach ($scriptSources as $scriptSource) {
            if (str_contains(mb_strtolower($scriptSource), 'core/assets/vendor/jquery.ui/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $scriptSources
     */
    private static function hasModernDrupalCoreSignals(string $bodyRaw, string $probeText, array $probePaths, array $scriptSources): bool
    {
        $haystacks = [mb_strtolower($bodyRaw), mb_strtolower($probeText), mb_strtolower(implode("\n", $probePaths))];

        foreach ($haystacks as $haystack) {
            if ($haystack === '') {
                continue;
            }

            if (str_contains($haystack, '/core/modules/') || str_contains($haystack, '/core/themes/') || str_contains($haystack, '/core/assets/vendor/') || str_contains($haystack, '/core/lib/Drupal.php') || str_contains($haystack, '/core/themes/stable9/VERSION') || str_contains($haystack, '/core/changelog.txt') || str_contains($haystack, '/changelog.txt')) {
                return true;
            }
        }

        foreach ($scriptSources as $scriptSource) {
            $normalized = mb_strtolower($scriptSource);

            if (str_contains($normalized, '/core/modules/') || str_contains($normalized, '/core/themes/') || str_contains($normalized, '/core/assets/vendor/') || str_contains($normalized, '/core/lib/drupal.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $scriptSources
     */
    private static function hasLegacyDrupalSignals(string $bodyRaw, string $probeText, array $probePaths, array $scriptSources): bool
    {
        $haystacks = [mb_strtolower($bodyRaw), mb_strtolower($probeText), mb_strtolower(implode("\n", $probePaths))];

        foreach ($haystacks as $haystack) {
            if ($haystack === '') {
                continue;
            }

            if (str_contains($haystack, '/misc/drupal.js') || str_contains($haystack, 'modules/system/') || str_contains($haystack, '/sites/all/themes/') || str_contains($haystack, '/sites/all/modules/')) {
                return true;
            }
        }

        foreach ($scriptSources as $scriptSource) {
            $normalized = mb_strtolower($scriptSource);

            if (str_contains($normalized, '/misc/drupal.js') || str_contains($normalized, 'modules/system/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private static function extractScriptSources(string $bodyRaw): array
    {
        if (! preg_match_all('/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $bodyRaw, $matches) || ! isset($matches[1]) || ! is_array($matches[1])) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (string $value): string => trim($value), $matches[1])));
    }

    private static function headerValue(array $headers, string $name): string
    {
        $lowerName = mb_strtolower($name);

        foreach ($headers as $headerName => $values) {
            if (mb_strtolower((string) $headerName) !== $lowerName) {
                continue;
            }

            return trim(implode(' ', is_array($values) ? $values : []));
        }

        return '';
    }
}
