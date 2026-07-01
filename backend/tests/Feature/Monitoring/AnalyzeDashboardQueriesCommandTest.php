<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AnalyzeDashboardQueriesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_runs_explain_for_dashboard_queries(): void
    {
        $this->artisan('monitoring:analyze-dashboard-queries --group-id=1 --format=json')
            ->expectsOutputToContain('dashboard_sites')
            ->expectsOutputToContain('status_by_group')
            ->expectsOutputToContain('pipeline_summary')
            ->expectsOutputToContain('open_alerts_by_group')
            ->assertExitCode(0);
    }
}
