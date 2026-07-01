<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\SiteCheckRepositoryInterface;
use App\Models\SiteCheck;
use Illuminate\Database\Eloquent\Collection;

final class EloquentSiteCheckRepository implements SiteCheckRepositoryInterface
{
    public function latestForSite(int $siteId): ?SiteCheck
    {
        return SiteCheck::where('site_id', $siteId)
            ->orderByDesc('checked_at')
            ->first();
    }

    public function recentForSite(int $siteId, int $hours = 24): Collection
    {
        return SiteCheck::where('site_id', $siteId)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->orderBy('checked_at')
            ->get();
    }

    public function uptimePercentage(int $siteId, int $hours = 24): float
    {
        $total = SiteCheck::where('site_id', $siteId)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->count();

        if ($total === 0) {
            return 0.0;
        }

        $up = SiteCheck::where('site_id', $siteId)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->where('status', 'up')
            ->count();

        return round(($up / $total) * 100, 2);
    }

    public function avgResponseTime(int $siteId, int $hours = 24): ?float
    {
        $avg = SiteCheck::where('site_id', $siteId)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->where('status', 'up')
            ->whereNotNull('response_time_ms')
            ->avg('response_time_ms');

        return $avg !== null ? round((float) $avg, 2) : null;
    }

    public function create(array $data): SiteCheck
    {
        return SiteCheck::create($data);
    }

    public function pruneOlderThan(int $days): int
    {
        return SiteCheck::where('checked_at', '<', now()->subDays($days))->delete();
    }

    public function statusBreakdownForSite(int $siteId, int $hours = 24): array
    {
        $counts = SiteCheck::where('site_id', $siteId)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return array_merge([
            'up'       => 0,
            'down'     => 0,
            'degraded' => 0,
            'timeout'  => 0,
        ], $counts);
    }

    public function timelineForSite(int $siteId, int $hours = 24, int $limit = 288): Collection
    {
        return SiteCheck::where('site_id', $siteId)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->select(['id', 'site_id', 'checked_at', 'status', 'response_time_ms', 'http_code'])
            ->orderBy('checked_at')
            ->limit($limit)
            ->get();
    }

    public function recentTimeline(int $hours = 1, int $limit = 60): Collection
    {
        return SiteCheck::query()
            ->where('checked_at', '>=', now()->subHours($hours))
            ->whereHas('site', static function ($query): void {
                $query->where('is_active', true)->where('is_monitored', true);
            })
            ->with(['site:id,name,site_group_id', 'site.siteGroup:id,name'])
            ->select(['id', 'site_id', 'checked_at', 'status', 'response_time_ms', 'http_code'])
            ->orderByDesc('checked_at')
            ->limit($limit)
            ->get();
    }

    public function summarizeWindow(int $hours = 1): array
    {
        $summary = SiteCheck::query()
            ->where('checked_at', '>=', now()->subHours($hours))
            ->whereHas('site', static function ($query): void {
                $query->where('is_active', true)->where('is_monitored', true);
            })
            ->selectRaw("COUNT(*) as total_checks")
            ->selectRaw("SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_checks")
            ->selectRaw('AVG(response_time_ms) as avg_latency_ms')
            ->first();

        return [
            'total_checks' => (int) ($summary?->total_checks ?? 0),
            'down_checks' => (int) ($summary?->down_checks ?? 0),
            'avg_latency_ms' => $summary?->avg_latency_ms !== null ? round((float) $summary->avg_latency_ms, 2) : null,
        ];
    }

    public function consecutiveStatusCount(int $siteId, string $status, int $limit = 5): int
    {
        $checks = SiteCheck::where('site_id', $siteId)
            ->orderByDesc('checked_at')
            ->limit($limit)
            ->pluck('status');

        $count = 0;

        foreach ($checks as $checkStatus) {
            if ($checkStatus !== $status) {
                break;
            }

            $count++;
        }

        return $count;
    }
}
