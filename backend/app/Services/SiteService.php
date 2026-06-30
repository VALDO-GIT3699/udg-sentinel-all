<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\SiteGroupRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Models\SiteEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class SiteService
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly SiteGroupRepositoryInterface $groupRepo,
    ) {}

    public function paginate(int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->siteRepo->paginate($perPage, $filters);
    }

    public function findOrFail(int $id): Site
    {
        $site = $this->siteRepo->findById($id);

        if ($site === null) {
            abort(404, 'Sitio no encontrado.');
        }

        return $site;
    }

    public function findBySlugOrFail(string $slug): Site
    {
        $site = $this->siteRepo->findBySlug($slug);

        if ($site === null) {
            abort(404, 'Sitio no encontrado.');
        }

        return $site;
    }

    public function create(array $validated): Site
    {
        $validated['slug'] = $this->generateUniqueSlug($validated['name']);

        $site = $this->siteRepo->create($validated);

        SiteEvent::record(
            siteId: $site->id,
            eventType: 'site_created',
            title: "Sitio '{$site->name}' registrado en Sentinel",
            severity: 'info',
        );

        return $site;
    }

    public function update(Site $site, array $validated): bool
    {
        if (isset($validated['name']) && $validated['name'] !== $site->name) {
            $validated['slug'] = $this->generateUniqueSlug($validated['name'], $site->id);
        }

        return $this->siteRepo->update($site, $validated);
    }

    public function delete(Site $site): bool
    {
        return $this->siteRepo->delete($site);
    }

    public function allGroups(): Collection
    {
        return $this->groupRepo->withSiteCount();
    }

    private function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $slug = Str::slug($name);
        $base = $slug;
        $i    = 1;

        while (true) {
            $exists = Site::where('slug', $slug)
                ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists();

            if (!$exists) {
                break;
            }

            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
