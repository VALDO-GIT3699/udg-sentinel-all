<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Models\Alert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

final class EloquentAlertRepository implements AlertRepositoryInterface
{
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator
    {
        $query = Alert::with(['site', 'alertRule', 'acknowledgedBy', 'resolvedBy']);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['severity']) && $filters['severity'] !== 'all') {
            $query->where('severity', $filters['severity']);
        }

        if (isset($filters['site_id'])) {
            $query->where('site_id', (int) $filters['site_id']);
        }

        return $query->orderByDesc('triggered_at')->paginate($perPage);
    }

    public function findById(int $id): ?Alert
    {
        return Alert::with(['site', 'alertRule', 'notifications.channel', 'acknowledgedBy', 'resolvedBy'])
            ->find($id);
    }

    public function create(array $data): Alert
    {
        return Alert::create($data);
    }

    public function countOpen(): int
    {
        return Alert::open()->count();
    }

    public function countCritical(): int
    {
        return Alert::open()->critical()->count();
    }

    public function openForSite(int $siteId): Collection
    {
        return Alert::where('site_id', $siteId)->open()
            ->orderByDesc('triggered_at')->get();
    }

    public function recentOpen(int $limit = 20): Collection
    {
        return Alert::with('site')->unresolved()
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderByDesc('triggered_at')
            ->limit($limit)
            ->get();
    }

    public function acknowledge(Alert $alert, int $userId): bool
    {
        return $alert->acknowledge($userId);
    }

    public function resolve(Alert $alert, int $userId): bool
    {
        return $alert->resolve($userId);
    }
}
