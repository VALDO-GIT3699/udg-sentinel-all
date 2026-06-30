<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\SiteGroupRepositoryInterface;
use App\Models\SiteGroup;
use Illuminate\Database\Eloquent\Collection;

final class EloquentSiteGroupRepository implements SiteGroupRepositoryInterface
{
    public function all(): Collection
    {
        return SiteGroup::ordered()->get();
    }

    public function findById(int $id): ?SiteGroup
    {
        return SiteGroup::find($id);
    }

    public function findBySlug(string $slug): ?SiteGroup
    {
        return SiteGroup::where('slug', $slug)->first();
    }

    public function create(array $data): SiteGroup
    {
        return SiteGroup::create($data);
    }

    public function update(SiteGroup $group, array $data): bool
    {
        return $group->update($data);
    }

    public function delete(SiteGroup $group): bool
    {
        return (bool) $group->delete();
    }

    public function withSiteCount(): Collection
    {
        return SiteGroup::withCount('sites')->ordered()->get();
    }
}
