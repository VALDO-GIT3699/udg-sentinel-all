<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Models\SslCertificate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SslCertificateRulesTest extends TestCase
{
    #[Test]
    public function it_returns_expired_when_certificate_is_expired(): void
    {
        $certificate = new SslCertificate();
        $certificate->is_expired = true;
        $certificate->days_remaining = -1;

        $this->assertSame('expired', $certificate->expiry_level);
    }

    #[Test]
    public function it_returns_critical_when_days_remaining_is_less_than_or_equal_to_critical_threshold(): void
    {
        config([
            'sentinel.ssl_alert_days_critical' => 7,
            'sentinel.ssl_alert_days_warning' => 30,
        ]);

        $certificate = new SslCertificate();
        $certificate->is_expired = false;
        $certificate->days_remaining = 5;

        $this->assertSame('critical', $certificate->expiry_level);
    }

    #[Test]
    public function it_returns_warning_when_days_remaining_is_less_than_or_equal_to_warning_threshold(): void
    {
        config([
            'sentinel.ssl_alert_days_critical' => 7,
            'sentinel.ssl_alert_days_warning' => 30,
        ]);

        $certificate = new SslCertificate();
        $certificate->is_expired = false;
        $certificate->days_remaining = 20;

        $this->assertSame('warning', $certificate->expiry_level);
    }
}
