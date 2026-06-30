<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\SiteCheck;
use Illuminate\Database\Eloquent\Collection;

interface SiteCheckRepositoryInterface
{
    public function latestForSite(int $siteId): ?SiteCheck;

    public function recentForSite(int $siteId, int $hours = 24): Collection;

    public function uptimePercentage(int $siteId, int $hours = 24): float;

    public function avgResponseTime(int $siteId, int $hours = 24): ?float;

    public function create(array $data): SiteCheck;

    public function pruneOlderThan(int $days): int;

    /**
     * @return array<string, int>
     */
    public function statusBreakdownForSite(int $siteId, int $hours = 24): array;

    /**
     * Timeline data points for charting.
     * @return Collection<int, SiteCheck>
     */
    public function timelineForSite(int $siteId, int $hours = 24, int $limit = 288): Collection;
}
