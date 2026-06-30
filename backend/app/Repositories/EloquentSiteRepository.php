<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

final class EloquentSiteRepository implements SiteRepositoryInterface
{
    public function all(): Collection
    {
        return Site::with('siteGroup')->orderBy('name')->get();
    }

    public function allMonitored(): Collection
    {
        return Site::active()->monitored()->with('siteGroup')->orderBy('name')->get();
    }

    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator
    {
        $query = Site::with(['siteGroup', 'latestCheck', 'latestSecurityScore']);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('current_status', $filters['status']);
        }

        if (isset($filters['group_id'])) {
            $query->where('site_group_id', (int) $filters['group_id']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'ilike', $search)
                  ->orWhere('domain', 'ilike', $search);
            });
        }

        if (isset($filters['priority'])) {
            $query->where('priority', (int) $filters['priority']);
        }

        return $query->orderByRaw("
            CASE current_status
                WHEN 'down' THEN 1
                WHEN 'degraded' THEN 2
                WHEN 'up' THEN 3
                ELSE 4
            END
        ")->orderBy('priority')->paginate($perPage);
    }

    public function findById(int $id): ?Site
    {
        return Site::with([
            'siteGroup',
            'latestCheck',
            'sslCertificate',
            'cmsDetail.drupalModules',
            'latestSecurityScore',
            'latestSecurityHeader',
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

    public function countByStatus(): array
    {
        $counts = Site::active()
            ->selectRaw('current_status, COUNT(*) as total')
            ->groupBy('current_status')
            ->pluck('total', 'current_status')
            ->toArray();

        return array_merge([
            'up'       => 0,
            'down'     => 0,
            'degraded' => 0,
            'unknown'  => 0,
        ], $counts);
    }

    public function getDown(): Collection
    {
        return Site::active()->down()->with('siteGroup')->orderBy('priority')->get();
    }

    public function getDegraded(): Collection
    {
        return Site::active()->monitored()->where('current_status', 'degraded')
            ->with('siteGroup')->orderBy('priority')->get();
    }

    public function getByGroup(int $groupId): Collection
    {
        return Site::active()->where('site_group_id', $groupId)
            ->with('latestCheck')->orderBy('name')->get();
    }

    public function withLatestChecks(int $limit = 10): Collection
    {
        return Site::active()->monitored()
            ->with('latestCheck')
            ->orderBy('priority')
            ->limit($limit)
            ->get();
    }
}
