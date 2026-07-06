<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Models\SiteGroup;
use App\Support\AssetIntelligenceSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentSiteRepository implements SiteRepositoryInterface
{
    public function __construct(private readonly AssetIntelligenceSchema $assetSchema)
    {
    }

    public function all(): Collection
    {
        return Site::with(['siteGroup', 'sslCertificate', 'cmsDetail', 'primaryServer'])->orderBy('name')->get();
    }

    public function allMonitored(): Collection
    {
        return Site::active()->monitored()->with(['siteGroup', 'sslCertificate', 'cmsDetail', 'primaryServer'])->orderBy('name')->get();
    }

    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(500, $perPage));

        return $this->dashboardInventoryQuery($filters)
            ->selectRaw('LOWER(sites.name) as dashboard_sort_name')
            ->with(['latestCheck', 'siteGroup', 'sslCertificate', 'cmsDetail', 'primaryServer'])
            ->orderBy('dashboard_sort_name')
            ->orderBy('sites.name')
            ->orderBy('sites.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findById(int $id): ?Site
    {
        return Site::with([
            'siteGroup',
            'latestCheck',
            'sslCertificate',
            'cmsDetail.drupalModules',
            'primaryServer',
            'siteTechnologies.technology',
            'latestSecurityScore',
            'latestSecurityHeader',
            'latestAssetClassification',
            'vulnerabilities' => fn ($q) => $q->active()->orderByDesc('detected_at'),
        ])->find($id);
    }

    public function findBySlug(string $slug): ?Site
    {
        return Site::where('slug', $slug)->first();
    }

    public function findByDomain(string $domain): ?Site
    {
        return Site::where('domain', $domain)->first();
    }

    public function create(array $data): Site
    {
        return Site::create($data);
    }

    public function update(Site $site, array $data): bool
    {
        return $site->update($data);
    }

    public function delete(Site $site): bool
    {
        return (bool) $site->delete();
    }

    public function countByStatus(?int $groupId = null): array
    {
        $filters = [];

        if ($groupId !== null) {
            $filters['group_id'] = $groupId;
        }

        $query = $this->dashboardInventoryQuery($filters);

        $query->getQuery()->orders = [];

        $counts = $query
            ->select('sites.current_status as current_status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('sites.current_status')
            ->pluck('total', 'current_status')
            ->toArray();

        return array_merge([
            'up'       => 0,
            'down'     => 0,
            'degraded' => 0,
            'unknown'  => 0,
        ], $counts);
    }

    public function statusByGroup(): Collection
    {
        return SiteGroup::query()
            ->selectRaw(
                "site_groups.id, site_groups.name, site_groups.slug,
                COUNT(*) FILTER (WHERE sites.is_active = true AND sites.is_monitored = true) as monitored_sites_count,
                COUNT(*) FILTER (WHERE sites.is_active = true AND sites.is_monitored = true AND sites.current_status = 'up') as up_count,
                COUNT(*) FILTER (WHERE sites.is_active = true AND sites.is_monitored = true AND sites.current_status = 'degraded') as degraded_count,
                COUNT(*) FILTER (WHERE sites.is_active = true AND sites.is_monitored = true AND sites.current_status = 'down') as down_count,
                COUNT(*) FILTER (WHERE sites.is_active = true AND sites.is_monitored = true AND sites.current_status = 'unknown') as unknown_count"
            )
            ->leftJoin('sites', 'sites.site_group_id', '=', 'site_groups.id')
            ->groupBy('site_groups.id', 'site_groups.name', 'site_groups.slug')
            ->orderBy('site_groups.name')
            ->get();
    }

    public function getDown(): Collection
    {
        return Site::active()->down()->with('siteGroup')->orderBy('priority')->get();
    }

    public function getDegraded(): Collection
    {
        return Site::active()->monitored()->where('current_status', 'degraded')
            ->with(['siteGroup', 'sslCertificate', 'cmsDetail', 'primaryServer'])->orderBy('priority')->get();
    }

    public function getByGroup(int $groupId): Collection
    {
        return Site::active()->where('site_group_id', $groupId)
            ->with(['latestCheck', 'siteGroup', 'sslCertificate', 'cmsDetail', 'primaryServer'])->orderBy('name')->get();
    }

    public function withLatestChecks(int $limit = 10): Collection
    {
        return Site::active()->monitored()
            ->with(['latestCheck', 'siteGroup', 'sslCertificate', 'cmsDetail', 'primaryServer'])
            ->orderBy('priority')
            ->limit($limit)
            ->get();
    }

    public function monitoredByGroup(int $groupId): Collection
    {
        return Site::active()->monitored()
            ->where('site_group_id', $groupId)
            ->with(['siteGroup', 'latestCheck', 'sslCertificate', 'cmsDetail', 'primaryServer'])
            ->orderBy('priority')
            ->orderBy('name')
            ->get();
    }

    public function monitoredByPriority(int $priority): Collection
    {
        return Site::active()->monitored()
            ->where('priority', $priority)
            ->with(['siteGroup', 'latestCheck', 'sslCertificate', 'cmsDetail', 'primaryServer'])
            ->orderBy('name')
            ->get();
    }

    public function dueForCheck(int $limit = 100): Collection
    {
        $driver = Site::query()->getConnection()->getDriverName();

        return Site::active()->monitored()
            ->where(function ($query) use ($driver): void {
                $query->whereNull('last_checked_at');

                if ($driver === 'sqlite') {
                    $query->orWhereRaw("last_checked_at <= datetime('now', '-' || check_interval_min || ' minutes')");
                    return;
                }

                if ($driver === 'pgsql') {
                    $query->orWhereRaw('EXTRACT(EPOCH FROM (NOW() - last_checked_at)) / 60 >= check_interval_min');
                    return;
                }

                $query->orWhereRaw('TIMESTAMPDIFF(MINUTE, last_checked_at, NOW()) >= check_interval_min');
            })
            ->with('siteGroup')
            ->orderBy('priority')
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();
    }

    public function dueForSslScan(int $limit = 100): Collection
    {
        $hours = max(1, (int) env('SENTINEL_SSL_SCAN_INTERVAL', 24));

        return Site::active()->monitored()
            ->where(function ($query) use ($hours): void {
                $query->whereDoesntHave('sslCertificates')
                    ->orWhereHas('sslCertificates', function ($certificateQuery) use ($hours): void {
                        $certificateQuery
                            ->whereNotNull('last_checked_at')
                            ->where('last_checked_at', '<=', now()->subHours($hours));
                    });
            })
            ->with(['siteGroup', 'sslCertificate', 'cmsDetail', 'primaryServer'])
            ->orderBy('priority')
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();
    }

    public function dueForSecurityHeaderScan(int $limit = 100): Collection
    {
        $hours = max(1, (int) env('SENTINEL_SECURITY_SCAN_INTERVAL', 12));

        return Site::active()->monitored()
            ->where(function ($query) use ($hours): void {
                $query->whereDoesntHave('securityHeaders')
                    ->orWhereHas('securityHeaders', function ($headerQuery) use ($hours): void {
                        $headerQuery
                            ->whereNotNull('checked_at')
                            ->where('checked_at', '<=', now()->subHours($hours));
                    });
            })
            ->with(['siteGroup', 'sslCertificate', 'cmsDetail', 'primaryServer'])
            ->orderBy('priority')
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();
    }

    public function dueForTechnologyScan(int $limit = 100): Collection
    {
        $hours = max(1, (int) env('SENTINEL_TECH_SCAN_INTERVAL', 24));

        return Site::active()->monitored()
            ->where(function ($query) use ($hours): void {
                $query->whereDoesntHave('siteTechnologies')
                    ->orWhereHas('siteTechnologies', function ($technologyQuery) use ($hours): void {
                        $technologyQuery
                            ->whereNotNull('detected_at')
                            ->where('detected_at', '<=', now()->subHours($hours));
                    });
            })
            ->with(['siteGroup', 'sslCertificate', 'cmsDetail', 'primaryServer'])
            ->orderBy('priority')
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();
    }

    public function dueForAssetClassification(int $limit = 100): Collection
    {
        if (! $this->assetSchema->isReady()) {
            return new Collection();
        }

        $hours = max(1, (int) env('SENTINEL_ASSET_CLASSIFICATION_INTERVAL', 24));

        return Site::active()->monitored()
            ->whereNull('asset_classification_locked_at')
            ->where(function (Builder $query) use ($hours): void {
                $query->whereNull('asset_last_classified_at')
                    ->orWhere('asset_last_classified_at', '<=', now()->subHours($hours));
            })
            ->with([
                'siteGroup',
                'latestCheck',
                'sslCertificate',
                'siteTechnologies.technology',
                'latestSecurityHeader',
            ])
            ->orderBy('priority')
            ->orderBy('asset_last_classified_at')
            ->limit($limit)
            ->get();
    }

    private function dashboardInventoryQuery(array $filters = []): Builder
    {
        $baseQuery = Site::query()->select('sites.*');

        $this->applyDashboardFilters($baseQuery, $filters);

        $rankedQuery = (clone $baseQuery)
            ->selectRaw($this->dashboardCanonicalDomainSql() . ' as dashboard_canonical_domain')
            ->selectRaw(
                'ROW_NUMBER() OVER ('
                . 'PARTITION BY sites.site_group_id, ' . $this->dashboardCanonicalDomainSql() . ' '
                . 'ORDER BY '
                . "CASE sites.current_status WHEN 'down' THEN 1 WHEN 'degraded' THEN 2 WHEN 'up' THEN 3 ELSE 4 END, "
                . 'sites.priority ASC, '
                . 'CASE WHEN sites.last_checked_at IS NULL THEN 1 ELSE 0 END ASC, '
                . 'sites.last_checked_at DESC, '
                . 'sites.id ASC'
                . ') as dashboard_duplicate_rank'
            );

        return Site::query()
            ->select('sites.*')
            ->joinSub($rankedQuery, 'dashboard_ranked_sites', static function ($join): void {
                $join->on('dashboard_ranked_sites.id', '=', 'sites.id');
            })
            ->where('dashboard_ranked_sites.dashboard_duplicate_rank', 1)
            ->orderBy('sites.name')
            ->orderBy('sites.id');
    }

    private function applyDashboardFilters(Builder $query, array $filters): void
    {
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('sites.current_status', $filters['status']);
        }

        if (isset($filters['group_id'])) {
            $query->where('sites.site_group_id', (int) $filters['group_id']);
        }

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $driver = Site::query()->getConnection()->getDriverName();
            $operator = $driver === 'pgsql' ? 'ilike' : 'like';
            $search = '%' . trim((string) $filters['search']) . '%';
            $query->where(function (Builder $nestedQuery) use ($search, $operator): void {
                $nestedQuery->where('sites.name', $operator, $search)
                    ->orWhere('sites.domain', $operator, $search);
            });
        }

        if (isset($filters['priority'])) {
            $query->where('sites.priority', (int) $filters['priority']);
        }

        if ($this->assetSchema->isReady() && isset($filters['asset_type']) && $filters['asset_type'] !== 'all') {
            $query->where('sites.asset_type', (string) $filters['asset_type']);
        }

        if ($this->assetSchema->isReady() && isset($filters['asset_role']) && $filters['asset_role'] !== 'all') {
            $query->where('sites.asset_role', (string) $filters['asset_role']);
        }

        if ($this->assetSchema->isReady() && isset($filters['classification_mode']) && $filters['classification_mode'] !== 'all') {
            $mode = (string) $filters['classification_mode'];

            if ($mode === 'manual') {
                $query->where('sites.asset_classification_source', 'manual');
            }

            if ($mode === 'automatic') {
                $query->where('sites.asset_classification_source', 'automatic');
            }
        }

        if ($this->assetSchema->isReady() && isset($filters['confidence_min'])) {
            $query->where('sites.asset_confidence_pct', '>=', (int) $filters['confidence_min']);
        }

        if ($this->assetSchema->isReady() && isset($filters['confidence_max'])) {
            $query->where('sites.asset_confidence_pct', '<=', (int) $filters['confidence_max']);
        }
    }

    private function dashboardCanonicalDomainSql(): string
    {
        $driver = Site::query()->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            return "REGEXP_REPLACE(LOWER(sites.domain), '^(?:(?:www\\d*|portal\\d*|web\\d*|home)\\.)+', '')";
        }

        return $this->stripMirrorPrefixesSql('LOWER(sites.domain)');
    }

    private function stripMirrorPrefixesSql(string $expression): string
    {
        return sprintf(
            "REPLACE(REPLACE(REPLACE(REPLACE(%s, 'www.', ''), 'portal.', ''), 'web.', ''), 'home.', '')",
            $expression,
        );
    }
}
