<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\CmsDetail;
use App\Models\DrupalModule;
use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\Technology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Monitoring\Jobs\RunTechnologyScanJob;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RunTechnologyScanJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_extracts_advanced_fingerprints_without_failing_the_monitoring_pipeline(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-tech',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal Drupal UDG',
            'slug' => 'portal-drupal-udg',
            'domain' => 'drupal.udg.mx',
            'url' => 'https://drupal.udg.mx',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'up',
            'current_score' => 100,
            'current_score_level' => 'excellent',
            'last_checked_at' => null,
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if ($url === 'https://drupal.udg.mx') {
                return Http::response(
                    '<html><head><meta name="generator" content="Drupal 10.2.3"></head><body>' .
                    '<script src="/sites/all/themes/bootr4theme/app.js"></script>' .
                    '<script src="/modules/custom/drudg8b3/main.js"></script>' .
                    '<div>SQLSTATE[08006]: postgres connection refused</div>' .
                    '</body></html>',
                    200,
                    [
                        'Server' => 'nginx/1.24.0',
                        'X-Powered-By' => 'PHP/8.2.18',
                    ]
                );
            }

            if ($url === 'https://drupal.udg.mx/core/CHANGELOG.txt') {
                return Http::response('Drupal 10.2.3, 2026-01-15', 200, ['Content-Type' => 'text/plain']);
            }

            if (in_array($url, [
                'https://drupal.udg.mx/readme.html',
                'https://drupal.udg.mx/readme.txt',
                'https://drupal.udg.mx/CHANGELOG.txt',
                'https://drupal.udg.mx/themes/',
                'https://drupal.udg.mx/modules/',
                'https://drupal.udg.mx/core/',
            ], true)) {
                return Http::response('', 404);
            }

            return Http::response('', 500);
        });

        $job = new RunTechnologyScanJob($site->id);
        $job->handle(app(SiteRepositoryInterface::class), app(MonitoringHttpClientFactory::class));

        $cmsDetail = CmsDetail::query()->where('site_id', $site->id)->first();

        $this->assertNotNull($cmsDetail);
        $this->assertSame('drupal', $cmsDetail?->cms_type);
        $this->assertSame('10.2.3', $cmsDetail?->cms_version);
        $this->assertSame('8.2.18', $cmsDetail?->php_version);
        $this->assertSame('PostgreSQL', $cmsDetail?->db_type);
        $this->assertSame('nginx/1.24.0', $cmsDetail?->server_software);
        $this->assertSame('bootr4theme', $cmsDetail?->theme_name);
        $this->assertSame(1, DrupalModule::query()->whereRelation('cmsDetail', 'site_id', $site->id)->where('module_name', 'drudg8b3')->count());

        $slugs = Technology::query()->pluck('slug')->all();

        $this->assertContains('drupal', $slugs);
        $this->assertContains('php', $slugs);
        $this->assertContains('nginx', $slugs);
        $this->assertContains('postgresql', $slugs);
        $this->assertContains('drupal-theme-bootr4theme', $slugs);
        $this->assertContains('drupal-module-drudg8b3', $slugs);
    }
}
