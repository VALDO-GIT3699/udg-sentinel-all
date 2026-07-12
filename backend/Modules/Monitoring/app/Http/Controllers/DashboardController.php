<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteCheckRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\MonitoringMassScanRun;
use App\Models\Alert;
use App\Models\Setting;
use App\Models\SiteGroup;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\SslCertificate;
use App\Models\SiteTechnology;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Modules\Monitoring\Jobs\DispatchMassScanRunJob;
use Modules\Monitoring\Jobs\RunHeadCheckJob;
use Modules\Monitoring\Jobs\RunSecurityHeadersCheckJob;
use Modules\Monitoring\Jobs\RunSslCheckJob;
use Modules\Monitoring\Jobs\RunTechnologyScanJob;
use Modules\Monitoring\Support\DetectedTechnology;
use Modules\Monitoring\Support\MassScanProgress;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    private const DASHBOARD_DEFAULT_PER_PAGE = 50;

    private const DASHBOARD_MAX_PER_PAGE = 500;

    private const DASHBOARD_REFRESH_INTERVAL_MS = 5000;

    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly SiteCheckRepositoryInterface $siteCheckRepository,
        private readonly AlertRepositoryInterface $alertRepository,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->warmTelemetryPipeline();

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
            'massScanProgress' => MassScanProgress::getCurrent(),
            'massScanHistory' => $this->massScanHistory(),
            'preventiveExpirations' => $this->preventiveExpirations(),
            'scheduledScansEnabled' => $this->scheduledScansEnabled(),
            'canManageSettings' => (bool) $request->user()?->can('monitoring.manage_settings'),
            'refreshIntervalMs' => self::DASHBOARD_REFRESH_INTERVAL_MS,
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    public function exportReport(Request $request)
    {
        $payload = $this->buildExecutivePdfPayload();
        $fileName = 'UDG_Sentinel_Reporte_General_' . now()->format('d_m_Y') . '.pdf';

        $pdf = Pdf::loadView('monitoring::reports.executive-report', $payload)
            ->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function preventiveExpirations(): array
    {
        $windowEnd = now()->addDays(60);

        $rows = Site::query()
            ->selectRaw(
                'DISTINCT ON (sites.id) sites.id as site_id, sites.name as site_name, sites.domain as domain, ssl_certificates.valid_until as valid_until, ssl_certificates.issuer as issuer'
            )
            ->join('ssl_certificates', 'ssl_certificates.site_id', '=', 'sites.id')
            ->whereNotNull('ssl_certificates.valid_until')
            ->where('ssl_certificates.valid_until', '>=', now())
            ->where('ssl_certificates.valid_until', '<=', $windowEnd)
            ->orderBy('sites.id')
            ->orderByDesc('ssl_certificates.valid_until')
            ->orderByDesc('ssl_certificates.id')
            ->limit(250)
            ->get();

        return $rows
            ->map(static function ($row): array {
                $validUntil = $row->valid_until instanceof \Illuminate\Support\Carbon
                    ? $row->valid_until
                    : \Illuminate\Support\Carbon::parse((string) $row->valid_until);
                $daysRemaining = max(0, (int) now()->diffInDays($validUntil, false));

                return [
                    'id' => (int) $row->site_id,
                    'site_name' => (string) $row->site_name,
                    'domain' => (string) $row->domain,
                    'valid_until' => $validUntil->toIso8601String(),
                    'days_remaining' => $daysRemaining,
                    'issuer' => (string) ($row->issuer ?? ''),
                    'month_label' => $validUntil->format('Y-m'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExecutivePdfPayload(): array
    {
        $statusCounts = $this->dashboardStatusCounts();
        $diagnosticBreakdown = $this->diagnosticBreakdown();
        $preventiveExpirations = array_slice($this->preventiveExpirations(), 0, 12);
        $recentRuns = array_slice($this->massScanHistory(), 0, 8);

        $sites = Site::query()
            ->with(['latestCheck', 'sslCertificate', 'cmsDetail', 'siteTechnologies.technology'])
            ->where('is_active', true)
            ->where('is_monitored', true)
            ->orderByRaw('LOWER(name)')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(function (Site $site): array {
                $status = strtolower((string) ($site->current_status ?? 'unknown'));
                $resolvedStatus = $this->resolveDashboardStatus($site, $status, $site->latestCheck);
                $diagnosis = $this->resolveSiteDiagnosis($site, $status, $site->latestCheck);
                $technology = $this->resolveTechnologyInfo($site);
                $certificate = $site->sslCertificate;

                $certificateLabel = 'Sin certificado';

                if ($certificate !== null) {
                    $validUntil = $certificate->valid_until;
                    $daysRemaining = $validUntil !== null
                        ? (int) now()->diffInDays($validUntil, false)
                        : $certificate->days_remaining;

                    if ($daysRemaining !== null && $daysRemaining < 0) {
                        $certificateLabel = 'Expirado';
                    } elseif ($daysRemaining !== null && $daysRemaining <= 30) {
                        $certificateLabel = 'Vence en ' . $daysRemaining . ' días';
                    } else {
                        $certificateLabel = 'Vigente';
                    }
                }

                return [
                    'name' => (string) $site->name,
                    'domain' => (string) $site->domain,
                    'status' => $resolvedStatus,
                    'status_label' => strtoupper($resolvedStatus),
                    'diagnostic_label' => (string) ($diagnosis['label'] ?? 'Sin diagnóstico'),
                    'diagnostic_reason' => (string) ($diagnosis['reason'] ?? ''),
                    'technology' => (string) ($technology['label'] ?? 'No identificada'),
                    'certificate' => $certificateLabel,
                    'last_checked_at' => optional($site->last_checked_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'status_counts' => $statusCounts,
            'diagnostic_breakdown' => $diagnosticBreakdown,
            'preventive_expirations' => $preventiveExpirations,
            'recent_runs' => $recentRuns,
            'sites' => $sites,
        ];
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

    public function scanSite(int $siteId)
    {
        $site = Site::query()
            ->whereKey($siteId)
            ->first();

        abort_if(! $site instanceof Site, 404);

        return $this->startManualScanFromIds(
            request: request(),
            siteIds: [(int) $site->id],
            triggerMode: 'manual_single',
            enforceMassInterval: false,
            successMessage: 'Se programó el escaneo completo del sitio seleccionado.',
        );
    }

    public function scanAll(Request $request): RedirectResponse|JsonResponse
    {
        $siteIds = Site::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($siteId): int => (int) $siteId)
            ->all();

        return $this->startManualScanFromIds(
            request: $request,
            siteIds: $siteIds,
            triggerMode: 'manual',
            enforceMassInterval: true,
            successMessage: 'Se programó una actualización masiva completa. El sistema revalida disponibilidad, SSL, cabeceras y tecnología en todos los sitios.',
        );
    }

    public function scanSelected(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'site_ids' => ['required', 'array', 'min:1', 'max:500'],
            'site_ids.*' => ['integer', 'min:1'],
        ]);

        $siteIds = Site::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $validated['site_ids']))))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($siteId): int => (int) $siteId)
            ->all();

        return $this->startManualScanFromIds(
            request: $request,
            siteIds: $siteIds,
            triggerMode: 'manual_selected',
            enforceMassInterval: false,
            successMessage: 'Se programó el escaneo de los sitios seleccionados.',
        );
    }

    public function scanRunView(Request $request, string $runId): Response
    {
        $progress = $this->resolveRunProgress($runId);

        abort_if($progress === null, 404);

        return Inertia::render('Monitoring/ScanProgress', [
            'runId' => $runId,
            'progress' => $progress,
            'dashboardUrl' => route('monitoring.dashboard'),
            'updatedAt' => now()->toIso8601String(),
            'canManageSettings' => (bool) $request->user()?->can('monitoring.manage_settings'),
        ]);
    }

    public function scanRunProgress(string $runId): JsonResponse
    {
        $progress = $this->resolveRunProgress($runId);

        if ($progress === null) {
            return response()->json([
                'exists' => false,
                'active' => false,
                'progress' => null,
            ]);
        }

        return response()->json([
            'exists' => true,
            'active' => ($progress['status'] ?? 'running') === 'running',
            'progress' => $progress,
        ]);
    }

    public function updateScheduledScans(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $validated['enabled'];

        Setting::set('monitoring.scheduled_scans_enabled', $enabled);

        $message = $enabled
            ? 'Escaneos programados activados. El scheduler volverá a despachar ciclos automáticos.'
            : 'Escaneos programados desactivados. Solo quedará disponible el modo manual.';

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'enabled' => $enabled,
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }

    private function scanAllResponse(
        Request $request,
        bool $started,
        string $status,
        string $message,
        ?array $progress,
        ?string $runId = null,
        ?string $redirectUrl = null,
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'started' => $started,
                'status' => $status,
                'message' => $message,
                'progress' => $progress,
                'run_id' => $runId,
                'redirect_url' => $redirectUrl,
            ]);
        }

        $flashKey = $started ? 'success' : 'warning';

        return back()->with($flashKey, $message);
    }

    /**
     * @param array<int, int> $siteIds
     */
    private function startManualScanFromIds(
        Request $request,
        array $siteIds,
        string $triggerMode,
        bool $enforceMassInterval,
        string $successMessage
    ): RedirectResponse|JsonResponse {
        $currentProgress = MassScanProgress::getCurrent();

        if (is_array($currentProgress) && ($currentProgress['status'] ?? 'running') === 'running') {
            $currentRunId = (string) ($currentProgress['run_id'] ?? '');

            return $this->scanAllResponse(
                request: $request,
                started: false,
                status: 'already_running',
                message: 'Ya hay una actualización en curso. Puedes seguirla en la página de progreso.',
                progress: $currentProgress,
                runId: $currentRunId !== '' ? $currentRunId : null,
                redirectUrl: $currentRunId !== '' ? route('monitoring.scans.show', ['runId' => $currentRunId]) : null,
            );
        }

        if (! Cache::add('monitoring:manual-scan:cooldown', now()->timestamp, 10)) {
            return $this->scanAllResponse(
                request: $request,
                started: false,
                status: 'cooldown',
                message: 'Ya hay una actualización en proceso de arranque. Espera unos segundos e intenta de nuevo.',
                progress: MassScanProgress::getCurrent(),
            );
        }

        if ($siteIds === []) {
            return $this->scanAllResponse(
                request: $request,
                started: false,
                status: 'no_sites',
                message: 'No hay sitios disponibles para ejecutar este escaneo.',
                progress: null,
            );
        }

        if ($enforceMassInterval) {
            $minIntervalMinutes = max(5, (int) Setting::get('monitoring.mass_scan_min_interval_minutes', 15));

            $lastRun = MonitoringMassScanRun::query()
                ->where('trigger_mode', 'manual')
                ->orderByDesc('started_at')
                ->first();

            if ($lastRun instanceof MonitoringMassScanRun && $lastRun->started_at !== null) {
                $nextAllowedAt = $lastRun->started_at->addMinutes($minIntervalMinutes);

                if ($nextAllowedAt->isFuture()) {
                    return $this->scanAllResponse(
                        request: $request,
                        started: false,
                        status: 'throttled',
                        message: sprintf(
                            'Escaneo masivo bloqueado por protección operativa. Intenta de nuevo en %d minuto(s).',
                            now()->diffInMinutes($nextAllowedAt)
                        ),
                        progress: null,
                    );
                }
            }
        }

        $progress = MassScanProgress::start(
            totalSites: count($siteIds),
            initiatedByUserId: $request->user()?->id,
            triggerMode: $triggerMode,
        );

        $runId = (string) ($progress['run_id'] ?? '');

        if ($runId === '') {
            return $this->scanAllResponse(
                request: $request,
                started: false,
                status: 'failed_to_start',
                message: 'No fue posible iniciar el escaneo solicitado.',
                progress: null,
            );
        }

        if (in_array($triggerMode, ['manual_selected', 'manual_single'], true)) {
            DispatchMassScanRunJob::dispatchSync($runId, $siteIds);
        } else {
            DispatchMassScanRunJob::dispatch($runId, $siteIds);
        }

        return $this->scanAllResponse(
            request: $request,
            started: true,
            status: 'started',
            message: $successMessage,
            progress: MassScanProgress::get($runId),
            runId: $runId,
            redirectUrl: route('monitoring.scans.show', ['runId' => $runId]),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRunProgress(string $runId): ?array
    {
        $fromCache = MassScanProgress::get($runId);

        if (is_array($fromCache)) {
            return $fromCache;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('monitoring_mass_scan_runs')) {
            return null;
        }

        $run = MonitoringMassScanRun::query()->where('run_id', $runId)->first();

        if (! $run instanceof MonitoringMassScanRun) {
            return null;
        }

        $totalSites = max(0, (int) $run->total_sites);
        $totalTasks = max(0, (int) $run->total_tasks);
        $completedTasks = max(0, (int) $run->completed_tasks);
        $failedTasks = max(0, (int) $run->failed_tasks);
        $remainingTasks = max(0, $totalTasks - $completedTasks);
        $progressPct = $totalTasks > 0 ? min(100.0, round(($completedTasks / $totalTasks) * 100, 2)) : 100.0;

        return [
            'run_id' => $run->run_id,
            'status' => $run->status,
            'started_at' => optional($run->started_at)?->toIso8601String() ?? now()->toIso8601String(),
            'last_progress_at' => optional($run->last_progress_at)?->toIso8601String(),
            'completed_at' => optional($run->completed_at)?->toIso8601String(),
            'total_sites' => $totalSites,
            'total_tasks' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'failed_tasks' => $failedTasks,
            'remaining_tasks' => $remainingTasks,
            'progress_pct' => $progressPct,
            'stages' => [
                'uptime' => [
                    'completed' => 0,
                    'failed' => 0,
                    'total' => $totalSites,
                    'remaining' => $totalSites,
                    'progress_pct' => 0,
                ],
                'ssl' => [
                    'completed' => 0,
                    'failed' => 0,
                    'total' => $totalSites,
                    'remaining' => $totalSites,
                    'progress_pct' => 0,
                ],
                'headers' => [
                    'completed' => 0,
                    'failed' => 0,
                    'total' => $totalSites,
                    'remaining' => $totalSites,
                    'progress_pct' => 0,
                ],
                'technology' => [
                    'completed' => 0,
                    'failed' => 0,
                    'total' => $totalSites,
                    'remaining' => $totalSites,
                    'progress_pct' => 0,
                ],
            ],
        ];
    }

    public function searchSuggestionsEndpoint(Request $request): JsonResponse
    {
        $query = trim($request->string('q')->toString());

        if ($query === '') {
            return response()->json(['items' => $this->searchSuggestions()]);
        }

        $driver = Site::query()->getConnection()->getDriverName();
        $operator = $driver === 'pgsql' ? 'ilike' : 'like';

        $items = Site::query()
            ->where(function ($builder) use ($query, $operator): void {
                $needle = '%' . $query . '%';
                $builder->where('name', $operator, $needle)
                    ->orWhere('domain', $operator, $needle);
            })
            ->orderByRaw('LOWER(name)')
            ->limit(30)
            ->get(['name', 'domain'])
            ->flatMap(static function (Site $site): array {
                return array_values(array_filter([
                    trim((string) $site->name),
                    trim((string) $site->domain),
                ], static fn (string $value): bool => $value !== ''));
            })
            ->unique()
            ->values()
            ->all();

        return response()->json(['items' => $items]);
    }

    public function scanProgress(): JsonResponse
    {
        $progress = MassScanProgress::getCurrent();

        if ($progress === null) {
            return response()->json([
                'active' => false,
                'progress' => null,
            ]);
        }

        return response()->json([
            'active' => ($progress['status'] ?? 'running') === 'running',
            'progress' => $progress,
        ]);
    }

    public function diagnosticSites(Request $request, string $bucket): Response
    {
        $allowedBuckets = [
            'operativo' => 'Operativos',
            'respuesta_lenta' => 'Respuesta lenta',
            'responde_con_errores' => 'Responde con errores',
            'inestable' => 'Inestables',
            'no_responde' => 'No responde',
            'sin_actualizar' => 'Sin actualizar',
        ];

        abort_unless(array_key_exists($bucket, $allowedBuckets), 404);

        $search = trim($request->string('search')->toString());
        $perPage = max(10, min(100, $request->integer('per_page', 50)));
        $page = max(1, $request->integer('page', 1));

        $sites = Site::query()
            ->where('is_active', true)
            ->where('is_monitored', true)
            ->with(['latestCheck', 'cmsDetail', 'servers', 'sslCertificate'])
            ->orderByRaw('LOWER(name)')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $filtered = $sites
            ->filter(function (Site $site) use ($bucket, $search): bool {
                $status = strtolower((string) ($site->current_status ?? 'unknown'));
                $diagnosis = $this->resolveSiteDiagnosis($site, $status, $site->latestCheck);

                if (($diagnosis['bucket'] ?? 'sin_actualizar') !== $bucket) {
                    return false;
                }

                if ($search === '') {
                    return true;
                }

                $needle = mb_strtolower($search);

                return str_contains(mb_strtolower((string) $site->name), $needle)
                    || str_contains(mb_strtolower((string) $site->domain), $needle)
                    || str_contains(mb_strtolower((string) $site->url), $needle);
            })
            ->values()
            ->map(function (Site $site): array {
                [$projectStatus, $comments] = $this->extractProjectStatusAndComments((string) ($site->notes ?? ''));
                $sslCertificate = $site->sslCertificate;

                return [
                    'id' => (int) $site->id,
                    'name' => (string) $site->name,
                    'domain' => (string) $site->domain,
                    'url' => (string) $site->url,
                    'cms' => $site->cmsDetail?->cms_type,
                    'server_ip' => $site->servers->first()?->ip_address,
                    'certificate_label' => $sslCertificate !== null
                        ? ($sslCertificate->is_expired ? 'Expirado' : 'Vigente')
                        : 'No',
                    'project_status' => $projectStatus,
                    'comments' => $comments,
                    'current_status' => $site->current_status,
                    'current_status_code' => strtolower((string) ($site->current_status ?? 'unknown')),
                    'display_status_code' => $this->resolveDashboardStatus(
                        $site,
                        strtolower((string) ($site->current_status ?? 'unknown')),
                        $site->latestCheck
                    ),
                    'last_checked_at' => optional($site->last_checked_at)?->toIso8601String(),
                ];
            });

        $paginated = $this->paginateCollection($filtered, $page, $perPage, $request);

        return Inertia::render('Monitoring/DiagnosticSites', [
            'bucket' => $bucket,
            'bucketLabel' => $allowedBuckets[$bucket],
            'search' => $search,
            'sites' => $paginated,
            'updatedAt' => now()->toIso8601String(),
        ]);
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
            $sites->getCollection()->map(function (Site $site): array {
                $latestCheck = $site->latestCheck;
                $status = strtolower((string) ($site->current_status ?? 'unknown'));
                $diagnosis = $this->resolveSiteDiagnosis($site, $status, $latestCheck);
                $displayStatus = $this->resolveDashboardStatus($site, $status, $latestCheck);
                $technology = $this->resolveTechnologyInfo($site);
                $certificate = $site->sslCertificate;
                $certificatePayload = null;

                if ($certificate !== null) {
                    $validUntil = $certificate->valid_until;
                    $normalizedDaysRemaining = $validUntil !== null
                        ? (int) now()->diffInDays($validUntil, false)
                        : $certificate->days_remaining;

                    $certificatePayload = [
                        'valid_until' => optional($validUntil)?->toIso8601String(),
                        'issuer' => $certificate->issuer,
                        'days_remaining' => $normalizedDaysRemaining,
                        'algorithm' => $certificate->algorithm,
                        'is_expired' => $normalizedDaysRemaining !== null ? $normalizedDaysRemaining < 0 : $certificate->is_expired,
                    ];
                }

                return [
                    'id' => (int) $site->id,
                    'name' => $site->name,
                    'domain' => $site->domain,
                    'url' => $site->url,
                    'current_status' => $site->current_status,
                    'current_status_code' => $status,
                    'display_status_code' => $displayStatus,
                    'last_checked_at' => optional($site->last_checked_at)?->toIso8601String(),
                    'diagnostic_bucket' => $diagnosis['bucket'],
                    'diagnostic_label' => $diagnosis['label'],
                    'diagnostic_reason' => $diagnosis['reason'],
                    'technology_name' => $technology['name'],
                    'technology_version' => $technology['version'],
                    'technology_label' => $technology['label'],
                    'ssl_certificate' => $certificatePayload,
                ];
            })
        );

        return $sites;
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
        // En corridas masivas (127 sitios x 4 etapas) los primeros sitios pueden terminar varios
        // minutos antes que los ultimos. Subimos la ventana minima para evitar falsos "sin actualizar".
        $massScanFreshnessGraceMinutes = max(20, (int) Setting::get('monitoring.mass_scan_freshness_grace_minutes', 45));
        $staleMinutes = max($checkInterval * 3, $massScanFreshnessGraceMinutes);
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
     * @param array<int, int> $siteIds
     */
    private function dispatchMassiveScan(array $siteIds, string $runId): void
    {
        try {
            DispatchMassScanRunJob::dispatch($runId, $siteIds);
        } catch (\Throwable) {
            // El disparo masivo es best effort; nunca debe romper el flujo web.
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function extractProjectStatusAndComments(string $notes): array
    {
        $cleanNotes = trim($notes);

        if ($cleanNotes === '') {
            return ['', ''];
        }

        if (! str_contains($cleanNotes, ' · ')) {
            return [$cleanNotes, ''];
        }

        $parts = explode(' · ', $cleanNotes, 2);

        return [trim((string) ($parts[0] ?? '')), trim((string) ($parts[1] ?? ''))];
    }

    /**
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $rows
     */
    private function paginateCollection(\Illuminate\Support\Collection $rows, int $page, int $perPage, Request $request): LengthAwarePaginator
    {
        $total = $rows->count();
        $items = $rows->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    /**
     * @return array<int, string>
     */
    private function searchSuggestions(): array
    {
        $sites = Site::query()
            ->orderByRaw('LOWER(name)')
            ->limit(350)
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

    private function scheduledScansEnabled(): bool
    {
        return (bool) Setting::get('monitoring.scheduled_scans_enabled', true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function massScanHistory(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('monitoring_mass_scan_runs')) {
            return [];
        }

        return MonitoringMassScanRun::query()
            ->with('initiatedBy:id,name,email')
            ->orderByDesc('started_at')
            ->limit(25)
            ->get()
            ->map(static function (MonitoringMassScanRun $run): array {
                return [
                    'run_id' => $run->run_id,
                    'trigger_mode' => $run->trigger_mode,
                    'status' => $run->status,
                    'total_sites' => (int) $run->total_sites,
                    'total_tasks' => (int) $run->total_tasks,
                    'completed_tasks' => (int) $run->completed_tasks,
                    'failed_tasks' => (int) $run->failed_tasks,
                    'started_at' => optional($run->started_at)?->toIso8601String(),
                    'last_progress_at' => optional($run->last_progress_at)?->toIso8601String(),
                    'completed_at' => optional($run->completed_at)?->toIso8601String(),
                    'initiated_by' => $run->initiatedBy?->name ?? 'Sistema',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTechnologyInfo(Site $site): array
    {
        $technology = $site->siteTechnologies
            ->sortByDesc(static fn (SiteTechnology $item): int => (int) ($item->is_primary ? 1000 : 0) + ((int) $item->confidence_pct))
            ->first();

        if ($technology instanceof SiteTechnology && $technology->technology !== null) {
            $detectedTechnology = DetectedTechnology::fromArray([
                'name' => $technology->technology->name,
                'version' => $technology->version,
                'category' => $technology->technology->category ?? 'other',
                'confidence' => $technology->confidence_pct,
                'vendor' => $technology->technology->vendor,
                'slug' => $technology->technology->slug,
            ])->toFrontendArray();

            if (
                mb_strtolower((string) ($technology->technology->slug ?? '')) === 'drupal'
                && trim((string) ($detectedTechnology['version'] ?? '')) === ''
            ) {
                $detectedTechnology = DetectedTechnology::fromArray([
                    'name' => 'Drupal',
                    'version' => 'Sin versión detectable',
                    'category' => 'cms',
                    'confidence' => $technology->confidence_pct,
                    'vendor' => $technology->technology->vendor,
                    'slug' => 'drupal',
                    'evidence' => ['site-technology-null-version'],
                ])->toFrontendArray();
            }

            if ($detectedTechnology['name'] !== '') {
                return [
                    'name' => $detectedTechnology['name'],
                    'version' => $detectedTechnology['version'],
                    'label' => $detectedTechnology['display_name'],
                    'category' => $detectedTechnology['category'],
                    'category_label' => $detectedTechnology['category_label'],
                    'confidence' => $detectedTechnology['confidence'],
                    'badge_state' => $detectedTechnology['badge_state'],
                ];
            }
        }

        $cmsType = trim((string) ($site->cmsDetail?->cms_type ?? ''));
        $cmsVersion = trim((string) ($site->cmsDetail?->cms_version ?? ''));
        $cmsLabel = trim((string) ($site->cmsDetail?->theme_name ?? ''));

        if ($cmsType !== '') {
            $normalized = match (strtolower($cmsType)) {
                'drupal' => 'Drupal',
                'laravel' => 'Laravel',
                'wordpress' => 'WordPress',
                'wix' => 'Wix',
                default => ucfirst($cmsType),
            };

            if ($normalized === 'Drupal' && $cmsVersion !== '' && preg_match('/^drupal\b/i', $cmsVersion) === 1) {
                $technology = DetectedTechnology::fromArray([
                    'name' => 'Drupal',
                    'version' => null,
                    'category' => 'cms',
                    'confidence' => 100,
                    'slug' => 'drupal',
                    'evidence' => [$cmsVersion],
                ])->toFrontendArray();

                return [
                    'name' => $technology['name'],
                    'version' => null,
                    'label' => $cmsVersion,
                    'category' => $technology['category'],
                    'category_label' => $technology['category_label'],
                    'confidence' => $technology['confidence'],
                    'badge_state' => $technology['badge_state'],
                ];
            }

            if ($normalized === 'Drupal' && $cmsVersion === '' && $cmsLabel !== '' && preg_match('/^drupal\b/i', $cmsLabel) === 1) {
                $technology = DetectedTechnology::fromArray([
                    'name' => 'Drupal',
                    'version' => null,
                    'category' => 'cms',
                    'confidence' => 100,
                    'slug' => 'drupal',
                    'evidence' => [$cmsLabel],
                ])->toFrontendArray();

                return [
                    'name' => $technology['name'],
                    'version' => null,
                    'label' => $cmsLabel,
                    'category' => $technology['category'],
                    'category_label' => $technology['category_label'],
                    'confidence' => $technology['confidence'],
                    'badge_state' => $technology['badge_state'],
                ];
            }

            if ($normalized === 'Drupal' && $cmsVersion === '') {
                $technology = DetectedTechnology::fromArray([
                    'name' => 'Drupal',
                    'version' => 'Sin versión detectable',
                    'category' => 'cms',
                    'confidence' => 78,
                    'slug' => 'drupal',
                    'evidence' => ['cms-detail-fallback'],
                ])->toFrontendArray();

                return [
                    'name' => $technology['name'],
                    'version' => $technology['version'],
                    'label' => $technology['display_name'],
                    'category' => $technology['category'],
                    'category_label' => $technology['category_label'],
                    'confidence' => $technology['confidence'],
                    'badge_state' => $technology['badge_state'],
                ];
            }

            $technology = DetectedTechnology::fromArray([
                'name' => $normalized,
                'version' => $cmsVersion !== '' ? $cmsVersion : null,
                'category' => 'cms',
                'confidence' => 100,
                'slug' => strtolower($normalized),
            ])->toFrontendArray();

            return [
                'name' => $technology['name'],
                'version' => $technology['version'],
                'label' => $technology['display_name'],
                'category' => $technology['category'],
                'category_label' => $technology['category_label'],
                'confidence' => $technology['confidence'],
                'badge_state' => $technology['badge_state'],
            ];
        }

        return [
            'name' => 'No identificada',
            'version' => null,
            'label' => 'No identificada',
            'category' => 'other',
            'category_label' => 'Otro',
            'confidence' => 0,
            'badge_state' => 'danger',
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function obsoleteTechnologies(int $limit): \Illuminate\Support\Collection
    {
        $rows = SiteTechnology::query()
            ->join('technologies', 'technologies.id', '=', 'site_technologies.technology_id')
            ->join('sites', 'sites.id', '=', 'site_technologies.site_id')
            ->select([
                'site_technologies.id',
                'site_technologies.site_id',
                'site_technologies.version',
                'site_technologies.confidence_pct',
                'site_technologies.detected_at',
                'technologies.name as technology_name',
                'technologies.category as technology_category',
                'technologies.vendor as technology_vendor',
                'technologies.slug as technology_slug',
                'sites.name as site_name',
                'sites.domain as site_domain',
            ])
            ->orderByDesc('site_technologies.detected_at')
            ->limit(max(10, $limit * 4))
            ->get();

        return $rows
            ->map(static function (object $row): array {
                $detected = DetectedTechnology::fromArray([
                    'name' => $row->technology_name,
                    'version' => $row->version,
                    'category' => $row->technology_category,
                    'confidence' => $row->confidence_pct,
                    'vendor' => $row->technology_vendor,
                    'slug' => $row->technology_slug,
                ])->toFrontendArray();

                return [
                    'site' => [
                        'name' => (string) $row->site_name,
                        'domain' => (string) $row->site_domain,
                    ],
                    'technology' => $detected,
                    'detected_at' => optional($row->detected_at)?->toIso8601String() ?? null,
                ];
            })
            ->filter(static fn (array $item): bool => (bool) ($item['technology']['is_obsolete'] ?? false))
            ->take(max(1, $limit))
            ->values();
    }

    private function uptimePercentage(int $days): float
    {
        $windowStart = now()->subDays(max(1, $days));

        $summary = SiteCheck::query()
            ->where('checked_at', '>=', $windowStart)
            ->selectRaw('COUNT(*) as total_checks')
            ->selectRaw("SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_checks")
            ->first();

        $total = (int) ($summary?->total_checks ?? 0);

        if ($total === 0) {
            return 0.0;
        }

        $up = (int) ($summary?->up_checks ?? 0);

        return round(($up / $total) * 100, 2);
    }

    private function averageSiteResponseTime(int $days): ?float
    {
        $windowStart = now()->subDays(max(1, $days));

        $average = SiteCheck::query()
            ->where('checked_at', '>=', $windowStart)
            ->whereNotNull('response_time_ms')
            ->avg('response_time_ms');

        return $average !== null ? round((float) $average, 2) : null;
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
        return config('queue.default') === 'sync';
    }

}
