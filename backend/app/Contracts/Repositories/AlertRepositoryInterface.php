<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Alert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface AlertRepositoryInterface
{
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;

    public function findById(int $id): ?Alert;

    public function create(array $data): Alert;

    public function countOpen(): int;

    public function countCritical(): int;

    public function openForSite(int $siteId): Collection;

    public function recentOpen(int $limit = 20): Collection;

    public function recentOpenForGroup(int $groupId, int $limit = 20): Collection;

    public function acknowledge(Alert $alert, int $userId): bool;

    public function resolve(Alert $alert, int $userId): bool;
}
