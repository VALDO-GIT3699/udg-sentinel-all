<?php

declare(strict_types=1);

namespace Modules\Monitoring\Support;

final class DrupalFingerprint
{
    /**
     * @param array<string, array<int, string>> $headers
     * @param array<int, array<string, mixed>> $probes
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

        $evidence = [];

        if ($generator !== '') {
            $evidence[] = 'generator-meta';
        }

        if ($xGenerator !== '') {
            $evidence[] = 'x-generator';
        }

        if ($xDrupalCache !== '') {
            $evidence[] = 'x-drupal-cache';
        }

        if ($scriptSources !== []) {
            $evidence[] = 'active-script-src';
        }

        $legacyDrupal = self::hasLegacyDrupalSignals($bodyRaw, $probeText, $probePaths, $scriptSources);
        $coreSignals = self::hasDrupalCoreSignals($bodyRaw, $probeText, $probePaths, $scriptSources);
        $era = self::detectDrupalEra($bodyRaw, $probeText, $probeMap, $scriptSources);

        $version = self::firstNonNullVersion([
            self::extractPriorityVersion($generator),
            self::extractPriorityVersion($xGenerator),
            self::extractPriorityVersion($xDrupalCache),
            self::extractExactVersion($probeText),
        ]);

        if ($version === null) {
            $version = match ($era) {
                '11' => '11 (Core)',
                '10' => '10 (Core)',
                '9' => '9 (Core)',
                '8' => '8 (Core)',
                'modern' => 'Moderno (Core)',
                'legacy' => '7',
                default => null,
            };
        }

        if ($version === null && $evidence === [] && $era === null) {
            return null;
        }

        $confidence = 84;

        if ($generator !== '') {
            $confidence = 96;
        } elseif ($xGenerator !== '' || $xDrupalCache !== '') {
            $confidence = 91;
        } elseif ($era === '11') {
            $confidence = 94;
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

        if (preg_match('/\bDrupal\s*(?:CMS\s*)?(?:version\s*)?([0-9]+(?:\.[0-9]+){0,2})\b/i', $value, $matches) === 1) {
            return self::normalizeDrupalVersion((string) $matches[1]);
        }

        if (preg_match('/\bDrupal\s+([0-9]+)\b/i', $value, $matches) === 1) {
            return self::normalizeDrupalVersion((string) $matches[1]);
        }

        if (preg_match('/\b([0-9]+(?:\.[0-9]+){0,2})\b/', $value, $matches) === 1 && str_contains(mb_strtolower($value), 'drupal')) {
            return self::normalizeDrupalVersion((string) $matches[1]);
        }

        return null;
    }

    private static function extractExactVersion(string $text): ?string
    {
        if (preg_match('/\b([0-9]+(?:\.[0-9]+){1,2})\b/', $text, $matches) === 1) {
            return self::normalizeDrupalVersion((string) $matches[1]);
        }

        return null;
    }

    /**
     * @param array<int, string> $scriptSources
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

        return (string) $matches[1];
    }

    /**
     * @param array<int, ?string> $versions
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
     * @param array<int, array<string, mixed>> $probes
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
     * @param array<string, array{status:int,path:string,body:string}> $probeMap
     * @param array<int, string> $scriptSources
     */
    private static function detectDrupalEra(string $bodyRaw, string $probeText, array $probeMap, array $scriptSources): ?string
    {
        $stable10Status = self::probeStatus($probeMap, '/core/themes/stable10/VERSION');
        $stable9Status = self::probeStatus($probeMap, '/core/themes/stable9/VERSION');
        $starterkitStatus = self::probeStatus($probeMap, '/core/themes/starterkit_theme/README.md');
        $ckeditor5Status = self::probeStatus($probeMap, '/core/assets/vendor/ckeditor5/README.md');
        $hasCoreSignals = self::hasDrupalCoreSignals($bodyRaw, $probeText, array_keys($probeMap), $scriptSources);
        $hasDrupal8Signals = self::hasDrupal8Signals($bodyRaw, $probeText, $scriptSources);
        $hasDrupal11Signals = $stable10Status === 200 || self::containsAny($probeText, ['/core/themes/stable10/']);
        $hasDrupal10Signals = $starterkitStatus === 200 || $ckeditor5Status === 200 || self::containsAny($probeText, ['/core/themes/starterkit_theme/', '/core/assets/vendor/ckeditor5/']);

        if (! $hasCoreSignals) {
            return $hasDrupal8Signals ? 'legacy' : null;
        }

        if ($stable9Status === 404 || $hasDrupal8Signals) {
            return '8';
        }

        if ($stable9Status === 200 && $hasDrupal11Signals) {
            return '11';
        }

        if ($stable9Status === 200 && $hasDrupal10Signals) {
            return '10';
        }

        if ($stable9Status === 200) {
            return '9';
        }

        return 'modern';
    }

    /**
     * @param array<string, array{status:int,path:string,body:string}> $probeMap
     */
    private static function probeStatus(array $probeMap, string $path): ?int
    {
        $normalized = mb_strtolower($path);

        return $probeMap[$normalized]['status'] ?? null;
    }

    /**
     * @param array<int, string> $needles
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
     * @param array<int, string> $scriptSources
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
     * @param array<int, string> $scriptSources
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
     * @param array<int, string> $scriptSources
     */
    private static function hasModernDrupalCoreSignals(string $bodyRaw, string $probeText, array $probePaths, array $scriptSources): bool
    {
        $haystacks = [mb_strtolower($bodyRaw), mb_strtolower($probeText), mb_strtolower(implode("\n", $probePaths))];

        foreach ($haystacks as $haystack) {
            if ($haystack === '') {
                continue;
            }

            if (str_contains($haystack, '/core/modules/') || str_contains($haystack, '/core/themes/') || str_contains($haystack, '/core/assets/vendor/') || str_contains($haystack, '/core/lib/Drupal.php') || str_contains($haystack, '/core/themes/stable9/VERSION') || str_contains($haystack, '/core/changelog.txt') || str_contains($haystack, '/changeLog.txt')) {
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
     * @param array<int, string> $scriptSources
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