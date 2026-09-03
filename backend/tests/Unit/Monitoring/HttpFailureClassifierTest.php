<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use Modules\Monitoring\Support\HttpFailureClassifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HttpFailureClassifierTest extends TestCase
{
    #[Test]
    public function it_classifies_certificate_and_ssl_errors_as_tls(): void
    {
        $messages = [
            'cURL error 60: SSL certificate problem: certificate has expired',
            'cURL error 60: SSL certificate problem: self-signed certificate',
            'SSL: certificate subject name does not match target hostname',
            'cURL error 51: SSL peer certificate or SSH remote key was not OK',
        ];

        foreach ($messages as $message) {
            $this->assertSame(
                HttpFailureClassifier::TYPE_TLS,
                HttpFailureClassifier::classify(new \Exception($message)),
                $message,
            );
        }
    }

    #[Test]
    public function it_classifies_dns_timeout_and_connection_refused_correctly(): void
    {
        $this->assertSame(
            HttpFailureClassifier::TYPE_DNS,
            HttpFailureClassifier::classify(new \Exception('cURL error 6: Could not resolve host: example.com')),
        );

        $this->assertSame(
            HttpFailureClassifier::TYPE_TIMEOUT,
            HttpFailureClassifier::classify(new \Exception('cURL error 28: Connection timed out after 6000 milliseconds')),
        );

        $this->assertSame(
            HttpFailureClassifier::TYPE_CONNECTION_REFUSED,
            HttpFailureClassifier::classify(new \Exception('cURL error 7: Failed to connect: Connection refused')),
        );
    }

    #[Test]
    public function it_falls_back_to_network_for_unrecognized_errors(): void
    {
        $this->assertSame(
            HttpFailureClassifier::TYPE_NETWORK,
            HttpFailureClassifier::classify(new \Exception('cURL error 56: Recv failure: Connection reset by peer')),
        );
    }

    #[Test]
    public function only_tls_failures_report_true_for_is_tls_failure(): void
    {
        $this->assertTrue(HttpFailureClassifier::isTlsFailure(new \Exception('SSL certificate problem: certificate has expired')));
        $this->assertFalse(HttpFailureClassifier::isTlsFailure(new \Exception('cURL error 6: Could not resolve host')));
    }
}
