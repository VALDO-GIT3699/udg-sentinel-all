<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\CmsDetail;
use App\Models\DrupalModule;
use App\Models\OfficialBaselineSite;
use App\Models\OfficialBaselineSnapshot;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SiteGroup;
use App\Models\SiteTechnology;
use App\Models\Technology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
    public function it_detects_drupal_when_generator_has_major_version_only(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-tech-v2',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal Drupal Major',
            'slug' => 'portal-drupal-major',
            'domain' => 'www.example.com',
            'url' => 'https://www.example.com',
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

            if ($url === 'https://www.example.com') {
                return Http::response(
                    '<html><head><meta name="generator" content="Drupal 10"></head><body>'.
                    '<script src="/core/misc/drupal.js"></script>'.
                    '</body></html>',
                    200,
                    ['Server' => 'nginx/1.24.0', 'X-Powered-By' => 'PHP/8.2.18'],
                );
            }

            if ($url === 'https://www.example.com/core/lib/Drupal.php') {
                return Http::response('<?php // Drupal core', 200, ['Content-Type' => 'text/plain']);
            }

            if ($url === 'https://www.example.com/core/themes/stable10/VERSION') {
                return Http::response('10.3.0', 200, ['Content-Type' => 'text/plain']);
            }

            return Http::response('', 404);
        });

        $job = new RunTechnologyScanJob($site->id);
        $job->handle(app(SiteRepositoryInterface::class), app(MonitoringHttpClientFactory::class));

        $cmsDetail = CmsDetail::query()->where('site_id', $site->id)->first();

        $this->assertNotNull($cmsDetail);
        $this->assertSame('drupal', $cmsDetail?->cms_type);
        $this->assertSame('10', $cmsDetail?->cms_version);

        $primaryCmsSlug = SiteTechnology::query()
            ->where('site_id', $site->id)
            ->where('is_primary', true)
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->value('technologies.slug');

        $this->assertSame('drupal-10', $primaryCmsSlug);
    }

    #[Test]
    public function it_avoids_wordpress_false_positive_when_only_wp_redirects_exist(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-tech-v3',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal ambiguo',
            'slug' => 'portal-ambiguo',
            'domain' => 'www.example.org',
            'url' => 'https://www.example.org',
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

            if ($url === 'https://www.example.org') {
                return Http::response(
                    '<html><head><title>Portal institucional</title></head><body>Contenido informativo</body></html>',
                    200,
                    ['Server' => 'nginx/1.24.0'],
                );
            }

            if (in_array($url, [
                'https://www.example.org/wp-json/',
                'https://www.example.org/wp-login.php',
                'https://www.example.org/wp-content/',
                'https://www.example.org/wp-includes/',
            ], true)) {
                return Http::response('', 301, ['Location' => 'https://www.example.org/']);
            }

            return Http::response('', 404);
        });

        $job = new RunTechnologyScanJob($site->id);
        $job->handle(app(SiteRepositoryInterface::class), app(MonitoringHttpClientFactory::class));

        $primaryCmsSlug = SiteTechnology::query()
            ->where('site_id', $site->id)
            ->where('is_primary', true)
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->value('technologies.slug');

        $this->assertSame('no-determinado', $primaryCmsSlug);
    }

    #[Test]
    public function it_keeps_no_determinado_when_contradictory_evidence_is_not_strong_enough(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-tech-v4',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal con evidencia ambigua',
            'slug' => 'portal-evidencia-ambigua',
            'domain' => 'www.example.com',
            'url' => 'https://www.example.com',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 2,
            'current_status' => 'up',
            'current_score' => 100,
            'current_score_level' => 'excellent',
            'last_checked_at' => null,
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        $snapshot = OfficialBaselineSnapshot::query()->create([
            'source_name' => 'true_sites.csv',
            'source_path' => '/tmp/true_sites.csv',
            'source_type' => 'csv',
            'source_hash' => hash('sha256', 'true-sites-v1'),
            'imported_by' => null,
            'is_current' => true,
            'total_rows' => 1,
            'unique_domains' => 1,
            'notes' => null,
            'imported_at' => now(),
        ]);

        OfficialBaselineSite::query()->create([
            'snapshot_id' => (int) $snapshot->id,
            'row_number' => 1,
            'normalized_domain' => 'www.example.com',
            'classification' => 'AG',
            'entity' => 'CGAI',
            'site_name' => 'Portal ambiguo',
            'domain' => 'https://www.example.com',
            'is_active' => true,
            'cms_label' => 'Drupal 10',
            'server_ip' => '',
            'certificate_label' => '',
            'project_status' => '',
            'comments' => '',
            'ticket_number' => null,
            'raw_payload' => null,
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if ($url === 'https://www.example.com') {
                return Http::response(
                    '<html><head><title>Portal institucional</title></head><body>'.
                    '<link rel="stylesheet" href="/assets/wp-content/theme.css">'.
                    '<script src="/assets/wp-includes/runtime.js"></script>'.
                    '</body></html>',
                    200,
                    ['Server' => 'nginx/1.24.0'],
                );
            }

            if ($url === 'https://www.example.com/wp-content/') {
                return Http::response('index', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('', 404);
        });

        $job = new RunTechnologyScanJob($site->id);
        $job->handle(app(SiteRepositoryInterface::class), app(MonitoringHttpClientFactory::class));

        $primaryCmsSlug = SiteTechnology::query()
            ->where('site_id', $site->id)
            ->where('is_primary', true)
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->value('technologies.slug');

        $this->assertSame('no-determinado', $primaryCmsSlug);

        $this->assertSame(0, SiteEvent::query()->where('site_id', $site->id)->where('event_type', 'monitoring.baseline_drift')->count());
    }

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
                    '<html><head><meta name="generator" content="Drupal 10.2.3"></head><body>'.
                    '<script src="/sites/all/themes/bootr4theme/app.js"></script>'.
                    '<script src="/modules/custom/drudg8b3/main.js"></script>'.
                    '<div>SQLSTATE[08006]: postgres connection refused</div>'.
                    '</body></html>',
                    200,
                    [
                        'Server' => 'nginx/1.24.0',
                        'X-Powered-By' => 'PHP/8.2.18',
                    ],
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

    #[Test]
    public function it_detects_cms_after_a_tls_certificate_failure_by_retrying_with_relaxed_verification(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-tls',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal TLS invalido',
            'slug' => 'portal-tls-invalido',
            'domain' => 'tls-invalido.udg.mx',
            'url' => 'https://tls-invalido.udg.mx',
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

        $primaryAttempts = 0;

        Http::fake(function (Request $request) use (&$primaryAttempts) {
            $url = $request->url();

            if ($url === 'https://tls-invalido.udg.mx') {
                $primaryAttempts++;

                // MonitoringHttpClientFactory reintenta 2 veces con el mismo cliente antes de
                // propagar la excepcion; el certificado sigue invalido en esos intentos y el
                // fallback con TLS relajado es el que finalmente obtiene respuesta.
                if ($primaryAttempts <= 2) {
                    throw new ConnectionException(
                        'cURL error 60: SSL certificate problem: certificate has expired',
                    );
                }

                return Http::response(
                    '<html><head><meta name="generator" content="WordPress 6.4"></head><body>'.
                    '<link rel="stylesheet" href="/wp-content/themes/twenty/style.css">'.
                    '</body></html>',
                    200,
                    ['X-Powered-By' => 'PHP/8.2.16'],
                );
            }

            return Http::response('', 404);
        });

        $job = new RunTechnologyScanJob($site->id);
        $job->handle(app(SiteRepositoryInterface::class), app(MonitoringHttpClientFactory::class));

        $slugs = SiteTechnology::query()
            ->where('site_id', $site->id)
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->pluck('technologies.slug')
            ->all();

        $this->assertContains('wordpress', $slugs, 'El fingerprint debio continuar tras el fallo TLS y detectar WordPress.');
        $this->assertContains('php', $slugs);

        $phpSlug = SiteTechnology::query()
            ->where('site_id', $site->id)
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->where('technologies.slug', 'php')
            ->value('site_technologies.version');

        $this->assertSame('8.2.16', $phpSlug, 'La version de PHP debe ser la evidencia real de X-Powered-By, no una inferida.');
    }

    #[Test]
    public function it_keeps_drupal_generic_when_evidence_exists_but_major_version_cannot_be_resolved(): void
    {
        // Se invoca classifyPrimaryTechnology() directamente con un fingerprint controlado
        // (sin probes reales) para aislar el caso: evidencia >= 2 pero sin pista de version.
        $job = new RunTechnologyScanJob(0);
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('classifyPrimaryTechnology');
        $method->setAccessible(true);

        $fingerprint = [
            'headers' => ['x-drupal-cache' => ['HIT']],
            'body_raw' => '<html><body><script src="/sites/all/modules/custom/main.js"></script></body></html>',
            'redirect_context' => [
                'is_redirect' => false,
                'is_external' => false,
                'final_accessible' => true,
                'final_url' => 'https://drupal-sin-version.example.test',
                'final_status' => 200,
            ],
            'probes' => [],
        ];

        $classification = $method->invoke($job, $fingerprint);

        $this->assertSame('drupal', $classification['slug']);
        $this->assertSame('drupal', $classification['cms_type']);
        $this->assertNull($classification['cms_version'], 'La version debe quedar nula (no determinada), no descartar el CMS completo.');
        $this->assertContains('x-drupal-cache', $classification['evidence']);
        $this->assertContains('drupal-sites-path', $classification['evidence']);
    }

    #[Test]
    public function php_session_cookie_alone_is_not_enough_to_classify_php(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-phpsessid-solo',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal solo cookie PHP',
            'slug' => 'portal-solo-cookie-php',
            'domain' => 'solo-cookie-php.example.test',
            'url' => 'https://solo-cookie-php.example.test',
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

            if ($url === 'https://solo-cookie-php.example.test') {
                return Http::response(
                    '<html><body>Portal institucional</body></html>',
                    200,
                    ['Set-Cookie' => 'PHPSESSID=abc123; path=/'],
                );
            }

            return Http::response('', 404);
        });

        $job = new RunTechnologyScanJob($site->id);
        $job->handle(app(SiteRepositoryInterface::class), app(MonitoringHttpClientFactory::class));

        $slugs = SiteTechnology::query()
            ->where('site_id', $site->id)
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->pluck('technologies.slug')
            ->all();

        $this->assertNotContains('php', $slugs, 'PHPSESSID por si solo no debe confirmar PHP.');
    }

    #[Test]
    public function php_session_cookie_combined_with_another_signal_reinforces_php_detection(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-phpsessid-combinado',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal cookie PHP combinada',
            'slug' => 'portal-cookie-php-combinada',
            'domain' => 'cookie-php-combinada.example.test',
            'url' => 'https://cookie-php-combinada.example.test',
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

            if ($url === 'https://cookie-php-combinada.example.test') {
                return Http::response(
                    '<html><body>Portal institucional</body></html>',
                    200,
                    [
                        'Set-Cookie' => 'PHPSESSID=abc123; path=/',
                        'Server' => 'Apache/2.4 PHP-emulation',
                    ],
                );
            }

            return Http::response('', 404);
        });

        $job = new RunTechnologyScanJob($site->id);
        $job->handle(app(SiteRepositoryInterface::class), app(MonitoringHttpClientFactory::class));

        $slugs = SiteTechnology::query()
            ->where('site_id', $site->id)
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->pluck('technologies.slug')
            ->all();

        $this->assertContains('php', $slugs, 'Con 2 senales (cookie + server) si debe reforzar la deteccion de PHP.');
    }

    #[Test]
    public function renamed_session_cookie_alone_does_not_classify_as_laravel(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-session-sola',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal cookie de sesion aislada',
            'slug' => 'portal-cookie-sesion-aislada',
            'domain' => 'sesion-aislada.example.test',
            'url' => 'https://sesion-aislada.example.test',
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

            if ($url === 'https://sesion-aislada.example.test') {
                return Http::response(
                    '<html><body>Portal institucional</body></html>',
                    200,
                    ['Set-Cookie' => 'mi_app_session=abc123; path=/'],
                );
            }

            return Http::response('', 404);
        });

        $job = new RunTechnologyScanJob($site->id);
        $job->handle(app(SiteRepositoryInterface::class), app(MonitoringHttpClientFactory::class));

        $slugs = SiteTechnology::query()
            ->where('site_id', $site->id)
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->pluck('technologies.slug')
            ->all();

        $this->assertNotContains('laravel', $slugs, 'Una cookie "*_session" aislada nunca debe bastar para clasificar Laravel.');
    }

    #[Test]
    public function xsrf_token_plus_renamed_session_cookie_plus_php_classifies_as_laravel(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-laravel-renombrado',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal Laravel con cookie renombrada',
            'slug' => 'portal-laravel-cookie-renombrada',
            'domain' => 'laravel-renombrado.example.test',
            'url' => 'https://laravel-renombrado.example.test',
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

            if ($url === 'https://laravel-renombrado.example.test') {
                return Http::response(
                    '<html><body>Portal institucional</body></html>',
                    200,
                    [
                        'Set-Cookie' => ['XSRF-TOKEN=abc123; path=/', 'mi_app_session=def456; path=/'],
                        'X-Powered-By' => 'PHP/8.2.32',
                    ],
                );
            }

            return Http::response('', 404);
        });

        $job = new RunTechnologyScanJob($site->id);
        $job->handle(app(SiteRepositoryInterface::class), app(MonitoringHttpClientFactory::class));

        $slugs = SiteTechnology::query()
            ->where('site_id', $site->id)
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->pluck('technologies.slug')
            ->all();

        $this->assertContains('laravel', $slugs, 'XSRF-TOKEN + cookie de sesion renombrada + PHP debe reconocerse como Laravel.');
    }

    #[Test]
    public function unconfigured_vhost_placeholder_is_classified_as_dominio_no_configurado(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-vhost-placeholder',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal dado de baja',
            'slug' => 'portal-dado-de-baja',
            'domain' => 'placeholder.example.test',
            'url' => 'https://placeholder.example.test',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 3,
            'current_status' => 'up',
            'current_score' => 100,
            'current_score_level' => 'excellent',
            'last_checked_at' => null,
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        Http::fake(function (Request $request) {
            return Http::response(
                '<html><head><title>P&aacute;gina Web no encontrada</title></head><body>'.
                '<h2>Por favor escriba de forma correcta la P&aacute;gina Web</h2>'.
                '<a href="http://www.udg.mx/">Sitio Web disponible</a>'.
                '</body></html>',
                200,
            );
        });

        $job = new RunTechnologyScanJob($site->id);
        $job->handle(app(SiteRepositoryInterface::class), app(MonitoringHttpClientFactory::class));

        $cmsDetail = CmsDetail::query()->where('site_id', $site->id)->first();

        $this->assertNotNull($cmsDetail);
        $this->assertSame('unconfigured', $cmsDetail?->cms_type);

        $slugs = SiteTechnology::query()
            ->where('site_id', $site->id)
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->pluck('technologies.slug')
            ->all();

        $this->assertEmpty($slugs, 'No debe persistirse ninguna tecnologia real para un vhost sin sitio configurado.');
    }

    #[Test]
    public function a_page_matching_only_one_placeholder_phrase_is_not_classified_as_unconfigured(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-falso-placeholder',
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        $site = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Portal con texto parecido',
            'slug' => 'portal-texto-parecido',
            'domain' => 'no-es-placeholder.example.test',
            'url' => 'https://no-es-placeholder.example.test',
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 3,
            'current_status' => 'up',
            'current_score' => 100,
            'current_score_level' => 'excellent',
            'last_checked_at' => null,
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);

        Http::fake(function (Request $request) {
            // Contiene la frase del titulo pero NO las otras dos frases distintivas
            // (instruccion + enlace de respaldo): no debe activar el detector de placeholder.
            return Http::response(
                '<html><head><title>Página Web no encontrada</title></head><body>'.
                '<p>Este contenido es un articulo real sobre un evento institucional.</p>'.
                '</body></html>',
                200,
            );
        });

        $job = new RunTechnologyScanJob($site->id);
        $job->handle(app(SiteRepositoryInterface::class), app(MonitoringHttpClientFactory::class));

        $cmsDetail = CmsDetail::query()->where('site_id', $site->id)->first();

        $this->assertNotSame('unconfigured', $cmsDetail?->cms_type);
    }
}
