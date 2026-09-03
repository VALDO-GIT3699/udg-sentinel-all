<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use Modules\Monitoring\Jobs\RunSiteInspectionJob;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

final class RunSiteInspectionJobStatusTest extends TestCase
{
    #[Test]
    public function it_reports_degraded_when_the_response_is_healthy_but_analysis_produced_errors(): void
    {
        $status = $this->resolveOperationalStatus(
            $this->healthyInspection(),
            ['El fingerprint de tecnología no pudo completarse.'],
        );

        $this->assertSame('degraded', $status);
    }

    #[Test]
    public function it_reports_up_when_the_response_is_healthy_and_analysis_found_no_errors(): void
    {
        $status = $this->resolveOperationalStatus($this->healthyInspection(), []);

        $this->assertSame('up', $status);
    }

    private function resolveOperationalStatus(array $inspection, array $analysisErrors): string
    {
        $job = new RunSiteInspectionJob(1);
        $method = new ReflectionMethod(RunSiteInspectionJob::class, 'resolveOperationalStatus');

        return $method->invoke($job, $inspection, $analysisErrors);
    }

    private function healthyInspection(): array
    {
        return [
            'http' => ['status_code' => 200],
            'ssl' => ['status' => 'ok', 'valid' => true],
            'body' => ['status' => 'ok'],
        ];
    }
}
