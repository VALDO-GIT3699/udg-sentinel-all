<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use App\Repositories\EloquentSiteCheckRepository;
use App\Repositories\EloquentSiteRepository;
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

        $repo = new EloquentSiteRepository();
        $due = $repo->dueForCheck(50);

        $this->assertCount(1, $due);
        $this->assertSame('udg-principal', $due->first()?->slug);
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

        $repo = new EloquentSiteRepository();
        $page = $repo->paginate(50);

        $this->assertCount(2, $page->items());
        $this->assertSame('biblioteca.cucosta.udg.mx', $page->items()[0]->domain);
        $this->assertSame('up', $page->items()[0]->current_status);
        $this->assertSame('cucosta.udg.mx', $page->items()[1]->domain);
        $this->assertSame('down', $page->items()[1]->current_status);
        $this->assertSame(['up' => 1, 'down' => 1, 'degraded' => 0, 'unknown' => 0], $repo->countByStatus());
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

        $repo = new EloquentSiteCheckRepository();

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
}
