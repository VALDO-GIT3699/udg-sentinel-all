<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\SiteGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Monitoring\Jobs\RunHeadCheckJob;
use Modules\Monitoring\Services\EvaluateSiteStatusService;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RunHeadCheckJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_tls_certificate_failure_does_not_mark_the_site_as_down(): void
    {
        $site = $this->createSite('tls-invalido', 'tls-invalido.udg.mx');
        $attempts = 0;

        // MonitoringHttpClientFactory reintenta 2 veces con el mismo cliente antes de
        // propagar la excepcion; simulamos que el certificado sigue invalido en esos
        // intentos y que el fallback con TLS relajado finalmente obtiene respuesta.
        Http::fake(function (Request $request) use (&$attempts) {
            $attempts++;

            if ($attempts <= 2) {
                throw new ConnectionException('cURL error 60: SSL certificate problem: certificate has expired');
            }

            return Http::response('<html>ok</html>', 200);
        });

        $this->runJob($site);

        $site->refresh();
        $this->assertSame('up', $site->current_status);

        $check = SiteCheck::query()->where('site_id', $site->id)->latest('checked_at')->first();
        $this->assertSame('up', $check?->status);
        $this->assertStringContainsString('certificado TLS', (string) $check?->error_message);
    }

    #[Test]
    public function a_dns_failure_marks_the_site_as_down(): void
    {
        $site = $this->createSite('dns-roto', 'dns-roto.udg.mx');

        Http::fake(function (Request $request): void {
            throw new ConnectionException('cURL error 6: Could not resolve host: dns-roto.udg.mx');
        });

        // El sitio tiene priority=1: se requieren 2 checks 'down' consecutivos para
        // que EvaluateSiteStatusService confirme el estado final como 'down'.
        $this->runJob($site);
        $this->runJob($site);

        $site->refresh();
        $this->assertSame('down', $site->current_status);
    }

    #[Test]
    public function a_timeout_marks_the_site_as_down(): void
    {
        $site = $this->createSite('timeout-sitio', 'timeout-sitio.udg.mx');

        Http::fake(function (Request $request): void {
            throw new ConnectionException('cURL error 28: Connection timed out after 6000 milliseconds');
        });

        $this->runJob($site);
        $this->runJob($site);

        $site->refresh();
        $this->assertSame('down', $site->current_status);
    }

    #[Test]
    public function a_connection_refused_marks_the_site_as_down(): void
    {
        $site = $this->createSite('conn-rechazada', 'conn-rechazada.udg.mx');

        Http::fake(function (Request $request): void {
            throw new ConnectionException('cURL error 7: Failed to connect: Connection refused');
        });

        $this->runJob($site);
        $this->runJob($site);

        $site->refresh();
        $this->assertSame('down', $site->current_status);
    }

    #[Test]
    public function http_403_is_treated_as_a_response_not_as_down(): void
    {
        $site = $this->createSite('sitio-403', 'sitio-403.udg.mx');

        Http::fake(fn (Request $request) => Http::response('Forbidden', 403));

        $this->runJob($site);

        $site->refresh();
        $this->assertNotSame('down', $site->current_status);
    }

    #[Test]
    public function http_404_is_treated_as_a_response_not_as_down(): void
    {
        $site = $this->createSite('sitio-404', 'sitio-404.udg.mx');

        Http::fake(fn (Request $request) => Http::response('Not Found', 404));

        $this->runJob($site);

        $site->refresh();
        $this->assertNotSame('down', $site->current_status);
    }

    #[Test]
    public function http_500_records_a_down_check_and_confirms_down_after_two_consecutive_failures(): void
    {
        $site = $this->createSite('sitio-500', 'sitio-500.udg.mx');

        Http::fake(fn (Request $request) => Http::response('Internal Error', 500));

        // Un 5xx es una falla real del origen: el check individual ya debe quedar 'down'...
        $this->runJob($site);
        $check = SiteCheck::query()->where('site_id', $site->id)->latest('checked_at')->first();
        $this->assertSame('down', $check?->status);

        // ...y, como con cualquier otra falla, el sitio (priority=1) requiere dos checks
        // 'down' consecutivos antes de que EvaluateSiteStatusService confirme 'down'.
        $site->refresh();
        $this->assertNotSame('down', $site->current_status);

        $this->runJob($site);
        $site->refresh();
        $this->assertSame('down', $site->current_status);
    }

    private function createSite(string $slug, string $domain): Site
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-'.$slug,
            'description' => null,
            'responsible_name' => null,
            'responsible_email' => null,
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio '.$slug,
            'slug' => $slug,
            'domain' => $domain,
            'url' => 'https://'.$domain,
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 1,
            'current_status' => 'unknown',
            'current_score' => 100,
            'current_score_level' => 'unknown',
            'last_checked_at' => null,
            'check_interval_min' => 5,
            'notes' => null,
            'tags' => [],
        ]);
    }

    private function runJob(Site $site): void
    {
        $job = new RunHeadCheckJob($site->id);
        $job->handle(
            app(SiteRepositoryInterface::class),
            app(EvaluateSiteStatusService::class),
            app(MonitoringHttpClientFactory::class),
        );
    }
}
