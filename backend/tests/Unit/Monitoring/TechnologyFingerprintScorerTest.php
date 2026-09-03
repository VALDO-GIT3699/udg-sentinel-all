<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use Modules\Monitoring\Services\SiteInspection\TechnologyFingerprintScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TechnologyFingerprintScorerTest extends TestCase
{
    #[Test]
    public function it_prefers_wordpress_over_weak_laravel_signals(): void
    {
        $scorer = app(TechnologyFingerprintScorer::class);

        $result = $scorer->score(
            headers: [
                'set-cookie' => 'XSRF-TOKEN=abc; path=/, wordpress_logged_in=1; path=/',
            ],
            body: '<html><head><meta name="csrf-token" content="abc"></head><body><link href="/wp-content/themes/site/style.css"><a href="/wp-includes/js/wp-embed.min.js"></a></body></html>',
            knownFiles: [
                '/wp-json/' => ['status' => 200, 'body' => '{"name":"WordPress Site"}'],
                '/readme.html' => ['status' => 404, 'body' => ''],
            ],
            inspectedUrl: 'https://portal.test',
            finalUrl: 'https://portal.test/',
        );

        $this->assertSame('WordPress', $result['detected']);
        $this->assertGreaterThanOrEqual(80, $result['confidence']);
        $this->assertContains('wp-content', $result['evidence']);
    }

    #[Test]
    public function it_detects_drupal_without_meta_generator_when_structural_signals_exist(): void
    {
        $scorer = app(TechnologyFingerprintScorer::class);

        $result = $scorer->score(
            headers: [
                'x-drupal-cache' => 'HIT',
            ],
            body: '<html><body><script type="application/json" data-drupal-selector="drupal-settings-json"></script><script src="/core/misc/drupal.js"></script></body></html>',
            knownFiles: [
                '/core/CHANGELOG.txt' => ['status' => 200, 'body' => 'Drupal 10.3.6, 2026-01-01'],
                '/core/misc/drupal.js' => ['status' => 200, 'body' => 'Drupal.behaviors = Drupal.behaviors || {};'],
            ],
            inspectedUrl: 'https://drupal.test',
            finalUrl: 'https://drupal.test/',
        );

        $this->assertSame('Drupal', $result['detected']);
        $this->assertSame('10.3.6', $result['version']);
        $this->assertContains('probe /core/CHANGELOG.txt = 200', $result['evidence']);
    }

    #[Test]
    public function it_detects_joomla_from_manifest_without_generator(): void
    {
        $scorer = app(TechnologyFingerprintScorer::class);

        $result = $scorer->score(
            headers: [],
            body: '<html><body><script src="/media/system/js/core.js"></script><a href="index.php?option=com_content"></a></body></html>',
            knownFiles: [
                '/administrator/manifests/files/joomla.xml' => ['status' => 200, 'body' => '<extension><version>5.2.1</version></extension>'],
                '/language/en-GB/en-GB.xml' => ['status' => 404, 'body' => ''],
            ],
            inspectedUrl: 'https://joomla.test',
            finalUrl: 'https://joomla.test/',
        );

        $this->assertSame('Joomla', $result['detected']);
        $this->assertSame('5.2.1', $result['version']);
    }

    #[Test]
    public function it_falls_back_to_php_runtime_when_no_cms_wins(): void
    {
        $scorer = app(TechnologyFingerprintScorer::class);

        $result = $scorer->score(
            headers: [
                'x-powered-by' => 'PHP/8.2.18',
                'set-cookie' => 'PHPSESSID=abcdef; path=/; HttpOnly',
            ],
            body: '<html><body><form action="/index.php/login"></form></body></html>',
            knownFiles: [
                '/robots.txt' => ['status' => 200, 'body' => 'User-agent: *'],
            ],
            inspectedUrl: 'https://php.test',
            finalUrl: 'https://php.test/',
        );

        $this->assertSame('PHP', $result['detected']);
        $this->assertSame('runtime', $result['category']);
        $this->assertSame('8.2.18', $result['version']);
    }

    #[Test]
    public function it_detects_laravel_from_xsrf_csrf_and_custom_session_cookie(): void
    {
        $scorer = app(TechnologyFingerprintScorer::class);

        $result = $scorer->score(
            headers: [
                'set-cookie' => 'XSRF-TOKEN=abc; path=/, cdu_session=encrypted; path=/; HttpOnly',
            ],
            body: '<html><head><meta name="csrf-token" content="abc"></head><body><title inertia>Portal</title><script src="/build/assets/app.js"></script><div id="app"></div></body></html>',
            knownFiles: [
                '/?rest_route=/' => ['status' => 200, 'body' => '<html>not a wordpress rest response</html>'],
            ],
            inspectedUrl: 'https://app.test',
            finalUrl: 'https://app.test/',
        );

        $this->assertSame('Laravel', $result['detected']);
        $this->assertGreaterThanOrEqual(80, $result['confidence']);
    }

    #[Test]
    public function a_404_probe_response_does_not_count_as_php_evidence(): void
    {
        $scorer = app(TechnologyFingerprintScorer::class);

        $result = $scorer->score(
            headers: [],
            body: '<html><body>Sitio estatico sin pistas de PHP</body></html>',
            knownFiles: [
                '/robots.txt' => ['status' => 404, 'body' => ''],
                '/license.txt' => ['status' => 404, 'body' => ''],
            ],
            inspectedUrl: 'https://estatico.test',
            finalUrl: 'https://estatico.test/',
        );

        $phpCandidate = collect($result['candidates'])->firstWhere('slug', 'php');
        $this->assertIsArray($phpCandidate);

        $probeRule = collect($phpCandidate['rules'])->firstWhere('heuristic', 'php_probe_files');
        $this->assertIsArray($probeRule);
        $this->assertFalse($probeRule['matched']);
        $this->assertSame(0, $phpCandidate['score']);
    }

    #[Test]
    public function it_detects_wix_from_static_asset_and_site_markers(): void
    {
        $scorer = app(TechnologyFingerprintScorer::class);

        $result = $scorer->score(
            headers: [],
            body: '<html><body><img src="https://static.wixstatic.com/media/foo.jpg"><script src="https://static.parastorage.com/services/wix-bolt.js"></script>Powered by wixsite.com</body></html>',
            knownFiles: [],
            inspectedUrl: 'https://museo.test',
            finalUrl: 'https://museo.test/',
        );

        $this->assertSame('Wix', $result['detected']);
        $this->assertSame('cms', $result['category']);
    }

    #[Test]
    public function it_detects_nextjs_on_vercel_from_server_and_response_headers(): void
    {
        $scorer = app(TechnologyFingerprintScorer::class);

        $result = $scorer->score(
            headers: [
                'server' => 'Vercel',
                'x-vercel-id' => 'cle1::iad1::8tkrh-1787975615126-2bc79b94a5e4',
                'x-vercel-cache' => 'MISS',
            ],
            body: '<html><body><link rel="preload" href="/_next/static/media/font.woff2"></body></html>',
            knownFiles: [],
            inspectedUrl: 'https://taller.test',
            finalUrl: 'https://taller.test/',
        );

        $this->assertSame('Next.js (Vercel)', $result['detected']);
        $this->assertSame('runtime', $result['category']);
    }

    #[Test]
    public function a_www_mirror_redirect_still_counts_as_the_same_host(): void
    {
        $scorer = app(TechnologyFingerprintScorer::class);

        $result = $scorer->score(
            headers: [
                'set-cookie' => 'wordpress_logged_in=1; path=/',
            ],
            body: '<html><body><link href="/wp-content/themes/site/style.css"><script src="/wp-includes/js/wp-embed.min.js"></script></body></html>',
            knownFiles: [
                '/wp-json/' => ['status' => 200, 'body' => '{"name":"WordPress Site"}'],
            ],
            // El sitio redirige de bare-domain a www: mismo sitio, distinto host exacto.
            inspectedUrl: 'https://portal.test',
            finalUrl: 'https://www.portal.test/',
        );

        $this->assertSame('WordPress', $result['detected']);
        $this->assertGreaterThanOrEqual(80, $result['confidence']);
    }

    #[Test]
    public function a_redirect_to_a_genuinely_different_host_stays_undetermined(): void
    {
        $scorer = app(TechnologyFingerprintScorer::class);

        $result = $scorer->score(
            headers: [
                'set-cookie' => 'wordpress_logged_in=1; path=/',
            ],
            body: '<html><body><link href="/wp-content/themes/site/style.css"><script src="/wp-includes/js/wp-embed.min.js"></script></body></html>',
            knownFiles: [
                '/wp-json/' => ['status' => 200, 'body' => '{"name":"WordPress Site"}'],
            ],
            inspectedUrl: 'https://portal.test',
            finalUrl: 'https://otro-dominio-no-relacionado.test/',
        );

        $this->assertSame('No determinado', $result['detected']);
    }

    #[Test]
    public function it_does_not_treat_html_rest_route_as_wordpress_rest_api(): void
    {
        $scorer = app(TechnologyFingerprintScorer::class);

        $result = $scorer->score(
            headers: [
                'x-powered-by' => 'PHP/8.2.32',
            ],
            body: '<html><body>Portal institucional</body></html>',
            knownFiles: [
                '/?rest_route=/' => ['status' => 200, 'body' => '<html><body>same app html</body></html>'],
                '/robots.txt' => ['status' => 200, 'body' => 'User-agent: *'],
            ],
            inspectedUrl: 'https://portal.test',
            finalUrl: 'https://portal.test/',
        );

        $this->assertNotSame('WordPress', $result['detected']);
    }
}
