<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\SiteGroup;
use Illuminate\Database\Eloquent\Collection;

interface SiteGroupRepositoryInterface
{
    public function all(): Collection;

    public function findById(int $id): ?SiteGroup;

    public function findBySlug(string $slug): ?SiteGroup;

    public function create(array $data): SiteGroup;

    public function update(SiteGroup $group, array $data): bool;

    public function delete(SiteGroup $group): bool;

    public function withSiteCount(): Collection;

    public function withMonitoredSiteCount(): Collection;
}
