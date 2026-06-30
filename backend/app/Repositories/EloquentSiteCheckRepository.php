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
}
