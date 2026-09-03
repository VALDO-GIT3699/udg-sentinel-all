<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services\SiteInspection;

use App\Models\Site;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\TransferStats;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;
use Modules\Monitoring\Support\HttpFailureClassifier;
use Psr\Http\Message\ResponseInterface;

final class VerticalSiteInspectionEngine
{
    private const SECURITY_HEADERS = [
        'strict-transport-security',
        'content-security-policy',
        'x-frame-options',
        'x-content-type-options',
        'referrer-policy',
        'permissions-policy',
    ];

    private const KNOWN_FILES = [
        '/robots.txt',
        '/CHANGELOG.txt',
        '/core/CHANGELOG.txt',
        '/core/misc/drupal.js',
        '/wp-json/',
        '/?rest_route=/',
        '/readme.html',
        '/license.txt',
        '/administrator/manifests/files/joomla.xml',
        '/language/en-GB/en-GB.xml',
    ];

    public function __construct(
        private readonly MonitoringHttpClientFactory $httpClientFactory,
        private readonly TechnologyFingerprintScorer $technologyFingerprintScorer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function inspectTarget(string $target): array
    {
        $inspection = $this->runInspection($target, 'default');
        $dns = $inspection['dns'];
        $http = $inspection['http'];
        $ssl = $inspection['ssl'];
        $headers = $inspection['security_headers'];
        $fingerprint = $inspection['fingerprint'];

        $risk = $this->calculateRiskLevel(
            httpStatus: $http['status'],
            sslValid: (bool) ($ssl['valid'] ?? false),
            headersScore: (string) ($headers['score'] ?? 'D'),
            cmsDetected: (string) ($fingerprint['cms'] ?? 'No determinado') !== 'No determinado',
        );

        return [
            'dns' => [
                'a' => $dns['records']['a'] ?? [],
                'cname' => $dns['records']['cname'] ?? [],
            ],
            'http' => [
                'status' => $http['status'],
                'redirects' => $http['redirects'],
                'latency_ms' => $http['latency_ms'],
            ],
            'ssl' => [
                'issuer' => $ssl['issuer'] ?? 'No determinado',
                'expires_at' => $ssl['expires_at'] ?? null,
                'days_left' => $ssl['expires_in_days'] ?? null,
                'valid' => (bool) ($ssl['valid'] ?? false),
            ],
            'headers' => [
                'score' => $headers['score'] ?? 'N/A',
                'missing' => $headers['missing'] ?? [],
            ],
            'technology' => [
                'detected' => $fingerprint['detected_technology'] ?? 'No determinado',
                'category' => $fingerprint['detected_category'] ?? 'unknown',
                'confidence' => $fingerprint['technology_confidence'] ?? 0,
                'evidence' => $fingerprint['technology_evidence'] ?? [],
                'cms' => $fingerprint['cms'] ?? 'No determinado',
                'cms_version' => $fingerprint['cms_version'] ?? 'No determinado',
                'php_version' => $fingerprint['php_version'] ?? 'No determinado',
                'server' => $fingerprint['server'] ?? 'No determinado',
                'frameworks' => $fingerprint['frameworks'] ?? [],
            ],
            'risk_score' => $risk,
            'instrumentation' => $inspection['instrumentation'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(Site $site): array
    {
        $target = (string) ($site->url !== '' ? $site->url : $site->domain);
        $inspection = $this->runInspection($target, 'mass_scan');
        $dns = $inspection['dns'];
        $http = $inspection['http'];
        $ssl = $inspection['ssl'];
        $headers = $inspection['security_headers'];
        $body = $inspection['body'];
        $fingerprint = $inspection['fingerprint'];

        $analysisErrors = [];

        if (($dns['status'] ?? 'error') !== 'ok') {
            $analysisErrors[] = sprintf('dns:%s', (string) ($dns['error'] ?? 'error'));
        }

        if (! is_int($http['status']) || $http['status'] <= 0) {
            $analysisErrors[] = 'http:no-connection';
        }

        if (($ssl['status'] ?? 'error') !== 'ok') {
            $analysisErrors[] = sprintf('ssl:%s', (string) ($ssl['error'] ?? 'error'));
        }

        $riskScoreMap = [
            'LOW' => 10,
            'MEDIUM' => 45,
            'HIGH' => 75,
            'CRITICAL' => 95,
        ];

        $riskLabelMap = [
            'LOW' => 'Low',
            'MEDIUM' => 'Medio',
            'HIGH' => 'Alto',
            'CRITICAL' => 'Crítico',
        ];

        $riskCode = (string) ($inspection['risk_code'] ?? 'CRITICAL');
        $riskScore = (int) ($riskScoreMap[$riskCode] ?? 95);
        $riskLabel = (string) ($riskLabelMap[$riskCode] ?? 'Crítico');

        $essentialChecksComplete = ($dns['status'] ?? 'error') === 'ok'
            && is_int($http['status'])
            && ($headers['status'] ?? 'error') === 'ok'
            && ($fingerprint['status'] ?? 'error') === 'ok';

        return [
            'domain' => (string) $site->domain,
            'is_active' => (bool) $site->is_active,
            'dns' => $dns,
            'http' => [
                'status_code' => $http['status'],
                'error' => $http['error'],
            ],
            'https' => [
                'status_code' => $http['status'],
                'error' => $http['error'],
            ],
            'redirect_chain' => $http['redirects'],
            'response_time_ms' => $http['latency_ms'],
            'ttfb_ms' => $http['ttfb_ms'],
            'ssl' => $ssl,
            'security_headers' => $headers,
            'body' => [
                'status' => $body['status'],
                'error' => $body['error'],
                'length' => $body['length'],
            ],
            'fingerprint' => $fingerprint,
            'cms' => [
                'name' => $fingerprint['cms'],
                'version' => $fingerprint['cms_version'],
                'confidence' => $fingerprint['cms_confidence'],
                'confidence_score' => $fingerprint['technology_confidence'],
                'evidence' => $fingerprint['technology_evidence'],
            ],
            'detected_technology' => $fingerprint['detected_technology'],
            'detected_technology_category' => $fingerprint['detected_category'],
            'server' => $fingerprint['server'],
            'runtime' => [
                'name' => $fingerprint['runtime_name'],
                'version' => $fingerprint['php_version'],
            ],
            'js_frameworks' => $fingerprint['frameworks'],
            'risk_score' => $riskScore,
            'risk_level' => $riskLabel,
            'essential_checks_complete' => $essentialChecksComplete,
            'analysis_errors' => $analysisErrors,
            'instrumentation' => $inspection['instrumentation'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectDebugTarget(string $target): array
    {
        $inspection = $this->runInspection($target, 'default');

        return [
            'resolved' => $inspection['resolved'],
            'http' => [
                'status' => $inspection['http']['status'],
                'error' => $inspection['http']['error'],
                'latency_ms' => $inspection['http']['latency_ms'],
                'final_url' => $inspection['http']['final_url'],
                'redirects' => $inspection['http']['redirects'],
            ],
            'ssl' => $inspection['ssl'],
            'instrumentation' => $inspection['instrumentation'],
            'fingerprint_debug' => $inspection['fingerprint']['debug'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runInspection(string $target, string $profile): array
    {
        $inspectionStartedAt = microtime(true);
        $resolved = $this->resolveTarget($target);
        $domain = $resolved['domain'];
        $url = $resolved['url'];

        $instrumentation = [
            'http_requests' => 0,
            'dns_ms' => 0,
            'ssl_ms' => 0,
            'fingerprint_ms' => 0,
            'known_files_ms' => 0,
            'total_ms' => 0,
        ];

        $dnsStartedAt = microtime(true);
        $dns = $this->inspectDns($domain);
        $instrumentation['dns_ms'] = (int) round((microtime(true) - $dnsStartedAt) * 1000);

        $client = $this->httpClientFactory->createGuzzleClient([
            'Accept' => 'text/html,*/*;q=0.8',
        ], $profile);

        $http = $this->inspectHttp($client, $url, $instrumentation, $profile);

        $sslStartedAt = microtime(true);
        $ssl = $this->inspectSsl((string) ($http['final_url'] ?? $url), $profile);
        $instrumentation['ssl_ms'] = (int) round((microtime(true) - $sslStartedAt) * 1000);

        $headers = $this->inspectSecurityHeaders($http['headers']);
        $body = $this->inspectBody((string) ($http['html'] ?? ''), (bool) ($http['has_response'] ?? false));

        $knownFiles = [];

        if ((bool) ($http['has_response'] ?? false)) {
            // Si la carga principal solo respondio tras relajar la verificacion TLS
            // (certificado autofirmado/vencido), las sondas de known-files deben usar
            // ese mismo cliente relajado; de lo contrario cada probe falla por el
            // mismo problema de certificado y el fingerprint se queda sin evidencia.
            $knownFilesClient = (bool) ($http['tls_issue_detected'] ?? false)
                ? $this->httpClientFactory->createGuzzleClient(['Accept' => 'text/html,*/*;q=0.8'], $profile, true)
                : $client;

            $knownFiles = $this->inspectKnownFilesParallel($knownFilesClient, (string) ($http['final_url'] ?? $url), $instrumentation);
        }

        $fingerprintStartedAt = microtime(true);
        $fingerprint = $this->fingerprint(
            headers: $http['headers'],
            body: (string) ($body['html'] ?? ''),
            knownFiles: $knownFiles,
            inspectedUrl: $url,
            finalUrl: (string) ($http['final_url'] ?? $url),
            httpStatus: $http['status'],
        );
        $instrumentation['fingerprint_ms'] = (int) round((microtime(true) - $fingerprintStartedAt) * 1000);

        $riskCode = $this->calculateRiskLevel(
            httpStatus: $http['status'],
            sslValid: (bool) ($ssl['valid'] ?? false),
            headersScore: (string) ($headers['score'] ?? 'D'),
            cmsDetected: (string) ($fingerprint['cms'] ?? 'No determinado') !== 'No determinado',
        );

        $instrumentation['total_ms'] = (int) round((microtime(true) - $inspectionStartedAt) * 1000);

        return [
            'resolved' => $resolved,
            'dns' => $dns,
            'http' => $http,
            'ssl' => $ssl,
            'security_headers' => $headers,
            'body' => $body,
            'known_files' => $knownFiles,
            'fingerprint' => $fingerprint,
            'risk_code' => $riskCode,
            'instrumentation' => $instrumentation,
        ];
    }

    /**
     * @return array{domain:string,url:string}
     */
    private function resolveTarget(string $target): array
    {
        $value = trim($target);

        if ($value === '') {
            return [
                'domain' => '',
                'url' => 'https://invalid.local',
            ];
        }

        if (! str_starts_with(mb_strtolower($value), 'http://') && ! str_starts_with(mb_strtolower($value), 'https://')) {
            $value = 'https://'.ltrim($value, '/');
        }

        $parts = parse_url($value);
        $host = trim((string) ($parts['host'] ?? ''));

        return [
            'domain' => mb_strtolower($host),
            'url' => $value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectDns(string $domain): array
    {
        $host = trim(mb_strtolower($domain));

        if ($host === '') {
            return [
                'status' => 'error',
                'error' => 'Domain vacío',
                'records' => ['a' => [], 'aaaa' => [], 'cname' => []],
            ];
        }

        try {
            $a = dns_get_record($host, DNS_A) ?: [];
            $aaaa = dns_get_record($host, DNS_AAAA) ?: [];
            $cname = dns_get_record($host, DNS_CNAME) ?: [];

            return [
                'status' => ($a !== [] || $aaaa !== [] || $cname !== []) ? 'ok' : 'no_records',
                'error' => ($a !== [] || $aaaa !== [] || $cname !== []) ? null : 'No DNS records found',
                'records' => [
                    'a' => array_values(array_filter(array_map(static fn (array $row): ?string => isset($row['ip']) ? (string) $row['ip'] : null, $a))),
                    'aaaa' => array_values(array_filter(array_map(static fn (array $row): ?string => isset($row['ipv6']) ? (string) $row['ipv6'] : null, $aaaa))),
                    'cname' => array_values(array_filter(array_map(static fn (array $row): ?string => isset($row['target']) ? (string) $row['target'] : null, $cname))),
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'error' => $exception->getMessage(),
                'records' => ['a' => [], 'aaaa' => [], 'cname' => []],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectHttp(Client $client, string $url, array &$instrumentation, string $profile): array
    {
        $primaryUrl = $this->normalizeUrl($url, true);
        $primaryProbe = $this->probeUrl($client, $primaryUrl, $instrumentation);
        $tlsIssueDetected = false;

        if (! $primaryProbe['has_response'] && ($primaryProbe['failure_type'] ?? null) === HttpFailureClassifier::TYPE_TLS) {
            // Certificado invalido/expirado/no confiable: reintentamos con verificacion TLS
            // relajada antes de asumir que el sitio no responde.
            $relaxedClient = $this->httpClientFactory->createGuzzleClient(['Accept' => 'text/html,*/*;q=0.8'], $profile, true);
            $relaxedProbe = $this->probeUrl($relaxedClient, $primaryUrl, $instrumentation);

            if ($relaxedProbe['has_response']) {
                $tlsIssueDetected = true;
                $primaryProbe = $relaxedProbe;
            }
        }

        if (! $primaryProbe['has_response'] && str_starts_with(mb_strtolower($primaryUrl), 'https://')) {
            $httpUrl = preg_replace('#^https://#i', 'http://', $primaryUrl) ?: $primaryUrl;
            $fallbackProbe = $this->probeUrl($client, $httpUrl, $instrumentation);

            if ($fallbackProbe['has_response']) {
                $primaryProbe = $fallbackProbe;
            }
        }

        // Algunos hosts institucionales sirven, solo en HTTPS, una pagina placeholder
        // que hace un meta-refresh a otro dominio (migracion de SSL incompleta), mientras
        // el sitio real sigue viviendo en HTTP. El status/SSL que se reporta sigue siendo
        // el de HTTPS (es real), pero el fingerprint usa el HTML de HTTP si tiene mas
        // contenido, para no perder evidencia de CMS que solo aparece ahi.
        $fingerprintBody = (string) ($primaryProbe['body'] ?? '');
        $fingerprintHeaders = $primaryProbe['headers'];

        if (
            $primaryProbe['has_response']
            && str_starts_with(mb_strtolower($primaryUrl), 'https://')
            && $this->isMetaRefreshPlaceholder($fingerprintBody)
        ) {
            $httpUrl = preg_replace('#^https://#i', 'http://', $primaryUrl) ?: $primaryUrl;
            $httpProbe = $this->probeUrl($client, $httpUrl, $instrumentation);
            $httpBody = (string) ($httpProbe['body'] ?? '');

            if ($httpProbe['has_response'] && ! $this->isMetaRefreshPlaceholder($httpBody)) {
                $fingerprintBody = $httpBody;
                $fingerprintHeaders = $httpProbe['headers'];
            }
        }

        return [
            'status' => $primaryProbe['status_code'],
            'error' => $primaryProbe['error'],
            'latency_ms' => $primaryProbe['response_time_ms'],
            'ttfb_ms' => $primaryProbe['ttfb_ms'],
            'redirects' => $primaryProbe['redirect_chain'],
            'final_url' => $primaryProbe['effective_url'] ?? $primaryUrl,
            'headers' => $fingerprintHeaders,
            'html' => $fingerprintBody,
            'has_response' => $primaryProbe['has_response'],
            'tls_issue_detected' => $tlsIssueDetected,
        ];
    }

    /**
     * Detecta una pagina placeholder de meta-refresh: HTML minimo cuyo unico proposito
     * es redirigir el navegador a otro dominio (comun cuando un host solo tiene HTTPS
     * configurado a medias). No aporta evidencia real sobre la tecnologia del sitio.
     */
    private function isMetaRefreshPlaceholder(string $body): bool
    {
        $trimmed = trim($body);

        if ($trimmed === '' || mb_strlen($trimmed) > 600) {
            return false;
        }

        if (preg_match('/<meta[^>]+http-equiv=["\']refresh["\']/i', $trimmed) !== 1) {
            return false;
        }

        return preg_match('/<body[^>]*>\s*<\/body>/i', $trimmed) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function probeUrl(Client $client, string $url, array &$instrumentation): array
    {
        $started = microtime(true);
        $transferTimeMs = null;
        $effectiveUrl = $url;
        $instrumentation['http_requests']++;

        try {
            $response = $client->request('GET', $url, [
                'headers' => ['Accept' => 'text/html,*/*;q=0.8'],
                'on_stats' => static function (TransferStats $stats) use (&$transferTimeMs, &$effectiveUrl): void {
                    $transferTimeMs = (int) round($stats->getTransferTime() * 1000);
                    $uri = $stats->getEffectiveUri();

                    if ($uri !== null) {
                        $effectiveUrl = (string) $uri;
                    }
                },
            ]);
            $elapsed = $transferTimeMs ?? (int) round((microtime(true) - $started) * 1000);

            return [
                'status_code' => (int) $response->getStatusCode(),
                'error' => null,
                'response_time_ms' => $elapsed,
                'ttfb_ms' => $elapsed,
                'redirect_chain' => $this->extractRedirectChain($response),
                'effective_url' => $effectiveUrl,
                'headers' => $this->normalizeHeaders($response->getHeaders()),
                'body' => (string) $response->getBody(),
                'has_response' => true,
            ];
        } catch (RequestException $exception) {
            $elapsed = $transferTimeMs ?? (int) round((microtime(true) - $started) * 1000);
            $response = $exception->getResponse();

            if ($response instanceof ResponseInterface) {
                return [
                    'status_code' => (int) $response->getStatusCode(),
                    'error' => null,
                    'response_time_ms' => $elapsed,
                    'ttfb_ms' => $elapsed,
                    'redirect_chain' => $this->extractRedirectChain($response),
                    'effective_url' => $effectiveUrl,
                    'headers' => $this->normalizeHeaders($response->getHeaders()),
                    'body' => (string) $response->getBody(),
                    'has_response' => true,
                ];
            }

            return [
                'status_code' => null,
                'error' => $exception->getMessage(),
                'response_time_ms' => $elapsed,
                'ttfb_ms' => $elapsed,
                'redirect_chain' => [],
                'effective_url' => $effectiveUrl,
                'headers' => [],
                'body' => '',
                'has_response' => false,
                'failure_type' => HttpFailureClassifier::classify($exception),
            ];
        } catch (\Throwable $exception) {
            $elapsed = (int) round((microtime(true) - $started) * 1000);

            return [
                'status_code' => null,
                'error' => $exception->getMessage(),
                'response_time_ms' => $elapsed,
                'ttfb_ms' => $elapsed,
                'redirect_chain' => [],
                'effective_url' => $url,
                'headers' => [],
                'body' => '',
                'has_response' => false,
                'failure_type' => HttpFailureClassifier::classify($exception),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectSsl(string $url, string $profile): array
    {
        if (! str_starts_with(mb_strtolower($url), 'https://')) {
            return [
                'status' => 'skipped',
                'error' => 'URL no HTTPS',
                'valid' => false,
                'issuer' => 'No determinado',
                'expires_at' => null,
                'expires_in_days' => null,
                'algorithm' => 'No determinado',
                'grade' => 'N/A',
            ];
        }

        $parsed = parse_url($url);
        $host = (string) ($parsed['host'] ?? '');
        $port = (int) ($parsed['port'] ?? 443);

        if ($host === '') {
            return [
                'status' => 'error',
                'error' => 'Host inválido para SSL',
                'valid' => false,
                'issuer' => 'No determinado',
                'expires_at' => null,
                'expires_in_days' => null,
                'algorithm' => 'No determinado',
                'grade' => 'N/A',
            ];
        }

        try {
            $sslTimeoutSeconds = $profile === 'mass_scan'
                ? max(1, (int) env('SENTINEL_MASS_SCAN_SSL_TIMEOUT', 3))
                : 8;

            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'SNI_enabled' => true,
                    'peer_name' => $host,
                ],
            ]);

            $socket = @stream_socket_client(
                sprintf('ssl://%s:%d', $host, $port),
                $errno,
                $errstr,
                $sslTimeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if (! is_resource($socket)) {
                return [
                    'status' => 'timeout',
                    'error' => $errstr !== '' ? $errstr : 'Connection refused',
                    'valid' => false,
                    'issuer' => 'No determinado',
                    'expires_at' => null,
                    'expires_in_days' => null,
                    'algorithm' => 'No determinado',
                    'grade' => 'F',
                ];
            }

            $params = stream_context_get_params($socket);
            fclose($socket);

            $cert = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($cert === null) {
                return [
                    'status' => 'error',
                    'error' => 'Certificado no disponible',
                    'valid' => false,
                    'issuer' => 'No determinado',
                    'expires_at' => null,
                    'expires_in_days' => null,
                    'algorithm' => 'No determinado',
                    'grade' => 'F',
                ];
            }

            $parsedCert = openssl_x509_parse($cert, false);

            if (! is_array($parsedCert)) {
                return [
                    'status' => 'error',
                    'error' => 'No fue posible parsear certificado',
                    'valid' => false,
                    'issuer' => 'No determinado',
                    'expires_at' => null,
                    'expires_in_days' => null,
                    'algorithm' => 'No determinado',
                    'grade' => 'F',
                ];
            }

            $validUntil = isset($parsedCert['validTo_time_t']) ? (int) $parsedCert['validTo_time_t'] : null;
            $expiresInDays = $validUntil !== null ? (int) floor(($validUntil - time()) / 86400) : null;
            $isValid = $expiresInDays !== null ? $expiresInDays >= 0 : false;

            $grade = 'A+';

            if (! $isValid) {
                $grade = 'F';
            } elseif ($expiresInDays !== null && $expiresInDays <= 7) {
                $grade = 'C';
            } elseif ($expiresInDays !== null && $expiresInDays <= 30) {
                $grade = 'B';
            }

            return [
                'status' => 'ok',
                'error' => null,
                'valid' => $isValid,
                'issuer' => (string) ($parsedCert['issuer']['CN'] ?? 'No determinado'),
                'expires_at' => $validUntil !== null ? gmdate(DATE_ATOM, $validUntil) : null,
                'expires_in_days' => $expiresInDays,
                'algorithm' => (string) ($parsedCert['signatureTypeLN'] ?? 'No determinado'),
                'grade' => $grade,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'error' => $exception->getMessage(),
                'valid' => false,
                'issuer' => 'No determinado',
                'expires_at' => null,
                'expires_in_days' => null,
                'algorithm' => 'No determinado',
                'grade' => 'F',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectSecurityHeaders(array $headers): array
    {
        if ($headers === []) {
            return [
                'status' => 'error',
                'error' => 'Sin respuesta HTTP para evaluar cabeceras',
                'headers' => [],
                'missing' => self::SECURITY_HEADERS,
                'score' => 'N/A',
            ];
        }

        $raw = array_change_key_case($headers, CASE_LOWER);
        $evaluated = [];
        $missing = [];
        $present = 0;

        foreach (self::SECURITY_HEADERS as $header) {
            $value = isset($raw[$header]) ? trim((string) $raw[$header]) : '';
            $ok = $value !== '';
            $evaluated[$header] = [
                'present' => $ok,
                'value' => $value,
            ];

            if ($ok) {
                $present++;
            } else {
                $missing[] = $header;
            }
        }

        $ratio = $present / count(self::SECURITY_HEADERS);
        $score = 'F';

        if ($ratio >= 0.95 && $missing === []) {
            $score = 'A+';
        } elseif ($ratio >= 0.8) {
            $score = 'A';
        } elseif ($ratio >= 0.65) {
            $score = 'B';
        } elseif ($ratio >= 0.45) {
            $score = 'C';
        } elseif ($ratio >= 0.25) {
            $score = 'D';
        }

        return [
            'status' => 'ok',
            'error' => null,
            'headers' => $evaluated,
            'missing' => $missing,
            'score' => $score,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectBody(string $html, bool $hasResponse): array
    {
        if (! $hasResponse) {
            return [
                'status' => 'error',
                'error' => 'Sin respuesta HTTP para analizar body',
                'html' => '',
                'length' => 0,
            ];
        }

        $trimmed = trim($html);

        if ($trimmed === '') {
            return [
                'status' => 'empty',
                'error' => 'Body vacío',
                'html' => '',
                'length' => 0,
            ];
        }

        return [
            'status' => 'ok',
            'error' => null,
            'html' => mb_substr($trimmed, 0, 250000),
            'length' => mb_strlen($trimmed),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fingerprint(array $headers, string $body, array $knownFiles, string $inspectedUrl, string $finalUrl, ?int $httpStatus = null): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);

        $headerFlat = [];

        foreach ($headers as $name => $values) {
            $headerFlat[$name] = trim((string) $values);
        }

        $server = isset($headers['server']) ? trim((string) $headers['server']) : '';
        $xPoweredBy = isset($headers['x-powered-by']) ? trim((string) $headers['x-powered-by']) : '';
        $technology = $this->technologyFingerprintScorer->score(
            headers: $headers,
            body: $body,
            knownFiles: $knownFiles,
            inspectedUrl: $inspectedUrl,
            finalUrl: $finalUrl,
        );

        $bodyLower = mb_strtolower($body);
        $cmsName = (string) (($technology['category'] ?? 'unknown') === 'cms'
            ? ($technology['detected'] ?? 'No determinado')
            : 'No determinado');
        $cmsVersion = (string) (($technology['category'] ?? 'unknown') === 'cms'
            ? ($technology['version'] ?? 'No determinado')
            : 'No determinado');
        $cmsConfidence = (string) ($technology['confidence_label'] ?? 'low');

        $runtimeName = 'No determinado';
        $phpVersion = 'No determinado';
        $phpVersionPattern = '/php\/?\s*([0-9]+(?:\.[0-9]+){0,2})/i';

        if (preg_match($phpVersionPattern, $xPoweredBy, $phpMatch) === 1) {
            // Caso mas comun y confiable: el header X-Powered-By declara PHP
            // explicitamente (ej. "PHP/8.1.26").
            $runtimeName = 'PHP';
            $phpVersion = (string) ($phpMatch[1] ?? 'No determinado');
        } elseif (preg_match($phpVersionPattern, $server, $phpMatch) === 1) {
            // Varios servidores Apache incrustan la version de PHP directo en
            // el header Server (ej. "Apache/2.4.6 (CentOS) ... PHP/7.3.33")
            // en vez de mandar X-Powered-By por separado — antes esto se
            // ignoraba por completo y el sitio quedaba "No determinado" pese
            // a tener la version justo ahi.
            $runtimeName = 'PHP';
            $phpVersion = (string) ($phpMatch[1] ?? 'No determinado');
        } elseif (
            ($technology['category'] ?? 'unknown') === 'cms'
            && in_array($technology['slug'] ?? '', ['drupal', 'wordpress', 'laravel', 'joomla'], true)
        ) {
            // El CMS detectado (por fingerprint de HTML/rutas, no por header)
            // es, sin excepcion, PHP -aunque el servidor oculte X-Powered-By
            // y Server (expose_php=Off es una practica de endurecimiento
            // comun). Reportar el runtime como PHP aqui es una inferencia
            // solida, no una adivinanza: no hay CMS Drupal/WordPress/Laravel/
            // Joomla que no corra sobre PHP. La version se deja "No
            // determinado" porque esa si requeriria evidencia real.
            $runtimeName = 'PHP';
        }

        $frameworks = $this->detectFrameworks($body);

        $detectedTechnology = (string) ($technology['detected'] ?? 'No determinado');
        $detectedCategory = (string) ($technology['category'] ?? 'unknown');
        $technologyConfidence = (int) ($technology['confidence'] ?? 0);
        $technologyEvidence = (array) ($technology['evidence'] ?? []);

        // Regla de negocio: un sitio que responde SIEMPRE usa alguna tecnologia. El
        // scorer de arriba exige un candidato decisivo (score >= umbral y margen claro)
        // para nombrar un CMS/framework con confianza alta; cuando eso no ocurre, esta
        // cascada de respaldo usa evidencia mas debil pero real -ya calculada arriba-
        // en vez de reportar "No determinado" para un sitio que si contesto.
        if ($detectedCategory === 'unknown') {
            if ($runtimeName !== 'No determinado') {
                $detectedTechnology = $runtimeName;
                $detectedCategory = 'runtime';
                $technologyConfidence = 45;
                $technologyEvidence = ['x-powered-by: '.$xPoweredBy];
            } elseif ($frameworks !== []) {
                $detectedTechnology = implode(' + ', $frameworks);
                $detectedCategory = 'frontend';
                $technologyConfidence = 35;
                $technologyEvidence = array_map(
                    static fn (string $framework): string => 'libreria/framework detectado en el HTML: '.$framework,
                    $frameworks,
                );
            } elseif ($httpStatus !== null && $httpStatus >= 200 && $httpStatus < 400 && $this->looksLikeRealHtmlDocument($body)) {
                // Solo se acepta como "el sitio" si la respuesta fue 2xx/3xx: el HTML de
                // una pagina de error/bloqueo (403, 404, 5xx) tambien es HTML valido, pero
                // no representa la tecnologia real del sitio -eso seria un falso positivo-.
                $detectedTechnology = 'HTML/CSS/JavaScript estático';
                $detectedCategory = 'static';
                $technologyConfidence = 25;
                $technologyEvidence = ['respuesta HTML valida sin fingerprint de CMS, runtime o framework conocido'];
            }
        }

        return [
            'status' => 'ok',
            'cms' => $cmsName,
            'cms_version' => $cmsVersion,
            'cms_confidence' => $cmsConfidence,
            'cms_score' => min(100, (int) ($technology['confidence'] ?? 0)),
            'technology_confidence' => $technologyConfidence,
            'technology_evidence' => $technologyEvidence,
            'detected_technology' => $detectedTechnology,
            'detected_category' => $detectedCategory,
            'server' => $server !== '' ? $server : 'No determinado',
            'runtime_name' => $runtimeName,
            'php_version' => $phpVersion,
            'frameworks' => $frameworks,
            'signals' => [
                'headers' => $headerFlat,
                'known_files' => $knownFiles,
            ],
            'debug' => $technology,
        ];
    }

    /**
     * Ultimo escalon de la cascada de respaldo: confirma que si recibimos un documento
     * HTML real (no un cuerpo vacio, ni binario, ni una pagina irrelevante), para poder
     * reportar al menos "HTML/CSS/JavaScript estatico" en vez de "No determinado".
     */
    private function looksLikeRealHtmlDocument(string $body): bool
    {
        $trimmed = trim($body);

        if (mb_strlen($trimmed) < 100) {
            return false;
        }

        return preg_match('/<html[\s>]/i', $trimmed) === 1 || preg_match('/<(head|body)[\s>]/i', $trimmed) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function detectFrameworks(string $body): array
    {
        $lower = mb_strtolower($body);
        $found = [];

        if (preg_match_all('/(?:src|href)=["\']([^"\']+)["\']/i', $body, $assetMatches) > 0) {
            foreach ((array) ($assetMatches[1] ?? []) as $assetUrl) {
                $asset = mb_strtolower((string) $assetUrl);

                if (str_contains($asset, 'bootstrap')) {
                    $found[] = 'Bootstrap';
                }

                if (str_contains($asset, 'jquery')) {
                    $found[] = 'jQuery';
                }

                if (str_contains($asset, 'vue') || str_contains($asset, 'vue.global')) {
                    $found[] = 'Vue';
                }

                if (str_contains($asset, 'react') || str_contains($asset, 'react-dom')) {
                    $found[] = 'React';
                }

                if (str_contains($asset, 'tailwind')) {
                    $found[] = 'Tailwind';
                }
            }
        }

        if (str_contains($lower, 'react')) {
            $found[] = 'React';
        }

        if (str_contains($lower, 'vue')) {
            $found[] = 'Vue';
        }

        if (str_contains($lower, 'angular')) {
            $found[] = 'Angular';
        }

        if (str_contains($lower, 'jquery')) {
            $found[] = 'jQuery';
        }

        if (str_contains($lower, 'bootstrap')) {
            $found[] = 'Bootstrap';
        }

        if (str_contains($lower, 'tailwindcss') || str_contains($lower, 'tailwind.config')) {
            $found[] = 'Tailwind';
        }

        return array_values(array_unique($found));
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectKnownFilesParallel(Client $client, string $baseUrl, array &$instrumentation): array
    {
        $stageStartedAt = microtime(true);
        $results = [];
        // Concurrencia mayor reduce el numero de tandas secuenciales, lo que mantiene
        // el peor caso acotado incluso con timeouts por request mas generosos.
        $concurrency = max(1, (int) env('SENTINEL_KNOWN_FILES_CONCURRENCY', 5));
        $instrumentation['http_requests'] += count(self::KNOWN_FILES);

        $requests = function () use ($client, $baseUrl): \Generator {
            foreach (self::KNOWN_FILES as $path) {
                $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');

                yield $path => static function () use ($client, $url) {
                    return $client->requestAsync('GET', $url, [
                        'headers' => ['Accept' => 'text/plain,text/html,*/*;q=0.8'],
                    ]);
                };
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => static function (ResponseInterface $response, string $path) use (&$results): void {
                $results[$path] = [
                    'status' => (int) $response->getStatusCode(),
                    'body' => mb_substr((string) $response->getBody(), 0, 4000),
                ];
            },
            'rejected' => static function ($reason, string $path) use (&$results): void {
                $message = $reason instanceof \Throwable ? $reason->getMessage() : 'Known file request failed';

                $results[$path] = [
                    'status' => 0,
                    'body' => '',
                    'error' => $message,
                ];
            },
        ]);

        $pool->promise()->wait();

        foreach (self::KNOWN_FILES as $path) {
            $results[$path] = $results[$path] ?? [
                'status' => 0,
                'body' => '',
                'error' => 'No response',
            ];
        }

        $instrumentation['known_files_ms'] = (int) round((microtime(true) - $stageStartedAt) * 1000);

        return $results;
    }

    /**
     * @return 'LOW'|'MEDIUM'|'HIGH'|'CRITICAL'
     */
    private function calculateRiskLevel(?int $httpStatus, bool $sslValid, string $headersScore, bool $cmsDetected): string
    {
        $score = 0;

        if ($httpStatus === null || $httpStatus <= 0) {
            $score += 60;
        } elseif ($httpStatus >= 500) {
            $score += 45;
        } elseif ($httpStatus >= 400) {
            $score += 30;
        }

        if (! $sslValid) {
            $score += 20;
        }

        $grade = mb_strtoupper($headersScore);

        if (in_array($grade, ['F', 'N/A'], true)) {
            $score += 20;
        } elseif ($grade === 'D') {
            $score += 12;
        } elseif ($grade === 'C') {
            $score += 8;
        }

        if (! $cmsDetected) {
            $score += 5;
        }

        if ($score >= 75) {
            return 'CRITICAL';
        }

        if ($score >= 45) {
            return 'HIGH';
        }

        if ($score >= 20) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractRedirectChain(ResponseInterface $response): array
    {
        $rawUrls = $response->getHeader('X-Guzzle-Redirect-History');
        $rawStatuses = $response->getHeader('X-Guzzle-Redirect-Status-History');

        $urls = array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $rawUrls), static fn (string $value): bool => $value !== ''));
        $statuses = array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $rawStatuses), static fn (string $value): bool => $value !== ''));

        $chain = [];

        foreach ($urls as $index => $url) {
            $chain[] = [
                'url' => (string) $url,
                'status' => isset($statuses[$index]) ? (int) $statuses[$index] : null,
            ];
        }

        return $chain;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $flattened = [];

        foreach ($headers as $name => $values) {
            $flattened[mb_strtolower((string) $name)] = trim(implode(' ', (array) $values));
        }

        return $flattened;
    }

    private function normalizeUrl(?string $url, bool $preferHttps): string
    {
        $value = trim((string) $url);

        if ($value === '') {
            return $preferHttps ? 'https://invalid.local' : 'http://invalid.local';
        }

        if (! str_starts_with(mb_strtolower($value), 'http://') && ! str_starts_with(mb_strtolower($value), 'https://')) {
            return ($preferHttps ? 'https://' : 'http://').ltrim($value, '/');
        }

        if ($preferHttps && str_starts_with(mb_strtolower($value), 'http://')) {
            return preg_replace('#^http://#i', 'https://', $value) ?: $value;
        }

        return $value;
    }
}
