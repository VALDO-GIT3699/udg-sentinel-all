<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Site;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface SiteRepositoryInterface
{
    public function all(): Collection;

    public function allMonitored(): Collection;

    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;

    public function findById(int $id): ?Site;

    public function findBySlug(string $slug): ?Site;

    public function findByDomain(string $domain): ?Site;

    public function create(array $data): Site;

    public function update(Site $site, array $data): bool;

    public function delete(Site $site): bool;

    public function countByStatus(?int $groupId = null): array;

    /**
     * @return Collection<int, mixed>
     */
    public function statusByGroup(): Collection;

    public function getDown(): Collection;

    public function getDegraded(): Collection;

    public function getByGroup(int $groupId): Collection;

    public function withLatestChecks(int $limit = 10): Collection;

    public function monitoredByGroup(int $groupId): Collection;

    public function monitoredByPriority(int $priority): Collection;

    public function dueForCheck(int $limit = 100): Collection;

    public function dueForSslScan(int $limit = 100): Collection;

    public function dueForSecurityHeaderScan(int $limit = 100): Collection;

    public function dueForTechnologyScan(int $limit = 100): Collection;
}
