<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteCheckRepositoryInterface;
use App\Contracts\Repositories\SiteGroupRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\BrokenLink;
use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\SiteTechnology;
use App\Models\SslCertificate;
use App\Models\TrafficMetric;
use App\Models\ServerMetric;
use App\Models\Vulnerability;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    private const DASHBOARD_DEFAULT_PER_PAGE = 50;

    private const DASHBOARD_MAX_PER_PAGE = 500;

    private const DASHBOARD_REFRESH_INTERVAL_MS = 15000;

    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly SiteGroupRepositoryInterface $siteGroupRepository,
        private readonly SiteCheckRepositoryInterface $siteCheckRepository,
        private readonly AlertRepositoryInterface $alertRepository,
    ) {
    }

    public function index(Request $request): Response
    {
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
            'filters' => $filters,
            'statusCounts' => $this->translateStatusCounts($this->siteRepository->countByStatus()),
            'statusByGroup' => $this->siteRepository->statusByGroup(),
            'pipelineMetrics' => $this->pipelineMetrics(),
            'groups' => $this->siteGroupRepository->withMonitoredSiteCount(),
            'sites' => $this->translateSiteStatuses($sites),
            'siteTelemetry' => $this->buildSiteTelemetry($sites),
            'timeline' => $this->translateTimelineStatuses($this->siteCheckRepository->recentTimeline(1, 60)),
            'openAlerts' => $this->alertRepository->recentOpen(20),
            'openAlertsCount' => $this->alertRepository->countOpen(),
            'criticalAlertsCount' => $this->alertRepository->countCritical(),
            'refreshIntervalMs' => self::DASHBOARD_REFRESH_INTERVAL_MS,
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineMetrics(): array
    {
        $summary = $this->siteCheckRepository->summarizeWindow(1);

        $totalChecksLastHour = $summary['total_checks'];
        $downChecksLastHour = $summary['down_checks'];
        $avgLatencyMsLastHour = $summary['avg_latency_ms'];

        $queueDepth = [
            'monitoring-uptime' => null,
            'monitoring-ssl' => null,
            'monitoring-tech' => null,
            'monitoring-headers' => null,
            'monitoring-alerts' => null,
        ];

        foreach (array_keys($queueDepth) as $queueName) {
            try {
                $queueDepth[$queueName] = Redis::llen('queues:' . $queueName);
            } catch (\Throwable) {
                $queueDepth[$queueName] = null;
            }
        }

        return [
            'window' => '1h',
            'totalChecks' => $totalChecksLastHour,
            'downChecks' => $downChecksLastHour,
            'errorRatePct' => $totalChecksLastHour > 0 ? round(($downChecksLastHour / $totalChecksLastHour) * 100, 2) : 0.0,
            'avgLatencyMs' => $avgLatencyMsLastHour !== null ? round((float) $avgLatencyMsLastHour, 2) : null,
            'queueDepth' => $queueDepth,
            'trafficPeaks' => TrafficMetric::query()
                ->where('recorded_at', '>=', now()->subHour())
                ->orderBy('recorded_at')
                ->limit(120)
                ->get(['recorded_at', 'avg_response_time_ms', 'requests_per_min'])
                ->map(static fn ($metric) => [
                    'at' => optional($metric->recorded_at)->format('H:i:s'),
                    'latencyMs' => $metric->avg_response_time_ms,
                    'rpm' => $metric->requests_per_min,
                ])
                ->values(),
            'resources' => $this->resourceSummary(),
        ];
    }

    /**
     * @return array<string, float|int|null>
     */
    private function resourceSummary(): array
    {
        $latestRows = ServerMetric::query()
            ->whereIn('id', function ($query): void {
                $query->selectRaw('MAX(id)')
                    ->from('server_metrics')
                    ->groupBy('server_id');
            })
            ->get();

        if ($latestRows->isEmpty()) {
            return [
                'servers' => 0,
                'cpuAvgPct' => null,
                'ramAvgPct' => null,
                'diskAvgPct' => null,
            ];
        }

        return [
            'servers' => $latestRows->count(),
            'cpuAvgPct' => round((float) $latestRows->avg('cpu_usage_pct'), 2),
            'ramAvgPct' => round((float) $latestRows->avg('ram_usage_pct'), 2),
            'diskAvgPct' => round((float) $latestRows->avg('disk_usage_pct'), 2),
        ];
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

    public function siteDetail(Site $site): Response
    {
        $record = $this->siteRepository->findById($site->id);

        abort_if($record === null, 404);

        return Inertia::render('Monitoring/SiteDetail', [
            'site' => $record,
            'timeline' => $this->siteCheckRepository->timelineForSite($site->id, 24, 288),
            'statusBreakdown24h' => $this->siteCheckRepository->statusBreakdownForSite($site->id, 24),
            'uptime24h' => $this->siteCheckRepository->uptimePercentage($site->id, 24),
            'avgResponse24h' => $this->siteCheckRepository->avgResponseTime($site->id, 24),
            'openAlerts' => $this->alertRepository->openForSite($site->id),
            'events' => $record->events()->orderByDesc('occurred_at')->limit(100)->get(),
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function translateStatusCounts(array $counts): array
    {
        return [
            'ACTIVO' => (int) ($counts['up'] ?? 0),
            'DEGRADADO' => (int) ($counts['degraded'] ?? 0),
            'CAÍDO' => (int) ($counts['down'] ?? 0),
            'DESCONOCIDO' => (int) ($counts['unknown'] ?? 0),
        ];
    }

    private function translateSiteStatuses(LengthAwarePaginator $sites): LengthAwarePaginator
    {
        return $sites->through(function ($site) {
            $site->current_status_code = (string) $site->current_status;
            $site->current_status = $this->translateStatusCode((string) $site->current_status);

            return $site;
        });
    }

    /**
     * @param Collection<int, mixed> $timeline
     * @return Collection<int, mixed>
     */
    private function translateTimelineStatuses(Collection $timeline): Collection
    {
        return $timeline->map(function ($point) {
            $point->status_code = (string) $point->status;
            $point->status = $this->translateStatusCode((string) $point->status);

            return $point;
        });
    }

    private function translateStatusCode(string $status): string
    {
        return match ($status) {
            'up' => 'ACTIVO',
            'degraded' => 'DEGRADADO',
            'down' => 'CAÍDO',
            default => 'DESCONOCIDO',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSiteTelemetry(LengthAwarePaginator $sites): array
    {
        $items = collect($sites->items());
        $siteIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($siteIds === []) {
            return [];
        }

        $sslBySite = SslCertificate::query()
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('last_checked_at')
            ->get()
            ->groupBy('site_id')
            ->map(static fn (Collection $group) => $group->first());

        $brokenBySite = BrokenLink::query()
            ->whereIn('site_id', $siteIds)
            ->where('http_code', 404)
            ->where('is_resolved', false)
            ->orderByDesc('last_checked_at')
            ->get(['site_id', 'url', 'found_on', 'last_checked_at'])
            ->groupBy('site_id')
            ->map(static fn (Collection $group) => $group->take(8)->values());

        $techBySite = SiteTechnology::query()
            ->with('technology:id,name,slug,category')
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('detected_at')
            ->get(['site_id', 'technology_id', 'version', 'is_primary', 'detected_at', 'metadata'])
            ->groupBy('site_id')
            ->map(static fn (Collection $group) => $group->unique('technology_id')->take(15)->values());

        $trafficBySite = TrafficMetric::query()
            ->whereIn('site_id', $siteIds)
            ->where('recorded_at', '>=', now()->subHour())
            ->orderBy('recorded_at')
            ->get(['site_id', 'recorded_at', 'avg_response_time_ms', 'requests_per_min'])
            ->groupBy('site_id')
            ->map(static fn (Collection $group) => $group->take(40)->values());

        $vulnerabilityBySite = Vulnerability::query()
            ->whereIn('site_id', $siteIds)
            ->where('is_active', true)
            ->where('is_false_positive', false)
            ->selectRaw('site_id, COUNT(*) as total')
            ->groupBy('site_id')
            ->pluck('total', 'site_id');

        $resourcesBySite = ServerMetric::query()
            ->join('servers', 'servers.id', '=', 'server_metrics.server_id')
            ->join('site_server', 'site_server.server_id', '=', 'servers.id')
            ->whereIn('site_server.site_id', $siteIds)
            ->whereIn('server_metrics.id', function ($query): void {
                $query->selectRaw('MAX(id)')->from('server_metrics')->groupBy('server_id');
            })
            ->get([
                'site_server.site_id as site_id',
                'server_metrics.cpu_usage_pct',
                'server_metrics.ram_usage_pct',
                'server_metrics.disk_usage_pct',
                'server_metrics.recorded_at',
            ])
            ->groupBy('site_id')
            ->map(static fn (Collection $group) => [
                'cpuPct' => round((float) $group->avg('cpu_usage_pct'), 2),
                'ramPct' => round((float) $group->avg('ram_usage_pct'), 2),
                'diskPct' => round((float) $group->avg('disk_usage_pct'), 2),
                'updatedAt' => optional($group->sortByDesc('recorded_at')->first()?->recorded_at)->toIso8601String(),
            ]);

        return $items->map(function ($site) use ($sslBySite, $brokenBySite, $techBySite, $trafficBySite, $vulnerabilityBySite, $resourcesBySite): array {
            $siteId = (int) $site->id;
            $ssl = $sslBySite->get($siteId);
            $score = (int) ($site->current_score ?? 0);
            $scoreLevel = (string) ($site->current_score_level ?? 'unknown');

            return [
                'siteId' => $siteId,
                'protectionLevel' => $this->protectionLevel($scoreLevel, $score),
                'securityScore' => $score,
                'ssl' => [
                    'daysRemaining' => $ssl?->days_remaining,
                    'expiresAt' => optional($ssl?->valid_until)->toIso8601String(),
                    'remainingText' => $this->humanSslRemaining($ssl?->days_remaining),
                ],
                'broken404' => $brokenBySite->get($siteId, collect())->map(static fn ($item) => [
                    'url' => (string) $item->url,
                    'route' => (string) ($item->found_on ?? '/'),
                    'lastCheckedAt' => optional($item->last_checked_at)->toIso8601String(),
                ])->values(),
                'technologies' => $techBySite->get($siteId, collect())->map(static fn ($item) => [
                    'name' => (string) optional($item->technology)->name,
                    'slug' => (string) optional($item->technology)->slug,
                    'category' => (string) optional($item->technology)->category,
                    'version' => $item->version,
                    'isPrimary' => (bool) $item->is_primary,
                    'metadata' => $item->metadata,
                ])->values(),
                'traffic' => $trafficBySite->get($siteId, collect())->map(static fn ($item) => [
                    'at' => optional($item->recorded_at)->format('H:i:s'),
                    'latencyMs' => $item->avg_response_time_ms,
                    'rpm' => $item->requests_per_min,
                ])->values(),
                'resources' => $resourcesBySite->get($siteId),
                'openVulnerabilities' => (int) ($vulnerabilityBySite[$siteId] ?? 0),
            ];
        })->values()->all();
    }

    private function humanSslRemaining(?int $daysRemaining): string
    {
        if ($daysRemaining === null) {
            return 'Sin dato de expiracion SSL';
        }

        if ($daysRemaining < 0) {
            return sprintf('Expiro hace %d dias', abs($daysRemaining));
        }

        $months = intdiv($daysRemaining, 30);
        $days = $daysRemaining % 30;

        if ($months > 0) {
            return sprintf('En %d mes(es) y %d dia(s) vence', $months, $days);
        }

        return sprintf('En %d dia(s) vence', $daysRemaining);
    }

    private function protectionLevel(string $scoreLevel, int $score): string
    {
        if ($score < 34 || in_array($scoreLevel, ['critical', 'very-low'], true)) {
            return 'Expuesto';
        }

        if ($score < 50 || in_array($scoreLevel, ['low'], true)) {
            return 'Bajo';
        }

        if ($score < 80 || in_array($scoreLevel, ['medium'], true)) {
            return 'Medio';
        }

        return 'Alto';
    }
}
