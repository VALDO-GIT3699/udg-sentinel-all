<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services;

use GuzzleHttp\Client;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class MonitoringHttpClientFactory
{
    private const DEFAULT_REQUEST_TIMEOUT_SECONDS = 10;

    private const DEFAULT_CONNECT_TIMEOUT_SECONDS = 5;

    private const DEFAULT_RETRIES = 2;

    private const MASS_SCAN_REQUEST_TIMEOUT_SECONDS = 6;

    private const MASS_SCAN_CONNECT_TIMEOUT_SECONDS = 3;

    private const MASS_SCAN_RETRIES = 0;

    /**
     * @param  array<string, string>  $headers
     * @param  bool  $relaxTls  Omite la verificacion TLS unicamente para este request; usar solo como
     *                          fallback controlado de fingerprint tras confirmar un fallo de certificado.
     */
    public function make(array $headers = [], string $profile = 'default', bool $relaxTls = false): PendingRequest
    {
        $policy = $this->policy($profile);
        $request = Http::timeout($policy['timeout'])
            ->connectTimeout($policy['connect_timeout'])
            ->withOptions($this->requestOptions($policy, $relaxTls))
            ->withHeaders($this->headers($headers));

        if ($policy['retries'] > 0) {
            $request = $request->retry($policy['retries'], 250, throw: false);
        }

        return $request;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  bool  $relaxTls  Omite la verificacion TLS unicamente para este cliente; usar solo como
     *                          fallback controlado de fingerprint tras confirmar un fallo de certificado.
     */
    public function createGuzzleClient(array $headers = [], string $profile = 'default', bool $relaxTls = false): Client
    {
        $policy = $this->policy($profile);

        return new Client([
            'timeout' => $policy['timeout'],
            'connect_timeout' => $policy['connect_timeout'],
            'verify' => $relaxTls ? false : $this->resolveSslVerificationOption(),
            'http_errors' => false,
            'allow_redirects' => [
                'max' => max(0, (int) env('SENTINEL_HTTP_MAX_REDIRECTS', 5)),
                'strict' => false,
                'referer' => true,
                'track_redirects' => true,
            ],
            'headers' => $this->headers($headers),
        ]);
    }

    /**
     * @param  array{timeout:int, connect_timeout:int, retries:int}  $policy
     * @return array<string, mixed>
     */
    private function requestOptions(array $policy, bool $relaxTls = false): array
    {
        $maxRedirects = max(0, (int) env('SENTINEL_HTTP_MAX_REDIRECTS', 5));

        return [
            'timeout' => $policy['timeout'],
            'connect_timeout' => $policy['connect_timeout'],
            'verify' => $relaxTls ? false : $this->resolveSslVerificationOption(),
            'http_errors' => false,
            'follow_redirects' => true,
            'allow_redirects' => [
                'max' => $maxRedirects,
                'strict' => false,
                'referer' => true,
                'track_redirects' => true,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        return array_merge([
            'User-Agent' => (string) env('SENTINEL_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 SentinelMonitoringBot/1.0'),
            'Accept' => '*/*',
            'Accept-Language' => 'es-MX,es;q=0.9,en;q=0.5',
        ], $headers);
    }

    /**
     * @return array{timeout:int, connect_timeout:int, retries:int}
     */
    private function policy(string $profile): array
    {
        if ($profile === 'mass_scan') {
            return [
                'timeout' => max(1, (int) env('SENTINEL_MASS_SCAN_HTTP_TIMEOUT', self::MASS_SCAN_REQUEST_TIMEOUT_SECONDS)),
                'connect_timeout' => max(1, (int) env('SENTINEL_MASS_SCAN_HTTP_CONNECT_TIMEOUT', self::MASS_SCAN_CONNECT_TIMEOUT_SECONDS)),
                'retries' => max(0, (int) env('SENTINEL_MASS_SCAN_HTTP_RETRIES', self::MASS_SCAN_RETRIES)),
            ];
        }

        return [
            'timeout' => self::DEFAULT_REQUEST_TIMEOUT_SECONDS,
            'connect_timeout' => self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
            'retries' => self::DEFAULT_RETRIES,
        ];
    }

    /**
     * @return bool|string
     */
    private function resolveSslVerificationOption()
    {
        $configured = env('SENTINEL_HTTP_VERIFY_SSL');

        if ($configured === null || $configured === '') {
            return ! app()->environment('local');
        }

        $normalized = mb_strtolower((string) $configured);

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return (string) $configured;
    }
}
