<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteCheckRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\SiteGroup;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Support\AssetIntelligenceSchema;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Modules\Monitoring\Jobs\RunHeadCheckJob;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

final class DashboardController extends Controller
{
    private const DASHBOARD_DEFAULT_PER_PAGE = 50;

    private const DASHBOARD_MAX_PER_PAGE = 500;

    private const DASHBOARD_REFRESH_INTERVAL_MS = 15000;

    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly SiteCheckRepositoryInterface $siteCheckRepository,
        private readonly AlertRepositoryInterface $alertRepository,
        private readonly AssetIntelligenceSchema $assetSchema,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: 'all',
            'group_id' => $request->integer('group_id') ?: null,
            'search' => $request->string('search')->toString(),
            'priority' => $request->integer('priority') ?: null,
        ];

        $sites = $this->siteRepository->paginate(
            perPage: max(1, min(self::DASHBOARD_MAX_PER_PAGE, $request->integer('per_page', self::DASHBOARD_DEFAULT_PER_PAGE))),
            filters: array_filter($filters, static fn ($value): bool => $value !== null && $value !== '')
        );

        $sites = $this->mapSitesForDashboard($sites);

        return Inertia::render('Monitoring/Dashboard', [
            'filters' => [
                'search' => (string) ($filters['search'] ?? ''),
                'status' => (string) ($filters['status'] ?? 'all'),
            ],
            'statusCounts' => $this->dashboardStatusCounts(),
            'diagnosticBreakdown' => $this->diagnosticBreakdown(),
            'searchSuggestions' => $this->searchSuggestions(),
            'pipelineMetrics' => $this->pipelineMetrics(),
            'sites' => $sites,
            'refreshIntervalMs' => self::DASHBOARD_REFRESH_INTERVAL_MS,
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    public function registerOfficialSite(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'regex:/^(?:[a-z0-9-]+\.)+udg\.mx$/i'],
            'entity' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'domain.regex' => 'El dominio debe pertenecer a la zona .udg.mx',
        ]);

        $domain = mb_strtolower(trim((string) $validated['domain']));

        $alreadyExists = Site::query()->where('domain', $domain)->exists();
        if ($alreadyExists) {
            return back()->with('warning', 'Ese dominio ya existe en el inventario.');
        }

        $entity = trim((string) ($validated['entity'] ?? 'Nuevos sitios UDG'));
        if ($entity === '') {
            $entity = 'Nuevos sitios UDG';
        }

        $group = SiteGroup::query()->firstOrCreate(
            ['slug' => Str::slug($entity)],
            [
                'name' => $entity,
                'description' => 'Sitios agregados manualmente para monitoreo operativo.',
                'color' => '#0F766E',
            ],
        );

        Site::query()->create([
            'site_group_id' => (int) $group->id,
            'name' => trim((string) $validated['name']),
            'slug' => Str::slug(trim((string) $validated['name']) . '-' . $domain),
            'domain' => $domain,
            'url' => 'https://' . $domain,
            'is_active' => true,
            'is_monitored' => true,
            'priority' => 2,
            'current_status' => 'unknown',
            'current_score' => 100,
            'current_score_level' => 'unknown',
            'check_interval_min' => 5,
            'notes' => trim((string) ($validated['notes'] ?? 'Alta manual desde dashboard')),
            'tags' => ['official', 'manual-registration'],
        ]);

        return back()->with('success', 'Sitio registrado y agregado al monitoreo.');
    }

    private function warmTelemetryPipeline(): void
    {
        if (! app()->environment(['local', 'development'])) {
            return;
        }

        if (! Cache::add('monitoring:dashboard:warmup', now()->timestamp, 60)) {
            return;
        }

        app()->terminating(function (): void {
            $this->dispatchWarmupCommands();
        });
    }

    private function dispatchWarmupCommands(): void
    {
        $commands = [
            ['monitoring:dispatch-head-checks', ['--limit' => 50, '--chunk' => 25, '--stagger' => 0]],
            ['monitoring:dispatch-ssl-checks', ['--limit' => 50, '--chunk' => 25, '--stagger' => 0]],
            ['monitoring:dispatch-security-headers-checks', ['--limit' => 50, '--chunk' => 25, '--stagger' => 0]],
            ['monitoring:dispatch-technology-scans', ['--limit' => 50]],
        ];

        foreach ($commands as [$command, $arguments]) {
            try {
                Artisan::call($command, $arguments);
            } catch (\Throwable) {
                // El warm-up es opcional y no debe romper el request del dashboard.
            }
        }
    }

    public function groupView(Request $request, SiteGroup $group): Response
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: 'all',
            'search' => $request->string('search')->toString(),
            'priority' => $request->integer('priority') ?: null,
            'group_id' => $group->id,
        ];

        return Inertia::render('Monitoring/GroupDetail', [
            'group' => $group,
            'filters' => $filters,
            'sites' => $this->siteRepository->paginate(
                perPage: max(1, min(100, $request->integer('per_page', 20))),
                filters: array_filter($filters, static fn ($value): bool => $value !== null && $value !== '')
            ),
            'statusCounts' => $this->dashboardStatusCounts($group->id),
            'openAlerts' => $this->alertRepository->recentOpenForGroup($group->id, 20),
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    public function diagnosticSites(Request $request, string $bucket): Response
    {
        $allowedBuckets = [
            'operativo',
            'respuesta_lenta',
            'responde_con_errores',
            'inestable',
            'no_responde',
            'sin_actualizar',
        ];

        abort_unless(in_array($bucket, $allowedBuckets, true), 404);

        $search = trim($request->string('search')->toString());
        $perPage = max(1, min(500, $request->integer('per_page', self::DASHBOARD_DEFAULT_PER_PAGE)));
        $page = max(1, $request->integer('page', 1));

        $sites = Site::query()
            ->with(['latestCheck', 'siteGroup', 'sslCertificate', 'cmsDetail', 'primaryServer', 'siteTechnologies.technology'])
            ->orderByRaw('LOWER(name)')
            ->orderBy('id')
            ->get();

        $mapped = $sites
            ->map(fn (Site $site): array => $this->mapDashboardSite($site))
            ->filter(static fn (array $site): bool => (string) ($site['diagnostic_bucket'] ?? '') === $bucket)
            ->values();

        if ($search !== '') {
            $searchLower = mb_strtolower($search);
            $mapped = $mapped
                ->filter(static function (array $site) use ($searchLower): bool {
                    $name = mb_strtolower((string) ($site['name'] ?? ''));
                    $domain = mb_strtolower((string) ($site['domain'] ?? ''));
                    $url = mb_strtolower((string) ($site['url'] ?? ''));

                    return str_contains($name, $searchLower)
                        || str_contains($domain, $searchLower)
                        || str_contains($url, $searchLower);
                })
                ->values();
        }

        $total = $mapped->count();
        $slice = $mapped->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => route('monitoring.diagnostic.sites', ['bucket' => $bucket]),
                'query' => array_filter([
                    'search' => $search !== '' ? $search : null,
                    'per_page' => $perPage,
                ]),
            ],
        );

        return Inertia::render('Monitoring/DiagnosticSites', [
            'bucket' => $bucket,
            'bucketLabel' => $this->diagnosticBucketLabel($bucket),
            'search' => $search,
            'sites' => $paginator,
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    public function scanSite(int $siteId)
    {
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('is_monitored', true)
            ->first();

        abort_if(! $site instanceof Site, 404);

        // Reescaneo prioritario: pausa temporal de despacho general para atender este sitio primero.
        Cache::put('monitoring:single-site-rescan:pause', true, now()->addMinutes(5));
        Cache::put('monitoring:single-site-rescan:site_id', (int) $site->id, now()->addMinutes(5));

        $site->forceFill(['last_checked_at' => null])->save();
        RunHeadCheckJob::dispatchSync((int) $site->id);

        Cache::forget('monitoring:single-site-rescan:pause');
        Cache::forget('monitoring:single-site-rescan:site_id');

        return back()->with('success', 'Reescaneo prioritario completado para el sitio seleccionado. El escaneo general puede continuar.');
    }

    public function scanAll(Request $request)
    {
        if (! Cache::add('monitoring:manual-scan-all:cooldown', now()->timestamp, 45)) {
            return back()->with('warning', 'Ya hay una actualizacion masiva en curso. Espera unos segundos e intenta de nuevo.');
        }

        // Solo marca sitios para que el scheduler horario procese el lote sin bloquear la UI.
        Site::query()
            ->where('is_active', true)
            ->where('is_monitored', true)
            ->update(['last_checked_at' => null]);

        return back()->with('success', 'Se marco el inventario para reescaneo. El scheduler horario y los workers lo procesaran sin bloquear la pagina.');
    }

    /**
     * @return array<string, int>
     */
    /**
     * @return array<string, mixed>
     */
    private function pipelineMetrics(): array
    {
        $summary = $this->siteCheckRepository->summarizeWindow(1);

        $totalChecksLastHour = max(0, (int) ($summary['total_checks'] ?? 0));
        $downChecksLastHour = max(0, (int) ($summary['down_checks'] ?? 0));
        $avgLatencyMsLastHour = $totalChecksLastHour > 0 && isset($summary['avg_latency_ms']) && $summary['avg_latency_ms'] !== null
            ? round((float) $summary['avg_latency_ms'], 2)
            : 0.0;

        $queueDepth = [
            'monitoring-uptime' => 0,
            'monitoring-ssl' => 0,
            'monitoring-tech' => 0,
            'monitoring-headers' => 0,
            'monitoring-alerts' => 0,
        ];

        foreach (array_keys($queueDepth) as $queueName) {
            try {
                $queueDepth[$queueName] = max(0, (int) Redis::llen('queues:' . $queueName));
            } catch (\Throwable) {
                $queueDepth[$queueName] = 0;
            }
        }

        return [
            'window' => '1h',
            'totalChecks' => $totalChecksLastHour,
            'downChecks' => $downChecksLastHour,
            'errorRatePct' => $totalChecksLastHour > 0 ? round(($downChecksLastHour / $totalChecksLastHour) * 100, 2) : 0.0,
            'avgLatencyMs' => $avgLatencyMsLastHour,
            'queueDepth' => $queueDepth,
        ];
    }

    private function mapSitesForDashboard(LengthAwarePaginator $sites): LengthAwarePaginator
    {
        $sites->setCollection(
            $sites->getCollection()->map(fn (Site $site): array => $this->mapDashboardSite($site))
        );

        return $sites;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDashboardSite(Site $site): array
    {
        $latestCheck = $site->latestCheck;
        $status = strtolower((string) ($site->current_status ?? 'unknown'));
        $diagnosis = $this->resolveSiteDiagnosis($site, $status, $latestCheck);
        $displayStatus = $this->resolveDashboardStatus($site, $status, $latestCheck);
        $notes = $this->splitProjectNotes((string) ($site->notes ?? ''));

        return [
            'id' => (int) $site->id,
            'name' => $site->name,
            'domain' => $site->domain,
            'url' => $site->url,
            'entity' => (string) ($site->siteGroup?->name ?? 'Sin entidad'),
            'cms' => (string) ($site->cmsDetail?->cms_type ?? ($site->siteTechnologies->first()?->technology?->slug ?? 'Sin dato')),
            'server_ip' => (string) ($site->primaryServer->first()?->ip_address ?? 'Externo'),
            'certificate_present' => $site->sslCertificate !== null,
            'certificate_label' => $site->sslCertificate !== null ? 'Sí' : 'No',
            'project_status' => $notes['status'],
            'comments' => $notes['comments'],
            'current_status' => $site->current_status,
            'current_status_code' => $status,
            'display_status_code' => $displayStatus,
            'last_checked_at' => optional($site->last_checked_at)?->toIso8601String(),
            'diagnostic_bucket' => $diagnosis['bucket'],
            'diagnostic_label' => $diagnosis['label'],
            'diagnostic_reason' => $diagnosis['reason'],
        ];
    }

    private function diagnosticBucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'operativo' => 'Operativos',
            'respuesta_lenta' => 'Respuesta lenta',
            'responde_con_errores' => 'Responde con errores',
            'inestable' => 'Inestables',
            'no_responde' => 'No responde',
            default => 'En la cola',
        };
    }

    /**
     * @return array<string, int>
     */
    private function dashboardStatusCounts(?int $groupId = null): array
    {
        $counts = [
            'up' => 0,
            'down' => 0,
            'degraded' => 0,
            'unknown' => 0,
        ];

        $query = Site::query()
            ->with('latestCheck');

        if ($groupId !== null) {
            $query->where('site_group_id', $groupId);
        }

        foreach ($query->get() as $site) {
            $status = strtolower((string) ($site->current_status ?? 'unknown'));
            $resolvedStatus = $this->resolveDashboardStatus($site, $status, $site->latestCheck);

            if (! array_key_exists($resolvedStatus, $counts)) {
                $counts['unknown']++;

                continue;
            }

            $counts[$resolvedStatus]++;
        }

        return $counts;
    }

    private function resolveDashboardStatus(Site $site, string $status, ?SiteCheck $latestCheck): string
    {
        $diagnosis = $this->resolveSiteDiagnosis($site, $status, $latestCheck);

        return match ((string) ($diagnosis['bucket'] ?? 'sin_actualizar')) {
            'operativo' => 'up',
            'no_responde' => 'down',
            'respuesta_lenta', 'responde_con_errores', 'inestable' => 'degraded',
            default => 'unknown',
        };
    }

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
                'bucket' => 'sin_actualizar',
                'label' => 'Sin actualizar',
                'reason' => 'No se recibieron mediciones recientes para este sitio.',
            ];
        }

        if ($status === 'up') {
            return [
                'bucket' => 'operativo',
                'label' => 'Operativo',
                'reason' => 'Respuesta estable dentro de los parametros esperados.',
            ];
        }

        $isTimeout = $normalizedError !== '' && (str_contains($normalizedError, 'timeout') || str_contains($normalizedError, 'curl error 28'));

        if ($status === 'down') {
            if ($isTimeout) {
                return [
                    'bucket' => 'no_responde',
                    'label' => 'No responde',
                    'reason' => 'El sitio no respondio dentro del tiempo maximo permitido.',
                ];
            }

            return [
                'bucket' => 'no_responde',
                'label' => 'No responde',
                'reason' => $errorMessage !== ''
                    ? $errorMessage
                    : ($httpCode !== null ? 'El servidor devolvio HTTP ' . $httpCode . '.' : 'No se obtuvo respuesta valida del sitio.'),
            ];
        }

        if ($status === 'degraded') {
            if ($isTimeout) {
                return [
                    'bucket' => 'inestable',
                    'label' => 'Inestable',
                    'reason' => 'Presenta timeouts intermitentes durante el monitoreo.',
                ];
            }

            if ($httpCode !== null && $httpCode >= 400) {
                return [
                    'bucket' => 'responde_con_errores',
                    'label' => 'Responde con errores',
                    'reason' => 'Responde, pero devolvio HTTP ' . $httpCode . '.',
                ];
            }

            if ($responseTimeMs !== null && $responseTimeMs >= 1500) {
                return [
                    'bucket' => 'respuesta_lenta',
                    'label' => 'Respuesta lenta',
                    'reason' => 'Tiempo de respuesta elevado (' . $responseTimeMs . ' ms).',
                ];
            }

            return [
                'bucket' => 'inestable',
                'label' => 'Inestable',
                'reason' => $errorMessage !== ''
                    ? $errorMessage
                    : 'Comportamiento intermitente fuera del umbral normal.',
            ];
        }

        return [
            'bucket' => 'sin_actualizar',
            'label' => 'Sin actualizar',
            'reason' => 'Aun no hay suficiente telemetria para clasificarlo con precision.',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function diagnosticBreakdown(): array
    {
        $result = [
            'operativo' => 0,
            'respuesta_lenta' => 0,
            'responde_con_errores' => 0,
            'inestable' => 0,
            'no_responde' => 0,
            'sin_actualizar' => 0,
        ];

        $sites = Site::query()
            ->with('latestCheck')
            ->get();

        foreach ($sites as $site) {
            $status = strtolower((string) ($site->current_status ?? 'unknown'));
            $diagnosis = $this->resolveSiteDiagnosis($site, $status, $site->latestCheck);
            $bucket = (string) ($diagnosis['bucket'] ?? 'sin_actualizar');

            if (! array_key_exists($bucket, $result)) {
                $result['sin_actualizar']++;
                continue;
            }

            $result[$bucket]++;
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function assetTypeCounts(): array
    {
        if (! $this->assetSchema->isReady()) {
            return [];
        }

        return Site::query()
            ->where('is_active', true)
            ->where('is_monitored', true)
            ->selectRaw("COALESCE(asset_type, 'unknown') as asset_type, COUNT(*) as total")
            ->groupBy('asset_type')
            ->pluck('total', 'asset_type')
            ->map(static fn ($value): int => (int) $value)
            ->toArray();
    }

    /**
     * @return array<string, int>
     */
    private function assetRoleCounts(): array
    {
        if (! $this->assetSchema->isReady()) {
            return [];
        }

        return Site::query()
            ->where('is_active', true)
            ->where('is_monitored', true)
            ->selectRaw("COALESCE(asset_role, 'unknown') as asset_role, COUNT(*) as total")
            ->groupBy('asset_role')
            ->pluck('total', 'asset_role')
            ->map(static fn ($value): int => (int) $value)
            ->toArray();
    }

    private function dispatchMassiveScan(int $limit, int $batch): void
    {
        try {
            Artisan::call('monitoring:dispatch-head-checks', [
                '--limit' => $limit,
                '--chunk' => $batch,
                '--stagger' => 0,
            ]);

            Artisan::call('monitoring:dispatch-ssl-checks', [
                '--limit' => $limit,
                '--chunk' => $batch,
                '--stagger' => 0,
            ]);

            Artisan::call('monitoring:dispatch-security-headers-checks', [
                '--limit' => $limit,
                '--chunk' => $batch,
                '--stagger' => 0,
            ]);

            Artisan::call('monitoring:dispatch-technology-scans', [
                '--limit' => $limit,
            ]);
        } catch (\Throwable) {
            // El disparo masivo es best effort; nunca debe romper el flujo web.
        }
    }

    /**
     * @return array<int, string>
     */
    private function searchSuggestions(): array
    {
        $sites = Site::query()
            ->orderByRaw('LOWER(name)')
            ->limit(250)
            ->get(['name', 'domain']);

        return $sites
            ->flatMap(static function (Site $site): array {
                return array_values(array_filter([
                    trim((string) $site->name),
                    trim((string) $site->domain),
                ], static fn (string $value): bool => $value !== ''));
            })
            ->unique()
            ->values()
            ->all();
    }

    private function dispatchMonitoringJob(string $jobClass, mixed ...$arguments): mixed
    {
        if ($this->shouldDispatchMonitoringSynchronously()) {
            return $jobClass::dispatchSync(...$arguments);
        }

        return $jobClass::dispatch(...$arguments);
    }

    private function shouldDispatchMonitoringSynchronously(): bool
    {
        return app()->environment(['local', 'development', 'testing']);
    }

    /**
     * @return array{status: string, comments: string}
     */
    private function splitProjectNotes(string $notes): array
    {
        $normalized = trim($notes);

        if ($normalized === '') {
            return [
                'status' => 'Sin dato',
                'comments' => '',
            ];
        }

        $parts = array_values(array_filter(array_map('trim', explode('·', $normalized)), static fn (string $part): bool => $part !== ''));

        if (count($parts) === 1) {
            return [
                'status' => $parts[0],
                'comments' => '',
            ];
        }

        return [
            'status' => $parts[0],
            'comments' => implode(' · ', array_slice($parts, 1)),
        ];
    }

}
