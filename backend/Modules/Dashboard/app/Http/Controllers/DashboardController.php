<?php

declare(strict_types=1);

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\SecurityScore;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\SiteTechnology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Monitoring\Support\DetectedTechnology;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard/Index', [
            'summary' => $this->buildSummary(),
            'chartDataUrl' => route('dashboard.chart-data', $request->query()),
            'reportUrl' => route('dashboard.executive-report', $request->query()),
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    public function chartData(Request $request): JsonResponse
    {
        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'site_checks' => [
                'last_30_days' => $this->dailySiteCheckSeries(30),
            ],
            'server_metrics' => [
                'last_30_days' => $this->dailyServerMetricSeries(30),
            ],
            'security_scores' => [
                'last_30_days' => $this->dailySecurityScoreSeries(30),
            ],
            'traffic_metrics' => [
                'last_30_days' => $this->dailyTrafficMetricSeries(30),
            ],
        ]);
    }

    public function executiveReport(Request $request)
    {
        return view('dashboard::executive-report', $this->buildExecutiveReport());
    }

    public function show(Request $request, string $dashboard): Response
    {
        return $this->index($request);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('dashboard.index');
    }

    public function edit(Request $request, string $dashboard): RedirectResponse
    {
        return redirect()->route('dashboard.index');
    }

    public function store(Request $request): void
    {
        abort(405);
    }

    public function update(Request $request, string $dashboard): void
    {
        abort(405);
    }

    public function destroy(string $dashboard): void
    {
        abort(405);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(): array
    {
        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'kpis' => [
                'active_sites' => Site::query()->where('is_active', true)->where('is_monitored', true)->count(),
                'uptime_7d_pct' => $this->uptimePercentage(7),
                'uptime_30d_pct' => $this->uptimePercentage(30),
                'avg_response_time_7d_ms' => $this->averageSiteResponseTime(7),
                'avg_cpu_7d_pct' => $this->averageServerCpuUsage(7),
                'avg_security_score_30d' => $this->averageSecurityScore(30),
                'open_alerts' => Alert::query()->whereIn('status', ['open', 'acknowledged'])->count(),
                'obsolete_technologies' => $this->obsoleteTechnologies(10)->count(),
            ],
            'top_alerts' => $this->topAlerts(5),
            'obsolete_technologies' => $this->obsoleteTechnologies(8)->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExecutiveReport(): array
    {
        $summary = $this->buildSummary();

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'uptime_week_pct' => $this->uptimePercentage(7),
            'uptime_month_pct' => $this->uptimePercentage(30),
            'avg_response_time_week_ms' => $this->averageSiteResponseTime(7),
            'top_alerts' => $summary['top_alerts'],
            'obsolete_technologies' => $summary['obsolete_technologies'],
            'critical_sites' => Site::query()
                ->where('is_active', true)
                ->where('is_monitored', true)
                ->whereIn('current_status', ['down', 'degraded'])
                ->orderByRaw("CASE current_status WHEN 'down' THEN 1 WHEN 'degraded' THEN 2 ELSE 3 END")
                ->orderBy('name')
                ->limit(10)
                ->get(['id', 'name', 'domain', 'current_status'])
                ->map(static fn (Site $site): array => [
                    'id' => (int) $site->id,
                    'name' => (string) $site->name,
                    'domain' => (string) $site->domain,
                    'status' => (string) $site->current_status,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dailySiteCheckSeries(int $days): array
    {
        $start = now()->subDays(max(1, min($days, 7)));

        return DB::table('site_checks')
            ->selectRaw("DATE_TRUNC('day', checked_at)::date as day")
            ->selectRaw('COUNT(*)::int as total_checks')
            ->selectRaw("SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END)::int as up_checks")
            ->selectRaw("SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END)::int as down_checks")
            ->selectRaw("SUM(CASE WHEN status = 'degraded' THEN 1 ELSE 0 END)::int as degraded_checks")
            ->selectRaw("SUM(CASE WHEN status = 'timeout' THEN 1 ELSE 0 END)::int as timeout_checks")
            ->selectRaw('AVG(response_time_ms) as avg_response_time_ms')
            ->where('checked_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('day', checked_at)::date")
            ->orderBy('day')
            ->get()
            ->map(static fn (object $row): array => [
                'day' => (string) $row->day,
                'total_checks' => (int) $row->total_checks,
                'up_checks' => (int) $row->up_checks,
                'down_checks' => (int) $row->down_checks,
                'degraded_checks' => (int) $row->degraded_checks,
                'timeout_checks' => (int) $row->timeout_checks,
                'avg_response_time_ms' => $row->avg_response_time_ms !== null ? round((float) $row->avg_response_time_ms, 2) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dailyServerMetricSeries(int $days): array
    {
        $start = now()->subDays(max(1, min($days, 7)));

        return DB::table('server_metrics')
            ->selectRaw("DATE_TRUNC('day', recorded_at)::date as day")
            ->selectRaw('AVG(cpu_usage_pct) as avg_cpu_usage_pct')
            ->selectRaw('AVG(ram_usage_pct) as avg_ram_usage_pct')
            ->selectRaw('AVG(disk_usage_pct) as avg_disk_usage_pct')
            ->selectRaw('AVG(load_avg_1) as avg_load_avg_1')
            ->selectRaw('AVG(load_avg_5) as avg_load_avg_5')
            ->selectRaw('AVG(load_avg_15) as avg_load_avg_15')
            ->where('recorded_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('day', recorded_at)::date")
            ->orderBy('day')
            ->get()
            ->map(static fn (object $row): array => [
                'day' => (string) $row->day,
                'avg_cpu_usage_pct' => $row->avg_cpu_usage_pct !== null ? round((float) $row->avg_cpu_usage_pct, 2) : null,
                'avg_ram_usage_pct' => $row->avg_ram_usage_pct !== null ? round((float) $row->avg_ram_usage_pct, 2) : null,
                'avg_disk_usage_pct' => $row->avg_disk_usage_pct !== null ? round((float) $row->avg_disk_usage_pct, 2) : null,
                'avg_load_avg_1' => $row->avg_load_avg_1 !== null ? round((float) $row->avg_load_avg_1, 2) : null,
                'avg_load_avg_5' => $row->avg_load_avg_5 !== null ? round((float) $row->avg_load_avg_5, 2) : null,
                'avg_load_avg_15' => $row->avg_load_avg_15 !== null ? round((float) $row->avg_load_avg_15, 2) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dailySecurityScoreSeries(int $days): array
    {
        $start = now()->subDays(max(1, $days));

        return SecurityScore::query()
            ->selectRaw("DATE_TRUNC('day', calculated_at)::date as day")
            ->selectRaw('AVG(score) as avg_score')
            ->selectRaw("SUM(CASE WHEN level = 'critical' THEN 1 ELSE 0 END)::int as critical_sites")
            ->where('calculated_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('day', calculated_at)::date")
            ->orderBy('day')
            ->get()
            ->map(static fn (object $row): array => [
                'day' => (string) $row->day,
                'avg_score' => $row->avg_score !== null ? round((float) $row->avg_score, 2) : null,
                'critical_sites' => (int) $row->critical_sites,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dailyTrafficMetricSeries(int $days): array
    {
        $start = now()->subDays(max(1, min($days, 7)));

        return DB::table('traffic_metrics')
            ->selectRaw("DATE_TRUNC('day', recorded_at)::date as day")
            ->selectRaw('AVG(requests_per_min) as avg_requests_per_min')
            ->selectRaw('AVG(unique_visitors) as avg_unique_visitors')
            ->selectRaw('SUM(bandwidth_bytes) as bandwidth_bytes')
            ->selectRaw('AVG(error_rate_pct) as avg_error_rate_pct')
            ->selectRaw('AVG(avg_response_time_ms) as avg_response_time_ms')
            ->where('recorded_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('day', recorded_at)::date")
            ->orderBy('day')
            ->get()
            ->map(static fn (object $row): array => [
                'day' => (string) $row->day,
                'avg_requests_per_min' => $row->avg_requests_per_min !== null ? round((float) $row->avg_requests_per_min, 2) : null,
                'avg_unique_visitors' => $row->avg_unique_visitors !== null ? round((float) $row->avg_unique_visitors, 2) : null,
                'bandwidth_bytes' => $row->bandwidth_bytes !== null ? (int) $row->bandwidth_bytes : null,
                'avg_error_rate_pct' => $row->avg_error_rate_pct !== null ? round((float) $row->avg_error_rate_pct, 2) : null,
                'avg_response_time_ms' => $row->avg_response_time_ms !== null ? round((float) $row->avg_response_time_ms, 2) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topAlerts(int $limit): array
    {
        return Alert::query()
            ->with(['site:id,name,domain'])
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderByDesc('triggered_at')
            ->limit(max(1, $limit))
            ->get(['id', 'site_id', 'title', 'severity', 'status', 'triggered_at'])
            ->map(static function (Alert $alert): array {
                return [
                    'id' => (int) $alert->id,
                    'site_id' => $alert->site_id !== null ? (int) $alert->site_id : null,
                    'site' => $alert->site !== null ? [
                        'name' => (string) $alert->site->name,
                        'domain' => (string) $alert->site->domain,
                    ] : null,
                    'title' => (string) $alert->title,
                    'severity' => (string) $alert->severity,
                    'status' => (string) $alert->status,
                    'triggered_at' => optional($alert->triggered_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function obsoleteTechnologies(int $limit): \Illuminate\Support\Collection
    {
        $rows = SiteTechnology::query()
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->join('sites', 'sites.id', '=', 'site_technologies.site_id')
            ->select([
                'site_technologies.id',
                'site_technologies.site_id',
                'site_technologies.version',
                'site_technologies.confidence_pct',
                'site_technologies.detected_at',
                'technologies.name as technology_name',
                'technologies.category as technology_category',
                'technologies.vendor as technology_vendor',
                'technologies.slug as technology_slug',
                'sites.name as site_name',
                'sites.domain as site_domain',
            ])
            ->orderByDesc('site_technologies.detected_at')
            ->limit(max(10, $limit * 4))
            ->get();

        return $rows
            ->map(static function (object $row): array {
                $detected = DetectedTechnology::fromArray([
                    'name' => $row->technology_name,
                    'version' => $row->version,
                    'category' => $row->technology_category,
                    'confidence' => $row->confidence_pct,
                    'vendor' => $row->technology_vendor,
                    'slug' => $row->technology_slug,
                ])->toFrontendArray();

                return [
                    'site' => [
                        'name' => (string) $row->site_name,
                        'domain' => (string) $row->site_domain,
                    ],
                    'technology' => $detected,
                    'detected_at' => optional($row->detected_at)?->toIso8601String() ?? null,
                ];
            })
            ->filter(static fn (array $item): bool => (bool) ($item['technology']['is_obsolete'] ?? false))
            ->take(max(1, $limit))
            ->values();
    }

    private function uptimePercentage(int $days): float
    {
        $windowStart = now()->subDays(max(1, $days));

        $summary = SiteCheck::query()
            ->where('checked_at', '>=', $windowStart)
            ->selectRaw('COUNT(*) as total_checks')
            ->selectRaw("SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_checks")
            ->first();

        $total = (int) ($summary?->total_checks ?? 0);

        if ($total === 0) {
            return 0.0;
        }

        $up = (int) ($summary?->up_checks ?? 0);

        return round(($up / $total) * 100, 2);
    }

    private function averageSiteResponseTime(int $days): ?float
    {
        $windowStart = now()->subDays(max(1, $days));

        $average = SiteCheck::query()
            ->where('checked_at', '>=', $windowStart)
            ->whereNotNull('response_time_ms')
            ->avg('response_time_ms');

        return $average !== null ? round((float) $average, 2) : null;
    }

    private function averageServerCpuUsage(int $days): ?float
    {
        $windowStart = now()->subDays(max(1, $days));

        $average = ServerMetric::query()
            ->where('recorded_at', '>=', $windowStart)
            ->whereNotNull('cpu_usage_pct')
            ->avg('cpu_usage_pct');

        return $average !== null ? round((float) $average, 2) : null;
    }

    private function averageSecurityScore(int $days): ?float
    {
        $windowStart = now()->subDays(max(1, $days));

        $average = SecurityScore::query()
            ->where('calculated_at', '>=', $windowStart)
            ->avg('score');

        return $average !== null ? round((float) $average, 2) : null;
    }
}
