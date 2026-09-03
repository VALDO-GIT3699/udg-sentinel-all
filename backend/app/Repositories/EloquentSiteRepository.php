<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Models\SiteGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
        $perPage = max(1, min(500, $perPage));

        return $this->dashboardInventoryQuery($filters)
            ->select('sites.*')
            ->selectRaw('LOWER(sites.name) as dashboard_sort_name')
            ->with(['latestCheck', 'sslCertificate', 'cmsDetail', 'siteTechnologies.technology', 'inspectionProfile'])
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
            'inspectionProfile',
            'sslCertificate',
            'cmsDetail.drupalModules',
            'siteTechnologies.technology',
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
            'up' => 0,
            'down' => 0,
            'degraded' => 0,
            'unknown' => 0,
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
                COUNT(*) FILTER (WHERE sites.is_active = true AND sites.is_monitored = true AND sites.current_status = 'unknown') as unknown_count",
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

    public function monitoredByGroup(int $groupId): Collection
    {
        return Site::active()->monitored()
            ->where('site_group_id', $groupId)
            ->with(['siteGroup', 'latestCheck'])
            ->orderBy('priority')
            ->orderBy('name')
            ->get();
    }

    public function monitoredByPriority(int $priority): Collection
    {
        return Site::active()->monitored()
            ->where('priority', $priority)
            ->with(['siteGroup', 'latestCheck'])
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
            ->with('siteGroup')
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
            ->with('siteGroup')
            ->orderBy('priority')
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();
    }

    public function dueForTechnologyScan(int $limit = 100): Collection
    {
        $hours = max(1, (int) env('SENTINEL_TECH_SCAN_INTERVAL', 24));

        return Site::monitored()
            ->where(function ($query) use ($hours): void {
                $query->whereDoesntHave('siteTechnologies')
                    ->orWhereHas('siteTechnologies', function ($technologyQuery) use ($hours): void {
                        $technologyQuery
                            ->whereNotNull('detected_at')
                            ->where('detected_at', '<=', now()->subHours($hours));
                    });
            })
            ->with('siteGroup')
            ->orderBy('priority')
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();
    }

    public function dueForAssetClassification(int $limit = 100): Collection
    {
        $hours = max(1, (int) env('SENTINEL_ASSET_CLASSIFICATION_INTERVAL', 24));

        return Site::active()->monitored()
            ->whereNull('asset_classification_locked_at')
            ->where(function (Builder $query) use ($hours): void {
                $query->whereNull('asset_last_classified_at')
                    ->orWhere('asset_last_classified_at', '<=', now()->subHours($hours));
            })
            ->with(['siteGroup', 'latestCheck'])
            ->orderBy('priority')
            ->orderBy('asset_last_classified_at')
            ->limit($limit)
            ->get();
    }

    private function dashboardInventoryQuery(array $filters = []): Builder
    {
        $query = Site::query();

        $this->applyDashboardFilters($query, $filters);

        return $query
            ->orderByRaw('LOWER(sites.name)')
            ->orderBy('sites.name')
            ->orderBy('sites.id');
    }

    private function applyDashboardFilters(Builder $query, array $filters): void
    {
        $inventoryScope = mb_strtolower(trim((string) ($filters['inventory_scope'] ?? 'all')));

        if ($inventoryScope === 'monitored') {
            $query->where('sites.is_active', true)
                ->where('sites.is_monitored', true);
        } elseif ($inventoryScope === 'active') {
            $query->where('sites.is_active', true);
        } elseif ($inventoryScope === 'inactive') {
            $query->where('sites.is_active', false);
        }

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('sites.current_status', $filters['status']);
        }

        if (isset($filters['group_id'])) {
            $query->where('sites.site_group_id', (int) $filters['group_id']);
        }

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $driver = Site::query()->getConnection()->getDriverName();
            $operator = $driver === 'pgsql' ? 'ilike' : 'like';
            $search = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $nestedQuery) use ($search, $operator): void {
                $nestedQuery->where('sites.name', $operator, $search)
                    ->orWhere('sites.domain', $operator, $search);
            });
        }

        if (isset($filters['cms']) && trim((string) $filters['cms']) !== '' && $filters['cms'] !== 'all') {
            $cms = mb_strtolower(trim((string) $filters['cms']));
            $driver = Site::query()->getConnection()->getDriverName();
            $operator = $driver === 'pgsql' ? 'ilike' : 'like';

            // CMS reconocidos vía site_technologies. "php" queda fuera: es un runtime que
            // convive con cualquier CMS, no debe descartar un sitio de "no determinado".
            $recognizedCmsSlugs = [
                'drupal', 'drupal-6', 'drupal-7', 'drupal-8', 'drupal-9', 'drupal-10', 'drupal-11',
                'wordpress', 'joomla', 'moodle', 'typo3', 'sharepoint', 'ghost', 'prestashop', 'magento',
                'laravel', 'wix',
            ];

            if ($cms === 'no-determinado') {
                // Un sitio solo es "no determinado" si ninguna fuente (technologies o
                // inspection_profile) reporta un CMS reconocido.
                $query->whereDoesntHave('siteTechnologies.technology', static function (Builder $technologyQuery) use ($recognizedCmsSlugs): void {
                    $technologyQuery->whereIn('technologies.slug', $recognizedCmsSlugs);
                });

                $query->where(function (Builder $profileQuery) use ($operator): void {
                    $profileQuery->whereDoesntHave('inspectionProfile')
                        ->orWhereHas('inspectionProfile', static function (Builder $profile) use ($operator): void {
                            $profile->where(function (Builder $cmsNameQuery) use ($operator): void {
                                $cmsNameQuery
                                    ->whereNull('cms_name')
                                    ->orWhere('cms_name', '')
                                    ->orWhere('cms_name', $operator, '%no determinado%')
                                    ->orWhere('cms_name', $operator, '%no identificad%');
                            });
                        });
                });
            } elseif ($cms === 'drupal') {
                $query->where(function (Builder $cmsQuery) use ($operator): void {
                    $cmsQuery
                        ->whereHas('siteTechnologies.technology', static function (Builder $technologyQuery): void {
                            $technologyQuery->whereIn('technologies.slug', [
                                'drupal', 'drupal-6', 'drupal-7', 'drupal-8', 'drupal-9', 'drupal-10', 'drupal-11',
                            ]);
                        })
                        ->orWhereHas('inspectionProfile', static function (Builder $profileQuery) use ($operator): void {
                            $profileQuery->where('cms_name', $operator, 'drupal%');
                        });
                });
            } elseif (preg_match('/^drupal-(6|7|8|9|10|11)$/', $cms, $matches) === 1) {
                $drupalMajor = $matches[1];
                $technologySlug = 'drupal-'.$drupalMajor;

                $query->where(function (Builder $cmsQuery) use ($technologySlug, $drupalMajor, $operator): void {
                    // Fuente principal: el slug de tecnología ya trae la versión mayor exacta,
                    // sin depender del genérico "drupal" ni de módulos/temas.
                    $cmsQuery->whereHas('siteTechnologies.technology', static function (Builder $technologyQuery) use ($technologySlug): void {
                        $technologyQuery->where('technologies.slug', $technologySlug);
                    });

                    // Respaldo: inspection_profile. Se compara por prefijo ("10.%") o valor
                    // exacto para no confundir "9" con "10.9.2" o "9.5.11".
                    $cmsQuery->orWhereHas('inspectionProfile', static function (Builder $profileQuery) use ($drupalMajor, $operator): void {
                        $profileQuery
                            ->where('cms_name', $operator, 'drupal%')
                            ->where(function (Builder $versionQuery) use ($drupalMajor, $operator): void {
                                $versionQuery
                                    ->where('cms_version', $drupalMajor)
                                    ->orWhere('cms_version', $operator, $drupalMajor.'.%');
                            });
                    });
                });
            } else {
                $needle = str_replace('-', ' ', $cms);

                $query->where(function (Builder $cmsQuery) use ($cms, $needle, $operator): void {
                    $cmsQuery
                        ->whereHas('siteTechnologies.technology', static function (Builder $technologyQuery) use ($cms): void {
                            $technologyQuery->where('technologies.slug', $cms);
                        })
                        ->orWhereHas('inspectionProfile', static function (Builder $profileQuery) use ($cms, $needle, $operator): void {
                            if ($cms === 'php') {
                                $profileQuery
                                    ->where('runtime_name', $operator, '%php%')
                                    ->orWhere('cms_name', $operator, '%php%');

                                return;
                            }

                            $profileQuery->where('cms_name', $operator, '%'.$needle.'%');
                        });
                });
            }
        }

        if (isset($filters['priority'])) {
            $query->where('sites.priority', (int) $filters['priority']);
        }

        if (isset($filters['lifecycle_status']) && trim((string) $filters['lifecycle_status']) !== '') {
            $query->where('sites.lifecycle_status', trim((string) $filters['lifecycle_status']));
        }

        if (isset($filters['php_version']) && trim((string) $filters['php_version']) !== '') {
            $needle = trim((string) $filters['php_version']);
            $driver = Site::query()->getConnection()->getDriverName();
            $operator = $driver === 'pgsql' ? 'ilike' : 'like';

            $query->whereHas('siteTechnologies.technology', static function (Builder $technologyQuery) use ($needle, $operator): void {
                $technologyQuery->where('technologies.slug', 'php')
                    ->where('site_technologies.version', $operator, $needle.'%');
            });
        }

        if (isset($filters['expires_in_days']) && (int) $filters['expires_in_days'] > 0) {
            $days = (int) $filters['expires_in_days'];
            $query->whereHas('sslCertificate', static function (Builder $sslQuery) use ($days): void {
                $sslQuery->whereNotNull('valid_until')
                    ->where('valid_until', '>=', now())
                    ->where('valid_until', '<=', now()->addDays($days));
            });
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
