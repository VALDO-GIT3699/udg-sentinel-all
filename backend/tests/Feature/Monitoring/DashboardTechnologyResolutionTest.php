<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use App\Models\SiteTechnology;
use App\Models\Technology;
use App\Repositories\EloquentSiteRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Monitoring\Http\Controllers\DashboardController;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Bug real hallado en produccion: el catalogo legacy `site_technologies` fue
 * poblado por un job ya discontinuado (RunTechnologyScanJob) que, cuando no
 * encontraba un CMS decisivo, escribia una fila "pseudo-tecnologia" literal
 * (slug "no-determinado", is_primary=true) en vez de dejar el sitio sin fila.
 * Como el resolver ordenaba por is_primary+confidence_pct, esa fila ganaba
 * SIEMPRE sobre evidencia real (PHP, frameworks JS, servidores web), incluso
 * cuando el motor de inspeccion actual ya tenia la respuesta correcta en
 * `fingerprint_payload`. Afectaba 40 de 359 sitios alcanzables en produccion.
 */
final class DashboardTechnologyResolutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function caso_1_runtime_php_sin_tecnologia_legacy_no_es_unknown(): void
    {
        $site = $this->makeSite('caso1.udg.mx');
        $site->inspectionProfile()->create([
            'cms_name' => 'No determinado',
            'runtime_name' => 'PHP',
            'runtime_version' => '8.2.16',
            'fingerprint_payload' => [
                'detected_technology' => 'PHP',
                'detected_category' => 'runtime',
                'technology_confidence' => 45,
            ],
        ]);

        $result = $this->resolve($site);

        $this->assertNotSame('No identificada', $result['name']);
        $this->assertSame('PHP', $result['name']);
    }

    #[Test]
    public function caso_2_js_framework_vue_sin_tecnologia_legacy_no_es_unknown(): void
    {
        $site = $this->makeSite('caso2.udg.mx');
        $site->inspectionProfile()->create([
            'cms_name' => 'No determinado',
            'js_frameworks' => ['Vue'],
            'fingerprint_payload' => [
                'detected_technology' => 'Vue',
                'detected_category' => 'frontend',
                'technology_confidence' => 35,
            ],
        ]);

        $result = $this->resolve($site);

        $this->assertNotSame('No identificada', $result['name']);
        $this->assertSame('Vue', $result['name']);
    }

    #[Test]
    public function caso_3_js_framework_react_sin_tecnologia_legacy_no_es_unknown(): void
    {
        $site = $this->makeSite('caso3.udg.mx');
        $site->inspectionProfile()->create([
            'cms_name' => 'No determinado',
            'js_frameworks' => ['React'],
            'fingerprint_payload' => [
                'detected_technology' => 'React',
                'detected_category' => 'frontend',
                'technology_confidence' => 35,
            ],
        ]);

        $result = $this->resolve($site);

        $this->assertNotSame('No identificada', $result['name']);
        $this->assertSame('React', $result['name']);
    }

    #[Test]
    public function caso_4_runtime_php_mas_jquery_produce_tecnologia_identificada(): void
    {
        $site = $this->makeSite('caso4.udg.mx');
        $site->inspectionProfile()->create([
            'cms_name' => 'No determinado',
            'runtime_name' => 'PHP',
            'runtime_version' => '8.1',
            'js_frameworks' => ['jQuery'],
            'fingerprint_payload' => [
                'detected_technology' => 'PHP',
                'detected_category' => 'runtime',
                'technology_confidence' => 45,
            ],
        ]);

        $result = $this->resolve($site);

        $this->assertNotSame('No identificada', $result['name']);
    }

    #[Test]
    public function caso_5_evidencia_static_produce_html_css_js_estatico(): void
    {
        $site = $this->makeSite('caso5.udg.mx');
        $site->inspectionProfile()->create([
            'cms_name' => 'No determinado',
            'fingerprint_payload' => [
                'detected_technology' => 'HTML/CSS/JavaScript estático',
                'detected_category' => 'static',
                'technology_confidence' => 25,
            ],
        ]);

        $result = $this->resolve($site);

        $this->assertSame('HTML/CSS/JavaScript estático', $result['name']);
        $this->assertSame('static', $result['category']);
    }

    #[Test]
    public function caso_6_sitio_down_sin_evidencia_permanece_no_identificada(): void
    {
        $site = $this->makeSite('caso6.udg.mx', 'down');
        $site->inspectionProfile()->create([
            'cms_name' => 'No determinado',
        ]);

        $result = $this->resolve($site);

        $this->assertSame('No identificada', $result['name']);
        $this->assertSame('danger', $result['badge_state']);
    }

    #[Test]
    public function caso_7_cms_identificado_via_legacy_devuelve_cms_correcto(): void
    {
        $site = $this->makeSite('caso7.udg.mx');
        $this->attachLegacyTechnology($site, 'wordpress', 'WordPress', 'cms', 92, true);

        $result = $this->resolve($site);

        $this->assertSame('WordPress', $result['name']);
        $this->assertSame('cms', $result['category']);
    }

    #[Test]
    public function caso_8_legacy_vacio_usa_fingerprint_fresco(): void
    {
        $site = $this->makeSite('caso8.udg.mx');
        $site->inspectionProfile()->create([
            'cms_name' => 'No determinado',
            'fingerprint_payload' => [
                'detected_technology' => 'Next.js (Vercel)',
                'detected_category' => 'runtime',
                'technology_confidence' => 99,
            ],
        ]);

        $result = $this->resolve($site);

        $this->assertSame('Next.js (Vercel)', $result['name']);
    }

    #[Test]
    public function caso_9_legacy_no_determinado_con_evidencia_fresca_usa_la_evidencia_fresca(): void
    {
        $site = $this->makeSite('caso9.udg.mx');
        $this->attachLegacyTechnology($site, 'no-determinado', 'No determinado', 'cms', 55, true);
        $site->inspectionProfile()->create([
            'cms_name' => 'No determinado',
            'fingerprint_payload' => [
                'detected_technology' => 'PHP',
                'detected_category' => 'runtime',
                'technology_confidence' => 72,
            ],
        ]);

        $result = $this->resolve($site);

        $this->assertNotSame('No determinado', $result['name']);
        $this->assertNotSame('No identificada', $result['name']);
        $this->assertSame('PHP', $result['name']);
    }

    #[Test]
    public function caso_10_evidencia_de_framework_o_runtime_no_es_superada_por_infraestructura_web_server(): void
    {
        // Reproduce el caso real de "artesescenicas.udg.mx": la unica fila legacy
        // real es "Apache" (web-server, alta confianza historica), pero el motor
        // actual ya detecto jQuery+Bootstrap en fingerprint_payload. La categoria
        // de infraestructura NUNCA debe ganarle a evidencia de framework/runtime.
        $site = $this->makeSite('caso10.udg.mx');
        $this->attachLegacyTechnology($site, 'no-determinado', 'No determinado', 'cms', 55, true);
        $this->attachLegacyTechnology($site, 'apache', 'Apache HTTP Server', 'web-server', 82, false);
        $site->inspectionProfile()->create([
            'cms_name' => 'No determinado',
            'js_frameworks' => ['jQuery', 'Bootstrap'],
            'fingerprint_payload' => [
                'detected_technology' => 'jQuery + Bootstrap',
                'detected_category' => 'frontend',
                'technology_confidence' => 35,
            ],
        ]);

        $result = $this->resolve($site);

        $this->assertSame('jQuery + Bootstrap', $result['name']);
        $this->assertSame('frontend', $result['category']);
    }

    #[Test]
    public function caso_11_cms_details_unconfigured_o_inactive_no_tapa_la_deteccion_fresca(): void
    {
        // Bug real hallado en produccion: el job legacy (ya discontinuado)
        // escribia "unconfigured"/"inactive" en cms_details.cms_type como
        // marcador de ESTADO ("vhost sin configurar" / "dominio inactivo"),
        // nunca como nombre de CMS. Sin excluirlos, ucfirst() los convertia
        // en "Unconfigured"/"Inactive" y ganaban tier 1 (cms) por encima de
        // la deteccion real ya calculada por el motor actual. Confirmado
        // contra 20 sitios reales en produccion (p. ej. ciaya5.udg.mx,
        // compostela2019.udg.mx) que mostraban "Unconfigured" en vez de
        // "HTML/CSS/JavaScript estático".
        $site = $this->makeSite('caso11.udg.mx');
        $site->cmsDetail()->create(['cms_type' => 'unconfigured']);
        $site->inspectionProfile()->create([
            'cms_name' => 'No determinado',
            'fingerprint_payload' => [
                'detected_technology' => 'HTML/CSS/JavaScript estático',
                'detected_category' => 'static',
                'technology_confidence' => 25,
            ],
        ]);

        $result = $this->resolve($site);

        $this->assertSame('HTML/CSS/JavaScript estático', $result['name']);
        $this->assertNotSame('Unconfigured', $result['name']);

        $siteB = $this->makeSite('caso11b.udg.mx');
        $siteB->cmsDetail()->create(['cms_type' => 'inactive']);
        $siteB->inspectionProfile()->create([
            'cms_name' => 'No determinado',
            'js_frameworks' => ['React'],
            'fingerprint_payload' => [
                'detected_technology' => 'React',
                'detected_category' => 'frontend',
                'technology_confidence' => 35,
            ],
        ]);

        $resultB = $this->resolve($siteB);

        $this->assertSame('React', $resultB['name']);
        $this->assertNotSame('Inactive', $resultB['name']);
    }

    #[Test]
    public function end_to_end_deteccion_persistida_llega_intacta_hasta_el_arreglo_que_consume_el_frontend(): void
    {
        // Prueba real motor -> persistencia -> mapeo de controlador -> arreglo que
        // Inertia serializa al frontend, usando el mismo repositorio/paginator y el
        // mismo mapSitesForDashboard() que el endpoint real usa. Se evita la ruta
        // HTTP completa porque DashboardController::index() tambien invoca
        // preventiveExpirations(), que usa "DISTINCT ON" (sintaxis exclusiva de
        // Postgres) y por eso ya falla bajo SQLite en TODOS los tests que golpean
        // esa ruta (fallo preexistente y no relacionado, documentado en
        // DashboardAlertsFeatureTest); no es parte de este bug de resolucion de
        // tecnologia y no se toca aqui.
        $site = $this->makeSite('e2e-caso.udg.mx');
        $this->attachLegacyTechnology($site, 'no-determinado', 'No determinado', 'cms', 55, true);
        $this->attachLegacyTechnology($site, 'nginx', 'Nginx', 'web-server', 82, false);
        $site->inspectionProfile()->create([
            'cms_name' => 'No determinado',
            'runtime_name' => 'No determinado',
            'js_frameworks' => ['React'],
            'fingerprint_payload' => [
                'detected_technology' => 'React',
                'detected_category' => 'frontend',
                'technology_confidence' => 35,
            ],
        ]);

        $paginator = (new EloquentSiteRepository)->paginate(50);

        $controller = app(DashboardController::class);
        $method = new ReflectionMethod(DashboardController::class, 'mapSitesForDashboard');
        $mapped = $method->invoke($controller, $paginator);

        $row = collect($mapped->getCollection())->firstWhere('domain', 'e2e-caso.udg.mx');

        $this->assertNotNull($row);
        $this->assertSame('React', $row['technology_name']);
        $this->assertNotSame('No determinado', $row['technology_name']);
        $this->assertNotSame('No identificada', $row['technology_name']);
    }

    private function resolve(Site $site): array
    {
        $controller = app(DashboardController::class);
        $method = new ReflectionMethod(DashboardController::class, 'resolveTechnologyInfo');

        return $method->invoke($controller, $site);
    }

    private function makeSite(string $domain, string $status = 'up'): Site
    {
        $group = SiteGroup::query()->firstOrCreate(
            ['slug' => 'tech-resolution-tests'],
            [
                'name' => 'Tech resolution tests',
                'description' => null,
                'responsible_name' => null,
                'responsible_email' => null,
                'color' => '#0EA5E9',
            ],
        );

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio '.$domain,
            'slug' => str($domain)->slug()->toString(),
            'domain' => $domain,
            'url' => 'https://'.$domain,
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 2,
            'current_status' => $status,
            'current_score' => 90,
            'current_score_level' => 'good',
            'last_checked_at' => now()->subMinute(),
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);
    }

    private function attachLegacyTechnology(Site $site, string $slug, string $name, string $category, int $confidence, bool $isPrimary = false): void
    {
        $technology = Technology::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'category' => $category, 'vendor' => 'n/a'],
        );

        SiteTechnology::query()->create([
            'site_id' => $site->id,
            'technology_id' => $technology->id,
            'version' => null,
            'confidence_pct' => $confidence,
            'is_primary' => $isPrimary,
            'detected_at' => now(),
            'detection_method' => 'automatic',
            'metadata' => [],
        ]);
    }
}
