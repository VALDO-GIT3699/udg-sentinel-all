<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use Modules\Monitoring\Services\SiteInspection\VerticalSiteInspectionEngine;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

final class VerticalSiteInspectionEngineTest extends TestCase
{
    #[Test]
    public function it_recognizes_the_sems_style_https_placeholder_page(): void
    {
        $body = <<<'HTML'
            <html>
            <head>
            <title>SEMS</title>
            <META HTTP-EQUIV="REFRESH" CONTENT="0;URL=http://www.sems.udg.mx">
            </head>
            <body>
            </body>
            </html>
            HTML;

        $this->assertTrue($this->isMetaRefreshPlaceholder($body));
    }

    #[Test]
    public function it_does_not_flag_a_real_page_that_happens_to_use_meta_refresh(): void
    {
        $body = '<html><head><meta http-equiv="refresh" content="5"></head>'
            .'<body><div id="app">'.str_repeat('contenido real de la pagina ', 30).'</div></body></html>';

        $this->assertFalse($this->isMetaRefreshPlaceholder($body));
    }

    #[Test]
    public function it_does_not_flag_a_normal_drupal_page(): void
    {
        $body = '<html><head><meta name="Generator" content="Drupal 7 (http://drupal.org)" /></head>'
            .'<body><div class="region region-content">'.str_repeat('contenido ', 50).'</div></body></html>';

        $this->assertFalse($this->isMetaRefreshPlaceholder($body));
    }

    #[Test]
    public function it_does_not_flag_an_empty_body(): void
    {
        $this->assertFalse($this->isMetaRefreshPlaceholder(''));
    }

    #[Test]
    public function fallback_cascade_reports_php_runtime_when_no_cms_wins_but_x_powered_by_is_present(): void
    {
        $result = $this->fingerprint(
            headers: ['x-powered-by' => 'PHP/8.1.10'],
            body: '<html><body>'.str_repeat('contenido generico sin cms ', 20).'</body></html>',
        );

        $this->assertSame('PHP', $result['detected_technology']);
        $this->assertSame('runtime', $result['detected_category']);
        $this->assertGreaterThan(0, $result['technology_confidence']);
        $this->assertNotSame('No determinado', $result['detected_technology']);
    }

    #[Test]
    public function fallback_cascade_reports_frontend_library_when_no_cms_or_runtime_evidence_exists(): void
    {
        $result = $this->fingerprint(
            headers: [],
            body: '<html><body><script src="/assets/jquery.min.js"></script>'
                .str_repeat('contenido generico sin cms ', 20).'</body></html>',
        );

        $this->assertSame('frontend', $result['detected_category']);
        $this->assertStringContainsString('jQuery', $result['detected_technology']);
    }

    #[Test]
    public function fallback_cascade_reports_static_html_as_last_resort_before_giving_up(): void
    {
        $result = $this->fingerprint(
            headers: [],
            body: '<html><body>'.str_repeat('contenido totalmente generico sin ninguna pista tecnica ', 20).'</body></html>',
        );

        $this->assertSame('static', $result['detected_category']);
        $this->assertSame('HTML/CSS/JavaScript estático', $result['detected_technology']);
    }

    #[Test]
    public function fallback_cascade_never_overrides_a_decisive_cms_detection(): void
    {
        $result = $this->fingerprint(
            headers: ['x-powered-by' => 'PHP/8.1.10'],
            body: '<html><body><link href="/wp-content/themes/site/style.css">'
                .'<script src="/wp-includes/js/wp-embed.min.js"></script></body></html>',
        );

        $this->assertSame('WordPress', $result['detected_technology']);
        $this->assertSame('cms', $result['detected_category']);
    }

    #[Test]
    public function fallback_cascade_does_not_label_a_blocked_or_error_page_as_the_sites_real_technology(): void
    {
        // Un 403 tambien trae HTML valido (la pagina de error), pero no representa la
        // tecnologia real del sitio: no debe caer en el "HTML/CSS/JS estatico" de respaldo.
        $result = $this->fingerprint(
            headers: [],
            body: '<html><body><h1>403 Forbidden</h1>'.str_repeat('acceso denegado ', 20).'</body></html>',
            httpStatus: 403,
        );

        $this->assertSame('No determinado', $result['detected_technology']);
        $this->assertSame('unknown', $result['detected_category']);
    }

    #[Test]
    public function fallback_cascade_does_not_fire_for_an_empty_body(): void
    {
        $result = $this->fingerprint(headers: [], body: '');

        $this->assertSame('No determinado', $result['detected_technology']);
        $this->assertSame('unknown', $result['detected_category']);
    }

    private function isMetaRefreshPlaceholder(string $body): bool
    {
        $engine = app(VerticalSiteInspectionEngine::class);
        $method = new ReflectionMethod(VerticalSiteInspectionEngine::class, 'isMetaRefreshPlaceholder');

        return $method->invoke($engine, $body);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, array<string, mixed>>  $knownFiles
     * @return array<string, mixed>
     */
    private function fingerprint(array $headers, string $body, array $knownFiles = [], ?int $httpStatus = 200): array
    {
        $engine = app(VerticalSiteInspectionEngine::class);
        $method = new ReflectionMethod(VerticalSiteInspectionEngine::class, 'fingerprint');

        return $method->invoke($engine, $headers, $body, $knownFiles, 'https://sitio.test', 'https://sitio.test/', $httpStatus);
    }
}
