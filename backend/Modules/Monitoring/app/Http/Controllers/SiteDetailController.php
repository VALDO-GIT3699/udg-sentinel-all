<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\SiteEvent;
use App\Models\TrafficMetric;
use App\Repositories\EloquentSiteCheckRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class SiteDetailController extends Controller
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly EloquentSiteCheckRepository $siteCheckRepository,
        private readonly AlertRepositoryInterface $alertRepository,
    ) {}

    private static function alertFriendlyDescription(string $title, string $message): string
    {
        $text = mb_strtolower($title.' '.$message);

        if (str_contains($text, 'csp') || str_contains($text, 'content-security-policy')) {
            return 'La cabecera Content-Security-Policy sigue pendiente y conviene cerrarla antes de exponer contenido dinámico.';
        }

        if (str_contains($text, 'ssl') || str_contains($text, 'certific') || str_contains($text, 'tls')) {
            return 'El certificado SSL requiere seguimiento preventivo para evitar pérdida de confianza o expiración.';
        }

        if (str_contains($text, 'hsts') || str_contains($text, 'strict-transport-security')) {
            return 'El sitio no está reforzando HTTPS con HSTS de forma consistente.';
        }

        if (str_contains($text, 'x-frame-options')) {
            return 'La protección contra clickjacking no está activa y debe quedar habilitada.';
        }

        return 'La alerta sigue abierta y requiere revisión operativa antes de que impacte disponibilidad o seguridad.';
    }

    private static function alertRecommendedAction(string $title, string $message): string
    {
        $text = mb_strtolower($title.' '.$message);

        if (str_contains($text, 'csp') || str_contains($text, 'content-security-policy')) {
            return 'Configura la cabecera en el servidor y valida los recursos permitidos.';
        }

        if (str_contains($text, 'ssl') || str_contains($text, 'certific') || str_contains($text, 'tls')) {
            return 'Renueva el certificado, valida la cadena de confianza y confirma la fecha de expiración.';
        }

        if (str_contains($text, 'hsts') || str_contains($text, 'strict-transport-security')) {
            return 'Activa HSTS y confirma que todas las rutas públicas resuelvan por HTTPS.';
        }

        if (str_contains($text, 'x-frame-options')) {
            return 'Agrega la directiva anti-frame al servidor y prueba la carga en navegadores principales.';
        }

        return 'Revisa el origen, corrige la causa raíz y valida el sitio con un reescaneo manual.';
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function eventMetadata(SiteEvent $event): array
    {
        $type = mb_strtolower((string) $event->event_type);

        if (str_contains($type, 'up') || str_contains($type, 'recovered') || str_contains($type, 'operat')) {
            return ['↑', 'text-emerald-300', 'El sitio volvió a estado operativo.'];
        }

        if (str_contains($type, 'down') || str_contains($type, 'error') || str_contains($type, 'fail')) {
            return ['●', 'text-rose-300', 'Se registró un evento de error o indisponibilidad.'];
        }

        if (str_contains($type, 'scan') || str_contains($type, 'change') || str_contains($type, 'update') || str_contains($type, 'manual')) {
            return ['⚙', 'text-cyan-300', 'Se registró un cambio operativo o un reescaneo manual.'];
        }

        return ['•', 'text-slate-300', 'Evento operativo registrado en el historial reciente.'];
    }

    public function addNote(Request $request, int $siteId): RedirectResponse
    {
        $site = $this->siteRepository->findById($siteId);
        abort_if(! $site instanceof Site, 404);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:3000'],
            'status' => ['required', 'in:en_curso,resuelta'],
        ]);

        SiteEvent::record(
            siteId: (int) $site->id,
            eventType: 'monitoring.note',
            title: 'Nota operativa',
            severity: 'info',
            description: trim((string) $validated['note']),
            metadata: [
                'note_status' => (string) $validated['status'],
            ],
            createdBy: $request->user()?->id,
            occurredAt: now(),
        );

        return back()->with('success', 'Nota agregada al timeline del sitio.');
    }

    /**
     * Un mismo endpoint PATCH atiende dos acciones del timeline de notas: cambiar el
     * status (en_curso/resuelta) y ocultar/mostrar la nota. El frontend envia solo el
     * campo que cambio, por eso ambos son opcionales pero se exige al menos uno.
     */
    public function updateNoteStatus(Request $request, int $siteId, int $eventId): RedirectResponse
    {
        $site = $this->siteRepository->findById($siteId);
        abort_if(! $site instanceof Site, 404);

        $validated = $request->validate([
            'status' => ['sometimes', 'required', 'in:en_curso,resuelta'],
            'is_hidden' => ['sometimes', 'required', 'boolean'],
        ]);

        abort_if($validated === [], 422, 'Debes indicar status o is_hidden.');

        $noteEvent = SiteEvent::query()
            ->where('site_id', $site->id)
            ->where('event_type', 'monitoring.note')
            ->whereKey($eventId)
            ->firstOrFail();

        $metadata = is_array($noteEvent->metadata) ? $noteEvent->metadata : [];

        if (array_key_exists('status', $validated)) {
            $metadata['note_status'] = (string) $validated['status'];

            if ($validated['status'] === 'resuelta') {
                $metadata['resolved_at'] = now()->toIso8601String();
            }
        }

        if (array_key_exists('is_hidden', $validated)) {
            $metadata['is_hidden'] = (bool) $validated['is_hidden'];
        }

        $metadata['updated_at'] = now()->toIso8601String();

        $noteEvent->metadata = $metadata;
        $noteEvent->save();

        return back()->with('success', 'Nota actualizada.');
    }

    public function deleteNote(int $siteId, int $eventId): RedirectResponse
    {
        $site = $this->siteRepository->findById($siteId);
        abort_if(! $site instanceof Site, 404);

        SiteEvent::query()
            ->where('site_id', $site->id)
            ->where('event_type', 'monitoring.note')
            ->whereKey($eventId)
            ->firstOrFail()
            ->delete();

        return back()->with('success', 'Nota eliminada del timeline.');
    }

    public function show(int $siteId): Response
    {
        $site = $this->siteRepository->findById($siteId);
        abort_if(! $site instanceof Site, 404);

        return Inertia::render('Monitoring/SiteDetail', $this->buildPayload($site));
    }

    /**
     * Puntos recientes de latencia + estado de ESTE sitio, para la grafica
     * "en vivo" del detalle. A diferencia de la del dashboard general (que
     * agrega por minuto entre todos los sitios), aqui cada punto es un
     * chequeo real e individual -con su codigo HTTP- porque para un solo
     * sitio no hace falta agregar y perder esa señal es justo lo que hace
     * la grafica mas util que un simple promedio.
     */
    public function latencyTimeseries(Request $request, int $siteId): JsonResponse
    {
        $minutes = max(10, min(180, $request->integer('minutes', 60)));
        $since = now()->subMinutes($minutes);

        $points = SiteCheck::query()
            ->where('site_id', $siteId)
            ->where('checked_at', '>=', $since)
            ->orderBy('checked_at')
            ->limit(300)
            ->get(['checked_at', 'response_time_ms', 'status', 'http_code'])
            ->map(static fn (SiteCheck $check): array => [
                'at' => optional($check->checked_at)?->toIso8601String(),
                'response_time_ms' => $check->response_time_ms,
                'status' => (string) $check->status,
                'http_code' => $check->http_code,
            ])
            ->values()
            ->all();

        return response()->json([
            'window_minutes' => $minutes,
            'points' => $points,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function showApi(int $siteId): JsonResponse
    {
        $site = $this->siteRepository->findById($siteId);

        if (! $site instanceof Site) {
            return response()->json(['message' => 'Sitio no encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->buildPayload($site),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Site $site): array
    {
        $inspectionProfile = $site->inspectionProfile;

        $checksTimeline = $this->normalizeTimeline(
            $this->siteCheckRepository->timelineForSite((int) $site->id, 24, 288)->all(),
            24,
            288,
        );
        $statusBreakdown24h = $this->normalizeStatusBreakdown(
            $this->siteCheckRepository->statusBreakdownForSite((int) $site->id, 24),
        );
        $traffic24h = TrafficMetric::query()
            ->where('site_id', (int) $site->id)
            ->where('recorded_at', '>=', now()->subHours(24))
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'requests_per_min', 'error_rate_pct', 'avg_response_time_ms']);

        $trafficSeries24h = $traffic24h->map(static fn (TrafficMetric $metric): array => [
            'at' => optional($metric->recorded_at)?->toIso8601String(),
            'rpm' => (int) ($metric->requests_per_min ?? 0),
            'error_rate_pct' => (float) ($metric->error_rate_pct ?? 0),
        ])->values();

        if ($this->isFlatTrafficSeries($trafficSeries24h->all())) {
            $trafficSeries24h = $this->buildTrafficFallbackFromChecks((int) $site->id, 24);
        }

        $oneHourStart = now()->subHour();
        $trafficSeries1h = $trafficSeries24h
            ->filter(static fn (array $point): bool => isset($point['at'])
                && is_string($point['at'])
                && Carbon::parse($point['at'])->greaterThanOrEqualTo($oneHourStart),
            )
            ->values();

        $securityPayload = is_array($inspectionProfile?->security_headers_payload)
            ? $inspectionProfile->security_headers_payload
            : [];

        $securityHeadersFromInspection = is_array($securityPayload['headers'] ?? null)
            ? (array) $securityPayload['headers']
            : [];

        $headerMap = [
            'strict-transport-security' => ['label' => 'HSTS'],
            'content-security-policy' => ['label' => 'Content-Security-Policy'],
            'x-frame-options' => ['label' => 'X-Frame-Options'],
            'x-content-type-options' => ['label' => 'X-Content-Type-Options'],
            'referrer-policy' => ['label' => 'Referrer-Policy'],
            'permissions-policy' => ['label' => 'Permissions-Policy'],
        ];

        $securityHeaders = collect($headerMap)
            ->map(function (array $headerConfig, string $key) use ($securityHeadersFromInspection): array {
                $headerData = is_array($securityHeadersFromInspection[$key] ?? null)
                    ? (array) $securityHeadersFromInspection[$key]
                    : [];
                $rawValue = $headerData['value'] ?? null;

                return [
                    'key' => $key,
                    'label' => $headerConfig['label'],
                    'present' => (bool) ($headerData['present'] ?? false),
                    'value' => is_array($rawValue) ? (string) ($rawValue[0] ?? '') : (string) ($rawValue ?? ''),
                ];
            })
            ->values()
            ->all();

        $diagnosis = $this->resolveSiteDiagnosis($site, strtolower((string) ($site->current_status ?? 'unknown')), $site->latestCheck);
        $sslPayload = is_array($inspectionProfile?->ssl_payload)
            ? $inspectionProfile->ssl_payload
            : [];
        $normalizedSslCertificate = null;

        if ($sslPayload !== []) {
            $validUntilRaw = trim((string) ($sslPayload['expires_at'] ?? ''));
            $normalizedDaysRemaining = isset($sslPayload['expires_in_days']) && is_numeric($sslPayload['expires_in_days'])
                ? (int) $sslPayload['expires_in_days']
                : null;

            $normalizedSslCertificate = [
                'valid_until' => $validUntilRaw !== '' ? $validUntilRaw : null,
                'issuer' => isset($sslPayload['issuer']) ? (string) $sslPayload['issuer'] : null,
                'days_remaining' => $normalizedDaysRemaining,
                'algorithm' => isset($sslPayload['algorithm']) ? (string) $sslPayload['algorithm'] : null,
            ];
        }

        $notesTimeline = SiteEvent::query()
            ->where('site_id', $site->id)
            ->where('event_type', 'monitoring.note')
            ->with('creator:id,name')
            ->orderByDesc('occurred_at')
            ->limit(300)
            ->get()
            ->map(static function (SiteEvent $event): array {
                $metadata = is_array($event->metadata) ? $event->metadata : [];

                return [
                    'id' => (int) $event->id,
                    'title' => (string) $event->title,
                    'note' => (string) ($event->description ?? ''),
                    'status' => (string) ($metadata['note_status'] ?? 'en_curso'),
                    'created_at' => optional($event->occurred_at)?->toIso8601String(),
                    'resolved_at' => isset($metadata['resolved_at']) ? (string) $metadata['resolved_at'] : null,
                    'is_hidden' => (bool) ($metadata['is_hidden'] ?? false),
                    'created_by_name' => $event->creator?->name,
                ];
            })
            ->values()
            ->all();

        return [
            'site' => array_merge($site->toArray(), [
                'ssl_certificate' => $normalizedSslCertificate,
            ]),
            'inspectionProfile' => [
                'inspected_at' => optional($inspectionProfile?->inspected_at)?->toIso8601String(),
                'dns_status' => (string) ($inspectionProfile?->dns_status ?? 'pending'),
                'dns_records' => is_array($inspectionProfile?->dns_records) ? $inspectionProfile->dns_records : [],
                'http_status' => $inspectionProfile?->http_status,
                'https_status' => $inspectionProfile?->https_status,
                'response_time_ms' => $inspectionProfile?->response_time_ms,
                'ssl' => $sslPayload,
                'security_headers' => $securityPayload,
                'fingerprint' => is_array($inspectionProfile?->fingerprint_payload) ? $inspectionProfile->fingerprint_payload : [],
                'cms_name' => (string) ($inspectionProfile?->cms_name ?? 'No determinado'),
                'cms_version' => (string) ($inspectionProfile?->cms_version ?? 'No determinado'),
                'server_signature' => (string) ($inspectionProfile?->server_signature ?? 'No determinado'),
                'runtime_name' => (string) ($inspectionProfile?->runtime_name ?? 'No determinado'),
                'runtime_version' => (string) ($inspectionProfile?->runtime_version ?? 'No determinado'),
                'frameworks' => is_array($inspectionProfile?->js_frameworks) ? $inspectionProfile->js_frameworks : [],
                'risk_score' => $inspectionProfile?->risk_score,
                'risk_level' => (string) ($inspectionProfile?->risk_level ?? 'No determinado'),
                'analysis_errors' => is_array($inspectionProfile?->analysis_errors) ? $inspectionProfile->analysis_errors : [],
            ],
            'currentDiagnosis' => $diagnosis,
            'notesTimeline' => $notesTimeline,
            'timeline' => $checksTimeline,
            'statusBreakdown24h' => $statusBreakdown24h,
            'uptime24h' => $this->siteCheckRepository->uptimePercentage((int) $site->id, 24),
            'avgResponse24h' => $this->siteCheckRepository->avgResponseTime((int) $site->id, 24),
            'openAlerts' => $this->normalizeOpenAlerts($this->alertRepository->openForSite((int) $site->id)->all()),
            'events' => $this->normalizeRecentEvents(
                $site->events()->orderByDesc('occurred_at')->limit(20)->get()->all(),
            ),
            'trafficSeries24h' => $trafficSeries24h->all(),
            'trafficSeries1h' => $trafficSeries1h->all(),
            'securityHeaders' => $securityHeaders,
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $series
     */
    private function isFlatTrafficSeries(array $series): bool
    {
        if ($series === []) {
            return true;
        }

        foreach ($series as $point) {
            if ((int) ($point['rpm'] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildTrafficFallbackFromChecks(int $siteId, int $hours): Collection
    {
        $checks = SiteCheck::query()
            ->where('site_id', $siteId)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->whereNotNull('checked_at')
            ->orderBy('checked_at')
            ->get(['checked_at', 'status']);

        if ($checks->isEmpty()) {
            return collect();
        }

        return $checks->map(static function (SiteCheck $check): array {
            $status = strtolower((string) ($check->status ?? 'unknown'));

            return [
                'at' => optional($check->checked_at)?->toIso8601String(),
                'rpm' => 12,
                'error_rate_pct' => match ($status) {
                    'down', 'timeout' => 100.0,
                    'degraded' => 50.0,
                    default => 0.0,
                },
            ];
        })->values();
    }

    /**
     * @param  array<int, mixed>  $timeline
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTimeline(array $timeline, int $hours, int $points): array
    {
        if ($timeline !== []) {
            return array_values(array_map(static function (mixed $point): array {
                $checkedAt = null;

                if (isset($point->checked_at) && $point->checked_at !== null) {
                    $checkedAt = $point->checked_at instanceof Carbon
                        ? $point->checked_at->toIso8601String()
                        : Carbon::parse((string) $point->checked_at)->toIso8601String();
                }

                return [
                    'id' => (int) ($point->id ?? 0),
                    'checked_at' => $checkedAt,
                    'status' => (string) ($point->status ?? 'unknown'),
                    'http_code' => isset($point->http_code) ? (int) $point->http_code : null,
                    'response_time_ms' => isset($point->response_time_ms) ? (float) $point->response_time_ms : 0.0,
                ];
            }, $timeline));
        }

        $steps = max(1, $points - 1);
        $start = now()->subHours($hours);
        $intervalSeconds = max(1, (int) floor(($hours * 3600) / $steps));
        $fallback = [];

        for ($i = 0; $i <= $steps; $i++) {
            $fallback[] = [
                'id' => 0,
                'checked_at' => $start->copy()->addSeconds($i * $intervalSeconds)->toIso8601String(),
                'status' => 'unknown',
                'http_code' => null,
                'response_time_ms' => 0.0,
            ];
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @return array<string, int>
     */
    private function normalizeStatusBreakdown(array $breakdown): array
    {
        return [
            'up' => (int) ($breakdown['up'] ?? 0),
            'down' => (int) ($breakdown['down'] ?? 0),
            'degraded' => (int) ($breakdown['degraded'] ?? 0),
            'timeout' => (int) ($breakdown['timeout'] ?? 0),
        ];
    }

    /**
     * @param  array<int, Alert>  $alerts
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOpenAlerts(array $alerts): array
    {
        return array_values(array_map(static function (Alert $alert): array {
            $severity = strtolower((string) $alert->severity);
            $message = trim((string) $alert->message);

            return [
                'id' => (int) $alert->id,
                'title' => (string) $alert->title,
                'severity' => $severity,
                'severity_label' => $severity === 'critical' ? 'Crítica' : 'Advertencia',
                'severity_class' => $severity === 'critical' ? 'bg-rose-500/15 text-rose-200' : 'bg-amber-500/15 text-amber-200',
                'triggered_at' => optional($alert->triggered_at)?->toIso8601String(),
                'friendly_description' => self::alertFriendlyDescription((string) $alert->title, $message),
                'recommended_action' => self::alertRecommendedAction((string) $alert->title, $message),
            ];
        }, $alerts));
    }

    /**
     * @param  array<int, SiteEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRecentEvents(array $events): array
    {
        return array_values(array_map(static function (SiteEvent $event): array {
            [$icon, $iconClass, $description] = self::eventMetadata($event);

            return [
                'id' => (int) $event->id,
                'title' => (string) $event->title,
                'event_type' => (string) $event->event_type,
                'severity' => strtolower((string) $event->severity),
                'icon' => $icon,
                'icon_class' => $iconClass,
                'description' => $description,
                'occurred_at' => optional($event->occurred_at)?->toIso8601String(),
            ];
        }, $events));
    }

    /**
     * @return array{bucket: string, label: string, reason: string}
     */
    private function resolveSiteDiagnosis(Site $site, string $status, ?SiteCheck $latestCheck): array
    {
        $errorMessage = trim((string) ($latestCheck?->error_message ?? ''));
        $httpCode = $latestCheck?->http_code;
        $responseTimeMs = $latestCheck?->response_time_ms;
        $normalizedError = mb_strtolower($errorMessage);

        $checkInterval = max(1, (int) ($site->check_interval_min ?? 5));
        $staleMinutes = max(3, $checkInterval * 3);
        $lastCheckedAt = $site->last_checked_at;

        if ($lastCheckedAt === null || $lastCheckedAt->lt(now()->subMinutes($staleMinutes))) {
            return [
                'bucket' => 'en_cola',
                'label' => 'En la cola',
                'reason' => 'Aun no hay una medicion reciente para este sitio.',
            ];
        }

        if ($status === 'up') {
            return [
                'bucket' => 'operativo',
                'label' => 'Operativo',
                'reason' => 'Respuesta estable dentro de parametros esperados.',
            ];
        }

        $isTimeout = $normalizedError !== '' && (str_contains($normalizedError, 'timeout') || str_contains($normalizedError, 'curl error 28'));

        if ($status === 'down') {
            return [
                'bucket' => 'no_responde',
                'label' => 'No responde',
                'reason' => $isTimeout
                    ? 'El sitio excedio el tiempo maximo de respuesta.'
                    : ($errorMessage !== ''
                        ? $errorMessage
                        : ($httpCode !== null ? 'El servidor devolvio HTTP '.$httpCode.'.' : 'No se obtuvo respuesta valida.')),
            ];
        }

        if ($status === 'degraded') {
            if ($isTimeout) {
                return [
                    'bucket' => 'inestable',
                    'label' => 'Con incidencias',
                    'reason' => 'Presenta timeouts intermitentes.',
                ];
            }

            if ($httpCode !== null && $httpCode >= 400) {
                return [
                    'bucket' => 'responde_con_errores',
                    'label' => 'Con incidencias',
                    'reason' => 'El sitio responde con HTTP '.$httpCode.'.',
                ];
            }

            if ($responseTimeMs !== null && $responseTimeMs >= 1500) {
                return [
                    'bucket' => 'respuesta_lenta',
                    'label' => 'Con incidencias',
                    'reason' => 'Tiempo de respuesta alto ('.$responseTimeMs.' ms).',
                ];
            }

            return [
                'bucket' => 'inestable',
                'label' => 'Con incidencias',
                'reason' => $errorMessage !== '' ? $errorMessage : 'Comportamiento intermitente detectado.',
            ];
        }

        return [
            'bucket' => 'en_cola',
            'label' => 'En la cola',
            'reason' => 'Pendiente de primera ronda de telemetria.',
        ];
    }
}
