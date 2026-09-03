<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\SiteTechnology;
use App\Models\Technology;
use App\Repositories\EloquentSiteCheckRepository;
use App\Repositories\EloquentSiteRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RepositoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function site_repository_returns_sites_due_for_check(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales oficiales',
            'slug' => 'portales-oficiales',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'UDG principal',
            'slug' => 'udg-principal',
            'domain' => 'udg.mx',
            'url' => 'https://udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'up',
            'current_score' => 90,
            'current_score_level' => 'good',
            'last_checked_at' => now()->subMinutes(30),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        $repo = new EloquentSiteRepository;
        $due = $repo->dueForCheck(50);

        $this->assertCount(1, $due);
        $this->assertSame('udg-principal', $due->first()?->slug);
    }

    #[Test]
    public function technology_scan_includes_inactive_sites_when_they_are_marked_as_monitored(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Inventario oficial',
            'slug' => 'inventario-oficial-tech',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio activo monitoreado',
            'slug' => 'sitio-activo-monitoreado',
            'domain' => 'activo-tech.udg.mx',
            'url' => 'https://activo-tech.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'up',
            'current_score' => 90,
            'current_score_level' => 'good',
            'last_checked_at' => now()->subHours(2),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio inactivo monitoreado',
            'slug' => 'sitio-inactivo-monitoreado',
            'domain' => 'inactivo-tech.udg.mx',
            'url' => 'https://inactivo-tech.udg.mx',
            'is_active' => false,
            'is_monitored' => true,
            'priority' => 3,
            'current_status' => 'unknown',
            'current_score' => 100,
            'current_score_level' => 'unknown',
            'last_checked_at' => null,
            'check_interval_min' => 15,
            'notes' => null,
            'tags' => [],
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio inactivo no monitoreado',
            'slug' => 'sitio-inactivo-no-monitoreado',
            'domain' => 'inactivo-no-tech.udg.mx',
            'url' => 'https://inactivo-no-tech.udg.mx',
            'is_active' => false,
            'is_monitored' => false,
            'priority' => 3,
            'current_status' => 'unknown',
            'current_score' => 100,
            'current_score_level' => 'unknown',
            'last_checked_at' => null,
            'check_interval_min' => 15,
            'notes' => null,
            'tags' => [],
        ]);

        $repo = new EloquentSiteRepository;
        $due = $repo->dueForTechnologyScan(50);

        $this->assertCount(2, $due);
        $this->assertEqualsCanonicalizing(
            ['sitio-activo-monitoreado', 'sitio-inactivo-monitoreado'],
            $due->pluck('slug')->all(),
        );
    }

    #[Test]
    public function site_repository_deduplicates_mirror_subdomains_but_preserves_distinct_services(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Centro Universitario de la Costa',
            'slug' => 'cucosta',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal principal CUCosta',
            'slug' => 'cucosta-principal',
            'domain' => 'cucosta.udg.mx',
            'url' => 'https://cucosta.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'down',
            'current_score' => 60,
            'current_score_level' => 'low',
            'last_checked_at' => now(),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal espejo CUCosta',
            'slug' => 'cucosta-www',
            'domain' => 'www.cucosta.udg.mx',
            'url' => 'https://www.cucosta.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 2,
            'current_status' => 'up',
            'current_score' => 92,
            'current_score_level' => 'good',
            'last_checked_at' => now()->subMinute(),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal alterno CUCosta',
            'slug' => 'cucosta-portal',
            'domain' => 'portal.cucosta.udg.mx',
            'url' => 'https://portal.cucosta.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 3,
            'current_status' => 'up',
            'current_score' => 88,
            'current_score_level' => 'good',
            'last_checked_at' => now()->subMinutes(2),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Biblioteca CUCosta',
            'slug' => 'cucosta-biblioteca',
            'domain' => 'biblioteca.cucosta.udg.mx',
            'url' => 'https://biblioteca.cucosta.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 2,
            'current_status' => 'up',
            'current_score' => 94,
            'current_score_level' => 'good',
            'last_checked_at' => now()->subMinutes(3),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        $repo = new EloquentSiteRepository;
        $page = $repo->paginate(50);

        $this->assertCount(2, $page->items());
        $this->assertSame('biblioteca.cucosta.udg.mx', $page->items()[0]->domain);
        $this->assertSame('up', $page->items()[0]->current_status);
        $this->assertSame('cucosta.udg.mx', $page->items()[1]->domain);
        $this->assertSame('down', $page->items()[1]->current_status);
        $this->assertSame(['up' => 1, 'down' => 1, 'degraded' => 0, 'unknown' => 0], $repo->countByStatus());
    }

    #[Test]
    public function dashboard_paginate_includes_full_inventory_by_default_and_can_filter_monitored_scope(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Inventario oficial',
            'slug' => 'inventario-oficial',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio activo',
            'slug' => 'sitio-activo',
            'domain' => 'activo.udg.mx',
            'url' => 'https://activo.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'up',
            'current_score' => 90,
            'current_score_level' => 'good',
            'last_checked_at' => now()->subMinute(),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio inactivo oficial',
            'slug' => 'sitio-inactivo-oficial',
            'domain' => 'inactivo.udg.mx',
            'url' => 'https://inactivo.udg.mx',
            'is_active' => false,
            'is_monitored' => false,
            'priority' => 3,
            'current_status' => 'unknown',
            'current_score' => 100,
            'current_score_level' => 'unknown',
            'last_checked_at' => null,
            'check_interval_min' => 15,
            'notes' => null,
            'tags' => [],
        ]);

        $repo = new EloquentSiteRepository;

        $allInventory = $repo->paginate(50);
        $onlyMonitored = $repo->paginate(50, ['inventory_scope' => 'monitored']);

        $this->assertSame(2, $allInventory->total());
        $this->assertSame(1, $onlyMonitored->total());
    }

    #[Test]
    public function site_check_repository_persists_checks_and_calculates_uptime(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Sistemas academicos',
            'slug' => 'sistemas-academicos',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#22C55E',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sistema escolar',
            'slug' => 'sistema-escolar',
            'domain' => 'escolar.udg.mx',
            'url' => 'https://escolar.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 2,
            'current_status' => 'unknown',
            'current_score' => 100,
            'current_score_level' => 'unknown',
            'last_checked_at' => null,
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        $repo = new EloquentSiteCheckRepository;

        $repo->create([
            'site_id' => $site->id,
            'checked_at' => now()->subMinutes(2),
            'status' => 'up',
            'http_code' => 200,
            'response_time_ms' => 320,
            'response_size_bytes' => 1024,
            'ip_resolved' => null,
            'redirect_url' => null,
            'error_message' => null,
            'checked_from' => 'test',
            'created_at' => now()->subMinutes(2),
        ]);

        $repo->create([
            'site_id' => $site->id,
            'checked_at' => now()->subMinute(),
            'status' => 'down',
            'http_code' => null,
            'response_time_ms' => 700,
            'response_size_bytes' => null,
            'ip_resolved' => null,
            'redirect_url' => null,
            'error_message' => 'timeout',
            'checked_from' => 'test',
            'created_at' => now()->subMinute(),
        ]);

        $this->assertSame(50.0, $repo->uptimePercentage($site->id, 24));
        $this->assertSame(320.0, $repo->avgResponseTime($site->id, 24));
    }

    #[Test]
    public function dashboard_cms_filter_uses_site_technologies_as_primary_source_and_avoids_false_positives(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Inventario CMS',
            'slug' => 'inventario-cms',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        // Caso 1: tecnología Drupal 10 + módulos/temas + php, perfil coherente.
        $caso1 = $this->createDashboardSite($group->id, 'Caso 1 Drupal 10', 'caso1.udg.mx');
        $this->attachTechnology($caso1, 'drupal', 'Drupal');
        $this->attachTechnology($caso1, 'drupal-10', 'Drupal 10');
        $this->attachTechnology($caso1, 'drupal-module-drudg8b3', 'Module drudg8b3');
        $this->attachTechnology($caso1, 'drupal-theme-drudg8b3', 'Theme drudg8b3');
        $this->attachTechnology($caso1, 'php', 'PHP');
        $caso1->inspectionProfile()->create(['cms_name' => 'Drupal', 'cms_version' => '10']);

        // Caso 2: tecnología Drupal 9 confiable, perfil desactualizado ("No determinado").
        $caso2 = $this->createDashboardSite($group->id, 'Caso 2 Drupal 9', 'caso2.udg.mx');
        $this->attachTechnology($caso2, 'drupal-9', 'Drupal 9');
        $caso2->inspectionProfile()->create(['cms_name' => 'No determinado', 'cms_version' => 'No determinado']);

        // Caso 3: solo tecnologías no-CMS (no-determinado, apache, php).
        $caso3 = $this->createDashboardSite($group->id, 'Caso 3 Sin CMS', 'caso3.udg.mx');
        $this->attachTechnology($caso3, 'no-determinado', 'No determinado');
        $this->attachTechnology($caso3, 'apache', 'Apache');
        $this->attachTechnology($caso3, 'php', 'PHP');
        $caso3->inspectionProfile()->create(['cms_name' => 'No determinado', 'cms_version' => 'No determinado']);

        // Caso 4: tecnología Laravel confiable, perfil "No determinado".
        $caso4 = $this->createDashboardSite($group->id, 'Caso 4 Laravel', 'caso4.udg.mx');
        $this->attachTechnology($caso4, 'laravel', 'Laravel');
        $this->attachTechnology($caso4, 'php', 'PHP');
        $caso4->inspectionProfile()->create(['cms_name' => 'No determinado', 'cms_version' => 'No determinado']);

        // Caso 5: solo runtime (php, apache), sin ningún CMS.
        $caso5 = $this->createDashboardSite($group->id, 'Caso 5 Runtime', 'caso5.udg.mx');
        $this->attachTechnology($caso5, 'php', 'PHP');
        $this->attachTechnology($caso5, 'apache', 'Apache');
        $caso5->inspectionProfile()->create(['cms_name' => 'No determinado', 'cms_version' => 'No determinado']);

        // Caso 6: sin tecnología drupal-9, evidencia solo vía inspection_profile ("9.5.11").
        $caso6 = $this->createDashboardSite($group->id, 'Caso 6 Drupal perfil 9', 'caso6.udg.mx');
        $caso6->inspectionProfile()->create(['cms_name' => 'Drupal', 'cms_version' => '9.5.11']);

        // Caso 7: sin tecnología drupal-10, evidencia solo vía inspection_profile ("10.9.2").
        $caso7 = $this->createDashboardSite($group->id, 'Caso 7 Drupal perfil 10', 'caso7.udg.mx');
        $caso7->inspectionProfile()->create(['cms_name' => 'Drupal', 'cms_version' => '10.9.2']);

        // Caso 8: tecnología drupal-10 Y perfil "10.9.2" (ambas fuentes coherentes).
        $caso8 = $this->createDashboardSite($group->id, 'Caso 8 Drupal 10 doble evidencia', 'caso8.udg.mx');
        $this->attachTechnology($caso8, 'drupal-10', 'Drupal 10');
        $caso8->inspectionProfile()->create(['cms_name' => 'Drupal', 'cms_version' => '10.9.2']);

        $repo = new EloquentSiteRepository;

        $drupalAny = $this->siteDomains($repo->paginate(50, ['cms' => 'drupal']));
        $this->assertEqualsCanonicalizing(
            ['caso1.udg.mx', 'caso2.udg.mx', 'caso6.udg.mx', 'caso7.udg.mx', 'caso8.udg.mx'],
            $drupalAny,
        );

        $drupal9 = $this->siteDomains($repo->paginate(50, ['cms' => 'drupal-9']));
        $this->assertEqualsCanonicalizing(['caso2.udg.mx', 'caso6.udg.mx'], $drupal9);

        $drupal10 = $this->siteDomains($repo->paginate(50, ['cms' => 'drupal-10']));
        $this->assertEqualsCanonicalizing(['caso1.udg.mx', 'caso7.udg.mx', 'caso8.udg.mx'], $drupal10);

        $laravel = $this->siteDomains($repo->paginate(50, ['cms' => 'laravel']));
        $this->assertSame(['caso4.udg.mx'], $laravel);

        $noDeterminado = $this->siteDomains($repo->paginate(50, ['cms' => 'no-determinado']));
        $this->assertEqualsCanonicalizing(['caso3.udg.mx', 'caso5.udg.mx'], $noDeterminado);
    }

    /**
     * @return array<int, string>
     */
    private function siteDomains(LengthAwarePaginator $paginator): array
    {
        return array_map(static fn (Site $site): string => (string) $site->domain, $paginator->items());
    }

    private function createDashboardSite(int $groupId, string $name, string $domain): Site
    {
        return Site::query()->create([
            'site_group_id' => $groupId,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'domain' => $domain,
            'url' => 'https://'.$domain,
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 2,
            'current_status' => 'up',
            'current_score' => 90,
            'current_score_level' => 'good',
            'last_checked_at' => now()->subMinute(),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);
    }

    private function attachTechnology(Site $site, string $slug, string $name): void
    {
        $technology = Technology::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'category' => 'cms', 'vendor' => 'n/a'],
        );

        SiteTechnology::query()->create([
            'site_id' => $site->id,
            'technology_id' => $technology->id,
            'version' => null,
            'confidence_pct' => 90,
            'is_primary' => true,
            'detected_at' => now(),
            'detection_method' => 'automatic',
            'metadata' => [],
        ]);
    }
}
