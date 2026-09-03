<?php

declare(strict_types=1);

namespace Modules\Monitoring\Support;

/**
 * Distingue el motivo real de una excepcion HTTP para no confundir un
 * certificado TLS invalido con un sitio genuinamente inalcanzable.
 */
final class HttpFailureClassifier
{
    public const TYPE_TLS = 'tls';

    public const TYPE_DNS = 'dns';

    public const TYPE_TIMEOUT = 'timeout';

    public const TYPE_CONNECTION_REFUSED = 'connection_refused';

    public const TYPE_NETWORK = 'network';

    public static function classify(\Throwable $exception): string
    {
        $message = mb_strtolower($exception->getMessage());

        if (str_contains($message, 'ssl') || str_contains($message, 'certificate') || str_contains($message, 'cert has expired')) {
            return self::TYPE_TLS;
        }

        if (str_contains($message, 'could not resolve host') || str_contains($message, 'name or service not known') || str_contains($message, 'curl error 6')) {
            return self::TYPE_DNS;
        }

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'curl error 28')) {
            return self::TYPE_TIMEOUT;
        }

        if (str_contains($message, 'connection refused') || str_contains($message, 'curl error 7')) {
            return self::TYPE_CONNECTION_REFUSED;
        }

        return self::TYPE_NETWORK;
    }

    public static function isTlsFailure(\Throwable $exception): bool
    {
        return self::classify($exception) === self::TYPE_TLS;
    }
}
