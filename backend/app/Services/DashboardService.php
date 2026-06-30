<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteCheckRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

final class DashboardService
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly SiteCheckRepositoryInterface $checkRepo,
        private readonly AlertRepositoryInterface $alertRepo,
    ) {}

    /**
     * Returns all metrics needed to render the main dashboard.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        return Cache::remember('dashboard:metrics', now()->addMinutes(1), function (): array {
            $statusCounts  = $this->siteRepo->countByStatus();
            $totalSites    = array_sum($statusCounts);
            $openAlerts    = $this->alertRepo->countOpen();
            $criticalAlerts = $this->alertRepo->countCritical();

            $avgUptime = $this->computeAverageUptime24h();

            return [
                'total_sites'      => $totalSites,
                'sites_up'         => $statusCounts['up'] ?? 0,
                'sites_down'       => $statusCounts['down'] ?? 0,
                'sites_degraded'   => $statusCounts['degraded'] ?? 0,
                'sites_unknown'    => $statusCounts['unknown'] ?? 0,
                'open_alerts'      => $openAlerts,
                'critical_alerts'  => $criticalAlerts,
                'avg_uptime_24h'   => $avgUptime,
            ];
        });
    }

    /**
     * Returns recently failing sites for the dashboard feed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentIssues(): array
    {
        $down     = $this->siteRepo->getDown();
        $degraded = $this->siteRepo->getDegraded();

        return $down->merge($degraded)
            ->sortBy('priority')
            ->take(10)
            ->map(fn (Site $site): array => [
                'id'            => $site->id,
                'name'          => $site->name,
                'domain'        => $site->domain,
                'status'        => $site->current_status,
                'score'         => $site->current_score,
                'last_checked'  => $site->last_checked_at?->toISOString(),
                'group'         => $site->siteGroup?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Returns open alerts sorted by severity.
     *
     * @return array<int, mixed>
     */
    public function getOpenAlerts(int $limit = 20): array
    {
        return $this->alertRepo->recentOpen($limit)->all();
    }

    private function computeAverageUptime24h(): float
    {
        $monitored = $this->siteRepo->allMonitored();

        if ($monitored->isEmpty()) {
            return 0.0;
        }

        $total = $monitored->sum(
            fn (Site $site): float => $this->checkRepo->uptimePercentage($site->id, 24)
        );

        return round($total / $monitored->count(), 2);
    }
}
