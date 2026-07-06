<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\Alert;
use App\Models\AssetClassification;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\SiteTechnology;
use App\Models\SslCertificate;
use Carbon\CarbonImmutable;

final class AnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function executiveSummary(): array
    {
        $now = CarbonImmutable::now();
        $last24h = $now->subDay();
        $last30d = $now->subDays(30);

        $totalAssets = (int) Site::query()->where('is_active', true)->count();
        $classifiedAssets = (int) Site::query()->where('is_active', true)->where('asset_type', '!=', 'unknown')->count();

        $uptime24h = round((float) (SiteCheck::query()
            ->where('checked_at', '>=', $last24h)
            ->selectRaw("AVG(CASE WHEN status = 'up' THEN 100.0 ELSE 0.0 END) as uptime_pct")
            ->value('uptime_pct') ?? 0.0), 2);

        $statusDistribution = Site::query()
            ->where('is_active', true)
            ->where('is_monitored', true)
            ->selectRaw("COALESCE(current_status, 'unknown') as key, COUNT(*) as total")
            ->groupBy('key')
            ->pluck('total', 'key')
            ->map(static fn ($value): int => (int) $value)
            ->toArray();

        $assetTypeDistribution = Site::query()
            ->where('is_active', true)
            ->selectRaw("COALESCE(asset_type, 'unknown') as key, COUNT(*) as total")
            ->groupBy('key')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(static fn ($row): array => ['key' => (string) $row->key, 'total' => (int) $row->total])
            ->values()
            ->all();

        $assetRoleDistribution = Site::query()
            ->where('is_active', true)
            ->selectRaw("COALESCE(asset_role, 'unknown') as key, COUNT(*) as total")
            ->groupBy('key')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(static fn ($row): array => ['key' => (string) $row->key, 'total' => (int) $row->total])
            ->values()
            ->all();

        $technologyDistribution = SiteTechnology::query()
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->selectRaw('technologies.slug as key, COUNT(*) as total')
            ->where('site_technologies.detected_at', '>=', $last30d)
            ->groupBy('technologies.slug')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(static fn ($row): array => ['key' => (string) $row->key, 'total' => (int) $row->total])
            ->values()
            ->all();

        $certificateAge = [
            'expired' => (int) SslCertificate::query()->where('is_expired', true)->count(),
            'expiring_30d' => (int) SslCertificate::query()->where('is_expired', false)->whereNotNull('days_remaining')->where('days_remaining', '<=', 30)->count(),
            'healthy' => (int) SslCertificate::query()->where('is_expired', false)->whereNotNull('days_remaining')->where('days_remaining', '>', 30)->count(),
        ];

        $alertsOpen = (int) Alert::query()->whereIn('status', ['open', 'acknowledged'])->count();
        $incidentsOpen = (int) Alert::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->where('context->event', 'site.status.incident')
            ->count();

        $classifierQuality = [
            'avg_confidence' => round((float) Site::query()->where('is_active', true)->avg('asset_confidence_pct'), 2),
            'manual_overrides' => (int) Site::query()->where('is_active', true)->where('asset_classification_source', 'manual')->count(),
            'low_confidence' => (int) Site::query()->where('is_active', true)->where('asset_confidence_pct', '<', 60)->count(),
        ];

        $recentChanges = AssetClassification::query()
            ->with('site:id,name,domain')
            ->orderByDesc('classified_at')
            ->limit(50)
            ->get()
            ->map(static fn (AssetClassification $item): array => [
                'site_id' => (int) $item->site_id,
                'site_name' => (string) ($item->site?->name ?? 'N/A'),
                'domain' => (string) ($item->site?->domain ?? ''),
                'asset_type' => (string) $item->asset_type,
                'asset_role' => (string) $item->asset_role,
                'confidence_pct' => (int) $item->confidence_pct,
                'source' => (string) $item->source,
                'classified_at' => optional($item->classified_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        $topCriticalAssets = Site::query()
            ->where('is_active', true)
            ->where('priority', 1)
            ->orderByRaw("CASE current_status WHEN 'down' THEN 1 WHEN 'degraded' THEN 2 WHEN 'up' THEN 3 ELSE 4 END")
            ->orderBy('asset_confidence_pct')
            ->limit(15)
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

        return [
            'kpis' => [
                'institutional_health_pct' => $totalAssets > 0
                    ? round((($statusDistribution['up'] ?? 0) / $totalAssets) * 100, 2)
                    : 0.0,
                'availability_24h_pct' => $uptime24h,
                'inventory_coverage_pct' => $totalAssets > 0
                    ? round(($classifiedAssets / $totalAssets) * 100, 2)
                    : 0.0,
                'open_alerts' => $alertsOpen,
                'open_incidents' => $incidentsOpen,
                'assets_total' => $totalAssets,
            ],
            'status_distribution' => $statusDistribution,
            'asset_type_distribution' => $assetTypeDistribution,
            'asset_role_distribution' => $assetRoleDistribution,
            'technology_distribution' => $technologyDistribution,
            'certificate_age' => $certificateAge,
            'classifier_quality' => $classifierQuality,
            'recent_changes' => $recentChanges,
            'top_critical_assets' => $topCriticalAssets,
            'generated_at' => $now->toIso8601String(),
        ];
    }
}
