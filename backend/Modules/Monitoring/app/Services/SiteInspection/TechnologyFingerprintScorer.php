<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services\SiteInspection;

final class TechnologyFingerprintScorer
{
    private const DETECTION_THRESHOLD = 60;

    private const DECISIVE_MARGIN = 15;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, array<string, mixed>>  $knownFiles
     * @return array<string, mixed>
     */
    public function score(
        array $headers,
        string $body,
        array $knownFiles,
        string $inspectedUrl,
        string $finalUrl,
    ): array {
        $normalizedHeaders = array_change_key_case($headers, CASE_LOWER);
        $bodyLower = mb_strtolower($body);
        $generatorMeta = $this->extractGeneratorMeta($body);
        $cookies = $this->cookieNames((string) ($normalizedHeaders['set-cookie'] ?? ''));
        $inspectedHost = mb_strtolower((string) (parse_url($inspectedUrl, PHP_URL_HOST) ?? ''));
        $finalHost = mb_strtolower((string) (parse_url($finalUrl, PHP_URL_HOST) ?? ''));
        // Un redirect a un "espejo" del mismo dominio (www./portal./web./home., el mismo
        // criterio que ya usa EloquentSiteRepository para deduplicar sitios) sigue siendo
        // el mismo sitio: no debe tratarse como salto a un host ajeno.
        $hostAligned = $inspectedHost !== ''
            && $finalHost !== ''
            && $this->canonicalHost($inspectedHost) === $this->canonicalHost($finalHost);

        $candidates = [
            $this->scoreDrupal($normalizedHeaders, $body, $bodyLower, $knownFiles, $generatorMeta),
            $this->scoreWordPress($normalizedHeaders, $body, $bodyLower, $knownFiles, $generatorMeta),
            $this->scoreLaravel($normalizedHeaders, $body, $bodyLower, $knownFiles),
            $this->scoreJoomla($normalizedHeaders, $body, $bodyLower, $knownFiles, $generatorMeta),
            $this->scorePhp($normalizedHeaders, $body, $bodyLower, $knownFiles, $cookies),
            $this->scoreWix($normalizedHeaders, $bodyLower),
            $this->scoreNextjs($normalizedHeaders, $bodyLower),
        ];

        usort(
            $candidates,
            static fn (array $left, array $right): int => ((int) $right['score']) <=> ((int) $left['score']),
        );

        $winner = $candidates[0];
        $runnerUp = $candidates[1] ?? null;
        $runnerUpScore = is_array($runnerUp) ? (int) ($runnerUp['score'] ?? 0) : 0;
        $margin = (int) $winner['score'] - $runnerUpScore;

        $decision = $this->resolveDecision($winner, $runnerUp, $hostAligned, $finalUrl);
        $discarded = $this->discardedCandidates($candidates, $decision, $margin);
        $confidence = $this->confidenceScore(
            winningScore: (int) $winner['score'],
            margin: $margin,
            hostAligned: $hostAligned,
            category: (string) $decision['category'],
        );

        return [
            'detected' => $decision['label'],
            'slug' => $decision['slug'],
            'category' => $decision['category'],
            'version' => $decision['version'],
            'confidence' => $confidence,
            'confidence_label' => $this->confidenceLabel($confidence),
            'evidence' => $decision['evidence'],
            'candidates' => $candidates,
            'discarded' => $discarded,
            'context' => [
                'inspected_url' => $inspectedUrl,
                'final_url' => $finalUrl,
                'inspected_host' => $inspectedHost,
                'final_host' => $finalHost,
                'host_aligned' => $hostAligned,
            ],
        ];
    }

