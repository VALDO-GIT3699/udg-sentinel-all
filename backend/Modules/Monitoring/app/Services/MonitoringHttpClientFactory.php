<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class MonitoringHttpClientFactory
{
    private const REQUEST_TIMEOUT_SECONDS = 10;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * @param array<string, string> $headers
     */
    public function make(array $headers = []): PendingRequest
    {
        $maxRedirects = max(0, (int) env('SENTINEL_HTTP_MAX_REDIRECTS', 5));

        return Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->retry(2, 250, throw: false)
            ->withOptions([
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'verify' => $this->resolveSslVerificationOption(),
                'http_errors' => false,
                'follow_redirects' => true,
                'allow_redirects' => [
                    'max' => $maxRedirects,
                    'strict' => false,
                    'referer' => true,
                    'track_redirects' => true,
                ],
            ])
            ->withHeaders(array_merge([
                'User-Agent' => (string) env('SENTINEL_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 SentinelMonitoringBot/1.0'),
                'Accept' => '*/*',
                'Accept-Language' => 'es-MX,es;q=0.9,en;q=0.5',
            ], $headers));
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
