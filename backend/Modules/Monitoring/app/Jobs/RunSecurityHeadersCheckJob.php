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
use Illuminate\Support\Facades\DB;
use Modules\Monitoring\Events\AlertResolved;
use Modules\Monitoring\Events\AlertTriggered;
use Modules\Monitoring\Events\SecurityHeadersWeak;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;
use Modules\Monitoring\Support\MassScanProgress;

final class RunSecurityHeadersCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    private const TARGET_HEADERS = [
        'strict-transport-security',
        'content-security-policy',
        'x-frame-options',
        'x-content-type-options',
        'referrer-policy',
        'permissions-policy',
    ];

    public function __construct(
        private readonly int $siteId,
        private readonly ?string $massScanRunId = null,
        private readonly bool $forceScan = false,
    )
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
        try {
            $site = $siteRepository->findById($this->siteId);

            if (! $site instanceof Site || (! $this->forceScan && (! $site->is_active || ! $site->is_monitored))) {
                return;
            }

            try {
                $response = $httpClientFactory
                    ->make(['Accept' => 'text/html,*/*;q=0.8'])
                    ->get($site->url);

            $headers = array_change_key_case($response->headers(), CASE_LOWER);
            $headerEvaluation = $this->evaluateHeaders($headers);
            $score = (int) round((count(array_filter($headerEvaluation)) / count(self::TARGET_HEADERS)) * 100);
            $level = SecurityScore::levelFromScore($score);

            DB::transaction(function () use ($site, $siteRepository, $headers, $headerEvaluation, $score, $level): void {
                SecurityHeader::create([
                    'site_id' => $site->id,
                    'checked_at' => now(),
                    'has_hsts' => $headerEvaluation['strict-transport-security'],
                    'has_csp' => $headerEvaluation['content-security-policy'],
                    'has_x_frame_options' => $headerEvaluation['x-frame-options'],
                    'has_x_content_type' => $headerEvaluation['x-content-type-options'],
                    'has_referrer_policy' => $headerEvaluation['referrer-policy'],
                    'has_permissions_policy' => $headerEvaluation['permissions-policy'],
                    'score_contribution' => $score,
                    'raw_headers' => $headers,
                ]);

                SecurityScore::create([
                    'site_id' => $site->id,
                    'score' => $score,
                    'level' => $level,
                    'calculated_at' => now(),
                    'breakdown' => $headerEvaluation,
                    'recommendations' => $this->buildRecommendations($headerEvaluation),
                ]);

                $siteRepository->update($site, [
                    'current_score' => $score,
                    'current_score_level' => $level,
                ]);
            });

            // Spec §9: emitir security.headers.weak cuando score < 67 (menos de 4/6 cabeceras)
            if ($score < 67) {
                $missing = array_keys(array_filter($headerEvaluation, static fn (bool $value): bool => ! $value));

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

                    event(new AlertTriggered(
                        alertId: (int) $alert->id,
                        siteId: $alert->site_id !== null ? (int) $alert->site_id : null,
                        severity: (string) $alert->severity,
                        event: $event,
                        triggeredAt: now()->toIso8601String(),
                    ));

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

                        event(new AlertResolved(
                            alertId: (int) $alert->id,
                            siteId: $alert->site_id !== null ? (int) $alert->site_id : null,
                            event: (string) data_get($alert->context, 'event', 'alert.resolved'),
                            resolvedAt: now()->toIso8601String(),
                        ));
                    });
            }
            } catch (\Throwable $exception) {
                // El scanner de cabeceras no debe detener el pipeline completo.

                if (is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                    MassScanProgress::recordFailure(
                        $this->massScanRunId,
                        'headers',
                        $this->siteId,
                        $exception->getMessage(),
                    );
                }
            }
        } finally {
            if (is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                MassScanProgress::completeTask($this->massScanRunId, 'headers', $this->siteId);
            }
        }
    }

    /**
     * @param array<string, bool> $headerEvaluation
     * @return array<int, string>
     */
    private function buildRecommendations(array $headerEvaluation): array
    {
        $recommendations = [];

        if (! ($headerEvaluation['strict-transport-security'] ?? false)) {
            $recommendations[] = 'Agregar Strict-Transport-Security.';
        }

        if (! ($headerEvaluation['content-security-policy'] ?? false)) {
            $recommendations[] = 'Agregar Content-Security-Policy sin directivas debiles.';
        }

        if (! ($headerEvaluation['x-frame-options'] ?? false)) {
            $recommendations[] = 'Agregar X-Frame-Options.';
        }

        if (! ($headerEvaluation['x-content-type-options'] ?? false)) {
            $recommendations[] = 'Agregar X-Content-Type-Options: nosniff.';
        }

        if (! ($headerEvaluation['referrer-policy'] ?? false)) {
            $recommendations[] = 'Agregar Referrer-Policy.';
        }

        if (! ($headerEvaluation['permissions-policy'] ?? false)) {
            $recommendations[] = 'Agregar Permissions-Policy.';
        }

        return $recommendations;
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array<string, bool>
     */
    private function evaluateHeaders(array $headers): array
    {
        return [
            'strict-transport-security' => $this->isStrictTransportSecurityStrong($headers['strict-transport-security'] ?? []),
            'content-security-policy' => $this->isContentSecurityPolicyStrong($headers['content-security-policy'] ?? []),
            'x-frame-options' => $this->isHeaderPresentAndNonEmpty($headers['x-frame-options'] ?? []),
            'x-content-type-options' => $this->isHeaderPresentAndNonEmpty($headers['x-content-type-options'] ?? []) && $this->headerContainsValue($headers['x-content-type-options'] ?? [], 'nosniff'),
            'referrer-policy' => $this->isHeaderPresentAndNonEmpty($headers['referrer-policy'] ?? []),
            'permissions-policy' => $this->isHeaderPresentAndNonEmpty($headers['permissions-policy'] ?? []),
        ];
    }

    /**
     * @param array<int, string> $values
     */
    private function isHeaderPresentAndNonEmpty(array $values): bool
    {
        return trim(implode(' ', $values)) !== '';
    }

    /**
     * @param array<int, string> $values
     */
    private function headerContainsValue(array $values, string $needle): bool
    {
        return str_contains(mb_strtolower(implode(' ', $values)), mb_strtolower($needle));
    }

    /**
     * @param array<int, string> $values
     */
    private function isStrictTransportSecurityStrong(array $values): bool
    {
        $value = mb_strtolower(trim(implode(' ', $values)));

        return $value !== '' && str_contains($value, 'max-age=') && ! str_contains($value, 'max-age=0');
    }

    /**
     * @param array<int, string> $values
     */
    private function isContentSecurityPolicyStrong(array $values): bool
    {
        $value = mb_strtolower(trim(implode(' ', $values)));

        return $value !== ''
            && ! str_contains($value, "'unsafe-inline'")
            && ! str_contains($value, "'unsafe-eval'")
            && ! str_contains($value, ' data:');
    }
}