    /**
     * Quita prefijos de "espejo" (www., portal., web., home., con o sin sufijo numerico
     * en los primeros tres) para comparar hosts como el mismo sitio. Mismo patron que
     * EloquentSiteRepository::dashboardCanonicalDomainSql usa para deduplicar dominios.
     */
    private function canonicalHost(string $host): string
    {
        return (string) preg_replace('/^(?:(?:www\d*|portal\d*|web\d*|home)\.)+/i', '', $host);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, array<string, mixed>>  $knownFiles
     * @return array<string, mixed>
     */
    private function scoreDrupal(array $headers, string $body, string $bodyLower, array $knownFiles, string $generatorMeta): array
    {
        $rules = [
            $this->rule(
                key: 'meta_generator',
                score: 60,
                matched: str_contains(mb_strtolower($generatorMeta), 'drupal'),
                evidence: $generatorMeta !== '' ? ['meta generator: '.$generatorMeta] : [],
            ),
            $this->rule(
                key: 'x_generator',
                score: 45,
                matched: str_contains(mb_strtolower((string) ($headers['x-generator'] ?? '')), 'drupal'),
                evidence: isset($headers['x-generator']) ? ['x-generator: '.(string) $headers['x-generator']] : [],
            ),
            $this->rule(
                key: 'drupal_settings',
                score: 30,
                matched: str_contains($bodyLower, 'drupal-settings-json') || str_contains($bodyLower, 'drupalsettings'),
                evidence: $this->matchedEvidence($bodyLower, ['drupal-settings-json', 'drupalSettings']),
            ),
            $this->rule(
                key: 'core_path',
                score: 40,
                matched: str_contains($bodyLower, '/core/') || (($knownFiles['/core/CHANGELOG.txt']['status'] ?? 0) === 200),
                evidence: $this->collectEvidence([
                    str_contains($bodyLower, '/core/') ? 'html contiene /core/' : null,
                    (($knownFiles['/core/CHANGELOG.txt']['status'] ?? 0) === 200) ? 'probe /core/CHANGELOG.txt = 200' : null,
                ]),
            ),
            $this->rule(
                key: 'misc_drupal_js',
                score: 20,
                matched: str_contains($bodyLower, '/misc/drupal.js') || (($knownFiles['/core/misc/drupal.js']['status'] ?? 0) === 200),
                evidence: $this->collectEvidence([
                    str_contains($bodyLower, '/misc/drupal.js') ? 'html contiene /misc/drupal.js' : null,
                    (($knownFiles['/core/misc/drupal.js']['status'] ?? 0) === 200) ? 'probe /core/misc/drupal.js = 200' : null,
                ]),
            ),
            $this->rule(
                key: 'x_drupal_cache',
                score: 35,
                matched: isset($headers['x-drupal-cache']) && trim((string) $headers['x-drupal-cache']) !== '',
                evidence: isset($headers['x-drupal-cache']) ? ['x-drupal-cache: '.(string) $headers['x-drupal-cache']] : [],
            ),
            $this->rule(
                key: 'sites_default_files',
                score: 20,
                matched: str_contains($bodyLower, '/sites/default/files') || str_contains($bodyLower, '/sites/all/'),
                evidence: $this->collectEvidence([
                    str_contains($bodyLower, '/sites/default/files') ? 'html contiene /sites/default/files' : null,
                    str_contains($bodyLower, '/sites/all/') ? 'html contiene /sites/all/' : null,
                ]),
            ),
        ];

        return $this->candidate('drupal', 'Drupal', 'cms', $rules, $this->detectDrupalVersion($body, $knownFiles, (string) ($headers['x-generator'] ?? '')));
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, array<string, mixed>>  $knownFiles
     * @return array<string, mixed>
     */
    private function scoreWordPress(array $headers, string $body, string $bodyLower, array $knownFiles, string $generatorMeta): array
    {
        $wpJsonBody = mb_strtolower((string) ($knownFiles['/wp-json/']['body'] ?? ''));
        $restRouteBody = mb_strtolower((string) ($knownFiles['/?rest_route=/']['body'] ?? ''));

        $rules = [
            $this->rule(
                key: 'wp_content',
                score: 60,
                matched: str_contains($bodyLower, 'wp-content'),
                evidence: $this->matchedEvidence($bodyLower, ['wp-content']),
            ),
            $this->rule(
                key: 'wp_includes',
                score: 40,
                matched: str_contains($bodyLower, 'wp-includes'),
                evidence: $this->matchedEvidence($bodyLower, ['wp-includes']),
            ),
            $this->rule(
                key: 'wp_json',
                score: 40,
                matched: str_contains($bodyLower, 'wp-json')
                    || str_contains($bodyLower, 'api.w.org')
                    || $this->looksLikeWordPressRestResponse($wpJsonBody)
                    || $this->looksLikeWordPressRestResponse($restRouteBody),
                evidence: $this->collectEvidence([
                    str_contains($bodyLower, 'wp-json') ? 'html contiene wp-json' : null,
                    str_contains($bodyLower, 'api.w.org') ? 'html contiene api.w.org' : null,
                    $this->looksLikeWordPressRestResponse($wpJsonBody) ? 'probe /wp-json/ expone REST de WordPress' : null,
                    $this->looksLikeWordPressRestResponse($restRouteBody) ? 'probe /?rest_route=/ expone REST de WordPress' : null,
                ]),
            ),
            $this->rule(
                key: 'meta_generator',
                score: 30,
                matched: str_contains(mb_strtolower($generatorMeta), 'wordpress'),
                evidence: $generatorMeta !== '' ? ['meta generator: '.$generatorMeta] : [],
            ),
            $this->rule(
                key: 'x_pingback',
                score: 20,
                matched: str_contains(mb_strtolower((string) ($headers['x-pingback'] ?? '')), 'xmlrpc.php'),
                evidence: isset($headers['x-pingback']) ? ['x-pingback: '.(string) $headers['x-pingback']] : [],
            ),
            $this->rule(
                key: 'cookie',
                score: 25,
                matched: str_contains(mb_strtolower((string) ($headers['set-cookie'] ?? '')), 'wordpress_logged_in')
                    || str_contains(mb_strtolower((string) ($headers['set-cookie'] ?? '')), 'wp-settings'),
                evidence: $this->collectEvidence([
                    str_contains(mb_strtolower((string) ($headers['set-cookie'] ?? '')), 'wordpress_logged_in') ? 'cookie wordpress_logged_in' : null,
                    str_contains(mb_strtolower((string) ($headers['set-cookie'] ?? '')), 'wp-settings') ? 'cookie wp-settings' : null,
                ]),
            ),
            $this->rule(
                key: 'readme_probe',
                score: 15,
                matched: (($knownFiles['/readme.html']['status'] ?? 0) === 200),
                evidence: (($knownFiles['/readme.html']['status'] ?? 0) === 200) ? ['probe /readme.html = 200'] : [],
            ),
        ];

        return $this->candidate('wordpress', 'WordPress', 'cms', $rules, $this->detectWordPressVersion($body, $knownFiles, $generatorMeta));
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, array<string, mixed>>  $knownFiles
     * @return array<string, mixed>
     */
    private function scoreLaravel(array $headers, string $body, string $bodyLower, array $knownFiles): array
    {
        $setCookie = mb_strtolower((string) ($headers['set-cookie'] ?? ''));
        $xPoweredBy = mb_strtolower((string) ($headers['x-powered-by'] ?? ''));
        $cookieNames = $this->cookieNames((string) ($headers['set-cookie'] ?? ''));
        $hasCustomSessionCookie = $this->hasCustomLaravelSessionCookie($cookieNames);
        $hasXsrfCookie = str_contains($setCookie, 'xsrf-token');
        $hasCsrfMeta = preg_match('/<meta[^>]+name=["\']csrf-token["\']/i', $body) === 1;

        $rules = [
            $this->rule(
                key: 'laravel_session_cookie',
                score: 50,
                matched: str_contains($setCookie, 'laravel_session'),
                evidence: str_contains($setCookie, 'laravel_session') ? ['cookie laravel_session'] : [],
            ),
            $this->rule(
                key: 'xsrf_token_cookie',
                score: 15,
                matched: $hasXsrfCookie,
                evidence: $hasXsrfCookie ? ['cookie XSRF-TOKEN'] : [],
            ),
            $this->rule(
                key: 'csrf_meta',
                score: 15,
                matched: $hasCsrfMeta,
                evidence: $hasCsrfMeta ? ['meta csrf-token'] : [],
            ),
            $this->rule(
                key: 'custom_session_cookie',
                score: 40,
                matched: $hasCustomSessionCookie,
                evidence: $hasCustomSessionCookie ? ['cookie de sesión personalizada *_session'] : [],
            ),
            $this->rule(
                key: 'csrf_triplet',
                score: 20,
                matched: $hasCustomSessionCookie && $hasXsrfCookie && $hasCsrfMeta,
                evidence: ($hasCustomSessionCookie && $hasXsrfCookie && $hasCsrfMeta) ? ['combinación XSRF-TOKEN + csrf-token + *_session'] : [],
            ),
            $this->rule(
                key: 'inertia_marker',
                score: 30,
                matched: str_contains($bodyLower, 'inertia') || preg_match('/<title\s+inertia>/i', $body) === 1,
                evidence: $this->collectEvidence([
                    str_contains($bodyLower, 'inertia') ? 'html contiene inertia' : null,
                    preg_match('/<title\s+inertia>/i', $body) === 1 ? 'title inertia' : null,
                ]),
            ),
            $this->rule(
                key: 'vite_assets',
                score: 15,
                matched: str_contains($bodyLower, 'build/assets') || str_contains($bodyLower, '@vite') || str_contains($bodyLower, 'resources/js'),
                evidence: $this->collectEvidence([
                    str_contains($bodyLower, 'build/assets') ? 'html contiene build/assets' : null,
                    str_contains($bodyLower, '@vite') ? 'html contiene @vite' : null,
                    str_contains($bodyLower, 'resources/js') ? 'html contiene resources/js' : null,
                ]),
            ),
            $this->rule(
                key: 'livewire',
                score: 20,
                matched: str_contains($bodyLower, '/vendor/livewire') || str_contains($bodyLower, 'livewire'),
                evidence: $this->collectEvidence([
                    str_contains($bodyLower, '/vendor/livewire') ? 'html contiene /vendor/livewire' : null,
                    str_contains($bodyLower, 'livewire') ? 'html contiene livewire' : null,
                ]),
            ),
            $this->rule(
                key: 'x_powered_by_laravel',
                score: 60,
                matched: str_contains($xPoweredBy, 'laravel'),
                evidence: str_contains($xPoweredBy, 'laravel') ? ['x-powered-by: '.(string) $headers['x-powered-by']] : [],
            ),
            $this->rule(
                key: 'vendor_laravel_path',
                score: 25,
                matched: str_contains($bodyLower, '/vendor/laravel') || str_contains($bodyLower, '/sanctum/csrf-cookie'),
                evidence: $this->collectEvidence([
                    str_contains($bodyLower, '/vendor/laravel') ? 'html contiene /vendor/laravel' : null,
                    str_contains($bodyLower, '/sanctum/csrf-cookie') ? 'html contiene /sanctum/csrf-cookie' : null,
                ]),
            ),
        ];

        return $this->candidate('laravel', 'Laravel', 'cms', $rules, $this->detectLaravelVersion((string) ($headers['x-powered-by'] ?? ''), $body));
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, array<string, mixed>>  $knownFiles
     * @return array<string, mixed>
     */
    private function scoreJoomla(array $headers, string $body, string $bodyLower, array $knownFiles, string $generatorMeta): array
    {
        $rules = [
            $this->rule(
                key: 'meta_generator',
                score: 30,
                matched: str_contains(mb_strtolower($generatorMeta), 'joomla'),
                evidence: $generatorMeta !== '' ? ['meta generator: '.$generatorMeta] : [],
            ),
            $this->rule(
                key: 'media_system_js',
                score: 35,
                matched: str_contains($bodyLower, '/media/system/js'),
                evidence: $this->matchedEvidence($bodyLower, ['/media/system/js']),
            ),
            $this->rule(
                key: 'com_content',
                score: 30,
                matched: str_contains($bodyLower, 'com_content'),
                evidence: $this->matchedEvidence($bodyLower, ['com_content']),
            ),
            $this->rule(
                key: 'cookie',
                score: 25,
                matched: str_contains(mb_strtolower((string) ($headers['set-cookie'] ?? '')), 'joomla_')
                    || str_contains(mb_strtolower((string) ($headers['set-cookie'] ?? '')), 'jfcookie'),
                evidence: $this->collectEvidence([
                    str_contains(mb_strtolower((string) ($headers['set-cookie'] ?? '')), 'joomla_') ? 'cookie joomla_' : null,
                    str_contains(mb_strtolower((string) ($headers['set-cookie'] ?? '')), 'jfcookie') ? 'cookie jfcookie' : null,
                ]),
            ),
            $this->rule(
                key: 'joomla_manifest_probe',
                score: 45,
                matched: (($knownFiles['/administrator/manifests/files/joomla.xml']['status'] ?? 0) === 200)
                    || (($knownFiles['/language/en-GB/en-GB.xml']['status'] ?? 0) === 200),
                evidence: $this->collectEvidence([
                    (($knownFiles['/administrator/manifests/files/joomla.xml']['status'] ?? 0) === 200) ? 'probe /administrator/manifests/files/joomla.xml = 200' : null,
                    (($knownFiles['/language/en-GB/en-GB.xml']['status'] ?? 0) === 200) ? 'probe /language/en-GB/en-GB.xml = 200' : null,
                ]),
            ),
        ];

        return $this->candidate('joomla', 'Joomla', 'cms', $rules, $this->detectJoomlaVersion($body, $knownFiles, $generatorMeta));
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, array<string, mixed>>  $knownFiles
     * @param  array<int, string>  $cookieNames
     * @return array<string, mixed>
     */
    private function scorePhp(array $headers, string $body, string $bodyLower, array $knownFiles, array $cookieNames): array
    {
        $xPoweredBy = (string) ($headers['x-powered-by'] ?? '');

        $rules = [
            $this->rule(
                key: 'x_powered_by_php',
                score: 35,
                matched: preg_match('/php\/?\s*([0-9]+(?:\.[0-9]+){0,2})/i', $xPoweredBy) === 1,
                evidence: preg_match('/php\/?\s*([0-9]+(?:\.[0-9]+){0,2})/i', $xPoweredBy) === 1 ? ['x-powered-by: '.$xPoweredBy] : [],
            ),
            $this->rule(
                key: 'phpsessid_cookie',
                score: 35,
                matched: in_array('phpsessid', $cookieNames, true),
                evidence: in_array('phpsessid', $cookieNames, true) ? ['cookie PHPSESSID'] : [],
            ),
            $this->rule(
                key: 'php_asset_or_route',
                score: 25,
                matched: preg_match('/\.php(?:[?#"\'])/i', $body) === 1 || str_contains($bodyLower, 'index.php'),
                evidence: $this->collectEvidence([
                    preg_match('/\.php(?:[?#"\'])/i', $body) === 1 ? 'html referencia .php' : null,
                    str_contains($bodyLower, 'index.php') ? 'html contiene index.php' : null,
                ]),
            ),
            $this->rule(
                key: 'php_probe_files',
                score: 10,
                matched: (($knownFiles['/robots.txt']['status'] ?? 0) === 200) || (($knownFiles['/license.txt']['status'] ?? 0) === 200),
                evidence: $this->collectEvidence([
                    (($knownFiles['/robots.txt']['status'] ?? 0) === 200) ? 'probe /robots.txt = 200' : null,
                    (($knownFiles['/license.txt']['status'] ?? 0) === 200) ? 'probe /license.txt = 200' : null,
                ]),
            ),
        ];

        return $this->candidate('php', 'PHP', 'runtime', $rules, $this->detectPhpVersion($xPoweredBy));
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function scoreWix(array $headers, string $bodyLower): array
    {
        $rules = [
            $this->rule(
                key: 'wix_static_assets',
                score: 60,
                matched: str_contains($bodyLower, 'wixstatic.com') || str_contains($bodyLower, 'static.parastorage.com'),
                evidence: $this->matchedEvidence($bodyLower, ['wixstatic.com', 'static.parastorage.com']),
            ),
            $this->rule(
                key: 'wix_site_marker',
                score: 40,
                matched: str_contains($bodyLower, 'wixsite.com') || str_contains($bodyLower, 'wix-code') || str_contains($bodyLower, '"wix.com"') || str_contains($bodyLower, 'wix-bolt'),
                evidence: $this->matchedEvidence($bodyLower, ['wixsite.com', 'wix-code', 'wix-bolt']),
            ),
            $this->rule(
                key: 'wix_headers',
                score: 45,
                matched: isset($headers['x-wix-request-id']) || isset($headers['x-wix-punisher']),
                evidence: $this->collectEvidence([
                    isset($headers['x-wix-request-id']) ? 'x-wix-request-id presente' : null,
                    isset($headers['x-wix-punisher']) ? 'x-wix-punisher presente' : null,
                ]),
            ),
        ];

        return $this->candidate('wix', 'Wix', 'cms', $rules, null);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function scoreNextjs(array $headers, string $bodyLower): array
    {
        $rules = [
            $this->rule(
                key: 'vercel_server_header',
                score: 55,
                matched: str_contains(mb_strtolower((string) ($headers['server'] ?? '')), 'vercel'),
                evidence: isset($headers['server']) ? ['server: '.$headers['server']] : [],
            ),
            $this->rule(
                key: 'vercel_response_headers',
                score: 40,
                matched: isset($headers['x-vercel-id']) || isset($headers['x-vercel-cache']),
                evidence: $this->collectEvidence([
                    isset($headers['x-vercel-id']) ? 'x-vercel-id presente' : null,
                    isset($headers['x-vercel-cache']) ? 'x-vercel-cache presente' : null,
                ]),
            ),
            $this->rule(
                key: 'nextjs_static_assets',
                score: 35,
                matched: str_contains($bodyLower, '_next/static') || str_contains($bodyLower, '__next_data__'),
                evidence: $this->matchedEvidence($bodyLower, ['_next/static', '__next_data__']),
            ),
        ];

        return $this->candidate('nextjs', 'Next.js (Vercel)', 'runtime', $rules, null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function candidate(string $slug, string $label, string $category, array $rules, ?string $version): array
    {
        $score = 0;
        $evidence = [];
        $matchedRules = 0;

        foreach ($rules as $rule) {
            if (($rule['matched'] ?? false) !== true) {
                continue;
            }

            $score += (int) ($rule['score'] ?? 0);
            $matchedRules++;

            foreach ((array) ($rule['evidence'] ?? []) as $item) {
                $evidence[] = (string) $item;
            }
        }

        return [
            'slug' => $slug,
            'label' => $label,
            'category' => $category,
            'score' => $score,
            'matched_rules' => $matchedRules,
            'version' => $version,
            'rules' => $rules,
            'evidence' => array_values(array_unique($evidence)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDecision(array $winner, ?array $runnerUp, bool $hostAligned, string $finalUrl): array
    {
        $winnerScore = (int) ($winner['score'] ?? 0);
        $runnerUpScore = is_array($runnerUp) ? (int) ($runnerUp['score'] ?? 0) : 0;
        $margin = $winnerScore - $runnerUpScore;

        if ($winnerScore < self::DETECTION_THRESHOLD) {
            return [
                'slug' => 'no-determinado',
                'label' => 'No determinado',
                'category' => 'unknown',
                'version' => null,
                'evidence' => [],
                'reason' => 'La mejor puntuación quedó por debajo del umbral de detección.',
            ];
        }

        if ($margin < self::DECISIVE_MARGIN) {
            return [
                'slug' => 'no-determinado',
                'label' => 'No determinado',
                'category' => 'unknown',
                'version' => null,
                'evidence' => [],
                'reason' => 'La diferencia entre la mejor y la segunda mejor tecnología es insuficiente.',
            ];
        }

        if (! $hostAligned) {
            return [
                'slug' => 'no-determinado',
                'label' => 'No determinado',
                'category' => 'unknown',
                'version' => null,
                'evidence' => [],
                'reason' => 'La respuesta final proviene de un host distinto: '.$finalUrl,
            ];
        }

        return [
            'slug' => (string) $winner['slug'],
            'label' => (string) $winner['label'],
            'category' => (string) $winner['category'],
            'version' => $winner['version'],
            'evidence' => $winner['evidence'],
            'reason' => 'Tecnología ganadora por puntuación acumulada.',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function discardedCandidates(array $candidates, array $decision, int $margin): array
    {
        $discarded = [];

        foreach ($candidates as $candidate) {
            $reason = null;

            if ((string) ($decision['slug'] ?? '') === 'no-determinado') {
                $reason = (string) ($decision['reason'] ?? 'Descartado por decisión final.');
            } elseif ((string) ($candidate['slug'] ?? '') === (string) ($decision['slug'] ?? '')) {
                $reason = 'Tecnología seleccionada.';
            } elseif ((int) ($candidate['score'] ?? 0) === 0) {
                $reason = 'No encontró evidencia suficiente.';
            } elseif ($margin < self::DECISIVE_MARGIN) {
                $reason = 'Empate técnico con la tecnología ganadora.';
            } else {
                $reason = 'Puntuación inferior a '.(string) ($decision['label'] ?? 'la tecnología ganadora').'.';
            }

            $discarded[] = [
                'technology' => (string) ($candidate['label'] ?? 'No determinado'),
                'score' => (int) ($candidate['score'] ?? 0),
                'reason' => $reason,
                'evidence' => $candidate['evidence'] ?? [],
            ];
        }

        return $discarded;
    }

    private function confidenceScore(int $winningScore, int $margin, bool $hostAligned, string $category): int
    {
        if ($category === 'unknown') {
            return 0;
        }

        $confidence = min(99, max(1, $winningScore));
        $confidence += min(12, max(0, $margin / 2));

        if (! $hostAligned) {
            $confidence -= 25;
        }

        return max(1, min(99, (int) round($confidence)));
    }

    private function confidenceLabel(int $confidence): string
    {
        if ($confidence >= 90) {
            return 'high';
        }

        if ($confidence >= 70) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(string $key, int $score, bool $matched, array $evidence): array
    {
        return [
            'heuristic' => $key,
            'score' => $score,
            'matched' => $matched,
            'evidence' => $matched ? array_values(array_unique($evidence)) : [],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function cookieNames(string $setCookie): array
    {
        if ($setCookie === '') {
            return [];
        }

        preg_match_all('/(?:^|,|\s)([A-Za-z0-9_\-]+)=/m', $setCookie, $matches);

        return array_values(array_unique(array_map(static fn (string $value): string => mb_strtolower(trim($value)), (array) ($matches[1] ?? []))));
    }

    private function extractGeneratorMeta(string $body): string
    {
        if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $matches) !== 1) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''));
    }

    /**
     * @param  array<int, string|null>  $items
     * @return array<int, string>
     */
    private function collectEvidence(array $items): array
    {
        return array_values(array_filter(array_map(static fn (?string $item): string => trim((string) $item), $items), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  array<int, string>  $needles
     * @return array<int, string>
     */
    private function matchedEvidence(string $haystack, array $needles): array
    {
        $matched = [];

        foreach ($needles as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                $matched[] = $needle;
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, array<string, mixed>>  $knownFiles
     */
    private function detectDrupalVersion(string $body, array $knownFiles, string $xGenerator): ?string
    {
        $version = $this->matchVersion($xGenerator, '/drupal\s*([0-9]+(?:\.[0-9]+){0,2})/i');

        if ($version !== null) {
            return $version;
        }

        $version = $this->matchVersion($body, '/drupal\s*([0-9]+(?:\.[0-9]+){0,2})/i');

        if ($version !== null) {
            return $version;
        }

        $changelog = (string) ($knownFiles['/core/CHANGELOG.txt']['body'] ?? $knownFiles['/CHANGELOG.txt']['body'] ?? '');

        return $this->matchVersion($changelog, '/drupal\s+([0-9]+(?:\.[0-9]+){0,2})/i');
    }

    /**
     * @param  array<string, array<string, mixed>>  $knownFiles
     */
    private function detectWordPressVersion(string $body, array $knownFiles, string $generatorMeta): ?string
    {
        $version = $this->matchVersion($generatorMeta, '/wordpress\s*([0-9]+(?:\.[0-9]+){0,2})/i');

        if ($version !== null) {
            return $version;
        }

        $readme = (string) ($knownFiles['/readme.html']['body'] ?? '');

        return $this->matchVersion($readme, '/version\s+([0-9]+(?:\.[0-9]+){0,2})/i');
    }

    private function detectLaravelVersion(string $xPoweredBy, string $body): ?string
    {
        $version = $this->matchVersion($xPoweredBy, '/laravel(?:\s+framework|\s+v|\/)?\s*([0-9]+(?:\.[0-9]+){0,2})/i');

        return $version ?? $this->matchVersion($body, '/laravel(?:\s+framework|\s+v|\/)?\s*([0-9]+(?:\.[0-9]+){0,2})/i');
    }

    /**
     * @param  array<string, array<string, mixed>>  $knownFiles
     */
    private function detectJoomlaVersion(string $body, array $knownFiles, string $generatorMeta): ?string
    {
        $version = $this->matchVersion($generatorMeta, '/joomla!?\s*([0-9]+(?:\.[0-9]+){0,2})/i');

        if ($version !== null) {
            return $version;
        }

        $license = (string) ($knownFiles['/license.txt']['body'] ?? '');

        if ($license !== '') {
            $version = $this->matchVersion($license, '/joomla!?\s*([0-9]+(?:\.[0-9]+){0,2})/i');

            if ($version !== null) {
                return $version;
            }
        }

        $manifest = (string) ($knownFiles['/administrator/manifests/files/joomla.xml']['body'] ?? '');

        return $this->matchVersion($manifest, '/<version>\s*([0-9]+(?:\.[0-9]+){0,2})\s*<\/version>/i');
    }

    private function detectPhpVersion(string $xPoweredBy): ?string
    {
        return $this->matchVersion($xPoweredBy, '/php\/?\s*([0-9]+(?:\.[0-9]+){0,2})/i');
    }

    private function looksLikeWordPressRestResponse(string $body): bool
    {
        if ($body === '') {
            return false;
        }

        $trimmed = ltrim($body);

        if ($trimmed === '' || ! in_array($trimmed[0], ['{', '['], true)) {
            return false;
        }

        return str_contains($trimmed, '"routes"')
            || str_contains($trimmed, '"namespaces"')
            || str_contains($trimmed, 'https://api.w.org/')
            || str_contains($trimmed, '"wp/v2"');
    }

    /**
     * @param  array<int, string>  $cookieNames
     */
    private function hasCustomLaravelSessionCookie(array $cookieNames): bool
    {
        foreach ($cookieNames as $cookieName) {
            if ($cookieName === 'laravel_session') {
                return true;
            }

            if (str_ends_with($cookieName, '_session') && $cookieName !== 'phpsessid') {
                return true;
            }
        }

        return false;
    }

    private function matchVersion(string $value, string $pattern): ?string
    {
        if ($value === '' || preg_match($pattern, $value, $matches) !== 1) {
            return null;
        }

        $version = trim((string) ($matches[1] ?? ''));

        return $version !== '' ? $version : null;
    }
}
