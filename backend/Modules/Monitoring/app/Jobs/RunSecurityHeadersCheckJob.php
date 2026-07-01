<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\SecurityHeader;
use App\Models\SecurityScore;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Services\AlertNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Monitoring\Events\SecurityHeadersWeak;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;

final class RunSecurityHeadersCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(private readonly int $siteId)
    {
        $this->onQueue((string) env('SENTINEL_QUEUE_HEADERS', 'monitoring-headers'));
    }

    public function handle(
        SiteRepositoryInterface $siteRepository,
        MonitoringHttpClientFactory $httpClientFactory,
        AlertRepositoryInterface $alertRepository,
        AlertNotificationService $alertNotificationService,
    ): void
    {
        $site = $siteRepository->findById($this->siteId);

        if (! $site instanceof Site || ! $site->is_active || ! $site->is_monitored) {
            return;
        }

        try {
            $response = $httpClientFactory
                ->make(['Accept' => 'text/html,*/*;q=0.8'])
                ->get($site->url);

            $headers = array_change_key_case($response->headers(), CASE_LOWER);

            $hasHsts = isset($headers['strict-transport-security']);
            $hasCsp = isset($headers['content-security-policy']);
            $hasXFrame = isset($headers['x-frame-options']);
            $hasXContentType = isset($headers['x-content-type-options']);
            $hasReferrerPolicy = isset($headers['referrer-policy']);
            $hasPermissionsPolicy = isset($headers['permissions-policy']);

            $passed =
                ((int) $hasHsts)
                + ((int) $hasCsp)
                + ((int) $hasXFrame)
                + ((int) $hasXContentType)
                + ((int) $hasReferrerPolicy)
                + ((int) $hasPermissionsPolicy);

            $score = (int) round(($passed / 6) * 100);
            $level = SecurityScore::levelFromScore($score);

            SecurityHeader::create([
                'site_id' => $site->id,
                'checked_at' => now(),
                'has_hsts' => $hasHsts,
                'has_csp' => $hasCsp,
                'has_x_frame_options' => $hasXFrame,
                'has_x_content_type' => $hasXContentType,
                'has_referrer_policy' => $hasReferrerPolicy,
                'has_permissions_policy' => $hasPermissionsPolicy,
                'score_contribution' => $score,
                'raw_headers' => $headers,
            ]);

            SecurityScore::create([
                'site_id' => $site->id,
                'score' => $score,
                'level' => $level,
                'calculated_at' => now(),
                'breakdown' => [
                    'strict-transport-security' => $hasHsts,
                    'content-security-policy' => $hasCsp,
                    'x-frame-options' => $hasXFrame,
                    'x-content-type-options' => $hasXContentType,
                    'referrer-policy' => $hasReferrerPolicy,
                    'permissions-policy' => $hasPermissionsPolicy,
                ],
                'recommendations' => $this->buildRecommendations($headers),
            ]);

            $siteRepository->update($site, [
                'current_score' => $score,
                'current_score_level' => $level,
            ]);

            // Spec §9: emitir security.headers.weak cuando score < 67 (menos de 4/6 cabeceras)
            if ($score < 67) {
                $missing = array_keys(array_filter([
                    'strict-transport-security' => ! $hasHsts,
                    'content-security-policy'   => ! $hasCsp,
                    'x-frame-options'           => ! $hasXFrame,
                    'x-content-type-options'    => ! $hasXContentType,
                    'referrer-policy'           => ! $hasReferrerPolicy,
                    'permissions-policy'        => ! $hasPermissionsPolicy,
                ]));

                SecurityHeadersWeak::dispatch(
                    siteId: (int) $site->id,
                    score: $score,
                    level: $level,
                    missing: $missing,
                    checkedAt: now()->toIso8601String(),
                );

                $event = 'security.headers.exposed';
                $existing = $alertRepository->openForSite($site->id)
                    ->first(fn ($alert) => data_get($alert->context, 'event') === $event);

                if ($existing === null) {
                    SiteEvent::record(
                        siteId: (int) $site->id,
                        eventType: $event,
                        title: 'Semaforo de proteccion en nivel Expuesto',
                        severity: 'high',
                        description: 'Faltan cabeceras de seguridad criticas en la respuesta del sitio.',
                        metadata: [
                            'score' => $score,
                            'level' => $level,
                            'missing_headers' => $missing,
                        ]
                    );

                    $alert = $alertRepository->create([
                        'site_id' => (int) $site->id,
                        'title' => 'Sitio expuesto por cabeceras de seguridad',
                        'message' => 'Nivel Expuesto detectado. Faltantes: ' . implode(', ', $missing),
                        'severity' => $score < 34 ? 'critical' : 'high',
                        'status' => 'open',
                        'triggered_at' => now(),
                        'context' => [
                            'event' => $event,
                            'score' => $score,
                            'level' => $level,
                            'missing_headers' => $missing,
                        ],
                    ]);

                    $alertNotificationService->dispatch($alert, [
                        'trigger' => 'security_exposed',
                    ]);
                }
            } else {
                $alertRepository->openForSite($site->id)
                    ->filter(fn ($alert) => data_get($alert->context, 'event') === 'security.headers.exposed')
                    ->each(function ($alert): void {
                        $alert->update([
                            'status' => 'resolved',
                            'resolved_at' => now(),
                            'resolved_by' => null,
                        ]);
                    });
            }
        } catch (\Throwable) {
            // El scanner de cabeceras no debe detener el pipeline completo.
        }
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array<int, string>
     */
    private function buildRecommendations(array $headers): array
    {
        $recommendations = [];

        if (! isset($headers['strict-transport-security'])) {
            $recommendations[] = 'Agregar Strict-Transport-Security.';
        }

        if (! isset($headers['content-security-policy'])) {
            $recommendations[] = 'Agregar Content-Security-Policy.';
        }

        if (! isset($headers['x-frame-options'])) {
            $recommendations[] = 'Agregar X-Frame-Options.';
        }

        if (! isset($headers['x-content-type-options'])) {
            $recommendations[] = 'Agregar X-Content-Type-Options: nosniff.';
        }

        if (! isset($headers['referrer-policy'])) {
            $recommendations[] = 'Agregar Referrer-Policy.';
        }

        if (! isset($headers['permissions-policy'])) {
            $recommendations[] = 'Agregar Permissions-Policy.';
        }

        return $recommendations;
    }
}
