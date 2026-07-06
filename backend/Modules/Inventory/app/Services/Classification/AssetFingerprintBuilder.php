<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Classification;

use App\Models\Site;
use Illuminate\Http\Client\Response;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;

final class AssetFingerprintBuilder
{
    public function __construct(private readonly MonitoringHttpClientFactory $httpClientFactory)
    {
    }

    public function build(Site $site): AssetFingerprint
    {
        $host = $this->resolveHost($site);
        $httpProbe = $this->probeHttp($site);
        $dns = $this->resolveDnsEvidence($host);

        $technologies = $site->siteTechnologies
            ->map(static fn ($entry): array => [
                'slug' => (string) ($entry->technology->slug ?? ''),
                'name' => (string) ($entry->technology->name ?? ''),
                'confidence_pct' => (int) ($entry->confidence_pct ?? 0),
            ])
            ->values()
            ->all();

        return new AssetFingerprint(
            siteId: (int) $site->id,
            host: $host,
            url: (string) $site->url,
            httpStatus: $httpProbe['status'],
            contentType: $httpProbe['content_type'],
            httpHeaders: $httpProbe['headers'],
            htmlExcerpt: $httpProbe['body_excerpt'],
            looksLikeJson: $httpProbe['looks_like_json'],
            looksLikeXml: $httpProbe['looks_like_xml'],
            redirectChain: $httpProbe['redirect_chain'],
            technologies: $technologies,
            dns: $dns,
            ssl: [
                'is_valid' => (bool) ($site->sslCertificate?->is_valid ?? false),
                'days_remaining' => $site->sslCertificate?->days_remaining,
            ],
            metadata: [
                'site_group' => $site->siteGroup?->slug,
                'current_status' => $site->current_status,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function probeHttp(Site $site): array
    {
        if (! (bool) config('inventory.classification.http_probe_enabled', true)) {
            return [
                'status' => null,
                'content_type' => null,
                'headers' => [],
                'body_excerpt' => '',
                'looks_like_json' => false,
                'looks_like_xml' => false,
                'redirect_chain' => [],
            ];
        }

        try {
            $response = $this->httpClientFactory
                ->make(['Accept' => 'application/json,text/xml,text/html,*/*;q=0.8'])
                ->get((string) $site->url);

            return $this->normalizeProbe($response);
        } catch (\Throwable) {
            return [
                'status' => null,
                'content_type' => null,
                'headers' => [],
                'body_excerpt' => '',
                'looks_like_json' => false,
                'looks_like_xml' => false,
                'redirect_chain' => [],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeProbe(Response $response): array
    {
        $headers = array_change_key_case($response->headers(), CASE_LOWER);
        $contentType = isset($headers['content-type']) ? (string) ($headers['content-type'][0] ?? '') : null;
        $body = trim((string) $response->body());
        $excerpt = mb_substr($body, 0, 9000);

        $looksLikeJson = str_starts_with($body, '{') || str_starts_with($body, '[');
        $looksLikeXml = str_starts_with($body, '<') && str_contains($body, '>');

        $redirectChain = [];
        $history = $response->header('x-guzzle-redirect-history');

        if (is_array($history)) {
            $redirectChain = array_values(array_filter(array_map('strval', $history)));
        }

        return [
            'status' => $response->status(),
            'content_type' => $contentType,
            'headers' => $headers,
            'body_excerpt' => $excerpt,
            'looks_like_json' => $looksLikeJson,
            'looks_like_xml' => $looksLikeXml,
            'redirect_chain' => $redirectChain,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDnsEvidence(string $host): array
    {
        if (! function_exists('dns_get_record') || $host === '') {
            return [
                'a_count' => 0,
                'aaaa_count' => 0,
                'mx_count' => 0,
                'cname_count' => 0,
                'txt_count' => 0,
            ];
        }

        try {
            $a = dns_get_record($host, DNS_A) ?: [];
            $aaaa = dns_get_record($host, DNS_AAAA) ?: [];
            $mx = dns_get_record($host, DNS_MX) ?: [];
            $cname = dns_get_record($host, DNS_CNAME) ?: [];
            $txt = dns_get_record($host, DNS_TXT) ?: [];

            return [
                'a_count' => count($a),
                'aaaa_count' => count($aaaa),
                'mx_count' => count($mx),
                'cname_count' => count($cname),
                'txt_count' => count($txt),
            ];
        } catch (\Throwable) {
            return [
                'a_count' => 0,
                'aaaa_count' => 0,
                'mx_count' => 0,
                'cname_count' => 0,
                'txt_count' => 0,
            ];
        }
    }

    private function resolveHost(Site $site): string
    {
        $candidate = trim((string) ($site->domain ?: parse_url((string) $site->url, PHP_URL_HOST)));

        return mb_strtolower($candidate);
    }
}
