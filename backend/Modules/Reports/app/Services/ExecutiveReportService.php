<?php

declare(strict_types=1);

namespace Modules\Reports\Services;

use App\Models\Alert;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Modules\Analytics\Services\AnalyticsService;

final class ExecutiveReportService
{
    public function __construct(private readonly AnalyticsService $analyticsService)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = []): array
    {
        $summary = $this->analyticsService->executiveSummary();

        $siteQuery = Site::query()->where('is_active', true);

        if (($filters['asset_type'] ?? 'all') !== 'all') {
            $siteQuery->where('asset_type', (string) $filters['asset_type']);
        }

        if (($filters['asset_role'] ?? 'all') !== 'all') {
            $siteQuery->where('asset_role', (string) $filters['asset_role']);
        }

        if (($filters['status'] ?? 'all') !== 'all') {
            $siteQuery->where('current_status', (string) $filters['status']);
        }

        $sites = $siteQuery->orderByDesc('priority')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'domain', 'current_status', 'asset_type', 'asset_role', 'asset_confidence_pct'])
            ->map(static fn (Site $site): array => [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
                'domain' => (string) $site->domain,
                'status' => (string) ($site->current_status ?? 'unknown'),
                'asset_type' => (string) ($site->asset_type ?? 'unknown'),
                'asset_role' => (string) ($site->asset_role ?? 'unknown'),
                'confidence_pct' => (int) ($site->asset_confidence_pct ?? 0),
            ])
            ->values()
            ->all();

        $openAlerts = Alert::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->when(($filters['severity'] ?? 'all') !== 'all', fn ($query) => $query->where('severity', (string) $filters['severity']))
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderByDesc('triggered_at')
            ->limit(50)
            ->get(['id', 'site_id', 'title', 'severity', 'status', 'triggered_at'])
            ->map(static fn (Alert $alert): array => [
                'id' => (int) $alert->id,
                'site_id' => $alert->site_id !== null ? (int) $alert->site_id : null,
                'title' => (string) $alert->title,
                'severity' => (string) $alert->severity,
                'status' => (string) $alert->status,
                'triggered_at' => optional($alert->triggered_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'filters' => $filters,
            'summary' => $summary,
            'sites' => $sites,
            'open_alerts' => $openAlerts,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function toCsv(array $filters = []): string
    {
        $report = $this->build($filters);
        $lines = [
            'tipo,valor',
            'salud_institucional,' . ($report['summary']['kpis']['institutional_health_pct'] ?? 0),
            'disponibilidad_24h,' . ($report['summary']['kpis']['availability_24h_pct'] ?? 0),
            'cobertura_inventario,' . ($report['summary']['kpis']['inventory_coverage_pct'] ?? 0),
            'alertas_abiertas,' . ($report['summary']['kpis']['open_alerts'] ?? 0),
            'incidentes_abiertos,' . ($report['summary']['kpis']['open_incidents'] ?? 0),
        ];

        return implode("\n", $lines) . "\n";
    }
}
