<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteCheckRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\SiteGroup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    private const DASHBOARD_DEFAULT_PER_PAGE = 50;

    private const DASHBOARD_MAX_PER_PAGE = 500;

    private const DASHBOARD_REFRESH_INTERVAL_MS = 5000;

    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly SiteCheckRepositoryInterface $siteCheckRepository,
        private readonly AlertRepositoryInterface $alertRepository,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->warmTelemetryPipeline();

        $filters = [
            'status' => $request->string('status')->toString() ?: 'all',
            'group_id' => $request->integer('group_id') ?: null,
            'search' => $request->string('search')->toString(),
            'priority' => $request->integer('priority') ?: null,
        ];

        $sites = $this->siteRepository->paginate(
            perPage: max(1, min(self::DASHBOARD_MAX_PER_PAGE, $request->integer('per_page', self::DASHBOARD_DEFAULT_PER_PAGE))),
            filters: array_filter($filters, static fn ($value): bool => $value !== null && $value !== '')
        );

        return Inertia::render('Monitoring/Dashboard', [
            'filters' => [
                'search' => (string) ($filters['search'] ?? ''),
            ],
            'statusCounts' => $this->siteRepository->countByStatus(),
            'pipelineMetrics' => $this->pipelineMetrics(),
            'sites' => $sites,
            'refreshIntervalMs' => self::DASHBOARD_REFRESH_INTERVAL_MS,
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    private function warmTelemetryPipeline(): void
    {
        if (! app()->environment(['local', 'development'])) {
            return;
        }

        if (! Cache::add('monitoring:dashboard:warmup', now()->timestamp, 60)) {
            return;
        }

        app()->terminating(function (): void {
            $this->dispatchWarmupCommands();
        });
    }

    private function dispatchWarmupCommands(): void
    {
        $commands = [
            ['monitoring:dispatch-head-checks', ['--limit' => 50, '--chunk' => 25, '--stagger' => 0]],
            ['monitoring:dispatch-ssl-checks', ['--limit' => 50, '--chunk' => 25, '--stagger' => 0]],
            ['monitoring:dispatch-security-headers-checks', ['--limit' => 50, '--chunk' => 25, '--stagger' => 0]],
            ['monitoring:dispatch-technology-scans', ['--limit' => 50]],
        ];

        foreach ($commands as [$command, $arguments]) {
            try {
                Artisan::call($command, $arguments);
            } catch (\Throwable) {
                // El warm-up es opcional y no debe romper el request del dashboard.
            }
        }
    }

    public function groupView(Request $request, SiteGroup $group): Response
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: 'all',
            'search' => $request->string('search')->toString(),
            'priority' => $request->integer('priority') ?: null,
            'group_id' => $group->id,
        ];

        return Inertia::render('Monitoring/GroupDetail', [
            'group' => $group,
            'filters' => $filters,
            'sites' => $this->siteRepository->paginate(
                perPage: max(1, min(100, $request->integer('per_page', 20))),
                filters: array_filter($filters, static fn ($value): bool => $value !== null && $value !== '')
            ),
            'statusCounts' => $this->siteRepository->countByStatus($group->id),
            'openAlerts' => $this->alertRepository->recentOpenForGroup($group->id, 20),
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineMetrics(): array
    {
        $summary = $this->siteCheckRepository->summarizeWindow(1);

        $totalChecksLastHour = max(0, (int) ($summary['total_checks'] ?? 0));
        $downChecksLastHour = max(0, (int) ($summary['down_checks'] ?? 0));
        $avgLatencyMsLastHour = $totalChecksLastHour > 0 && isset($summary['avg_latency_ms']) && $summary['avg_latency_ms'] !== null
            ? round((float) $summary['avg_latency_ms'], 2)
            : 0.0;

        $queueDepth = [
            'monitoring-uptime' => 0,
            'monitoring-ssl' => 0,
            'monitoring-tech' => 0,
            'monitoring-headers' => 0,
            'monitoring-alerts' => 0,
        ];

        foreach (array_keys($queueDepth) as $queueName) {
            try {
                $queueDepth[$queueName] = max(0, (int) Redis::llen('queues:' . $queueName));
            } catch (\Throwable) {
                $queueDepth[$queueName] = 0;
            }
        }

        return [
            'window' => '1h',
            'totalChecks' => $totalChecksLastHour,
            'downChecks' => $downChecksLastHour,
            'errorRatePct' => $totalChecksLastHour > 0 ? round(($downChecksLastHour / $totalChecksLastHour) * 100, 2) : 0.0,
            'avgLatencyMs' => $avgLatencyMsLastHour,
            'queueDepth' => $queueDepth,
        ];
    }

}
