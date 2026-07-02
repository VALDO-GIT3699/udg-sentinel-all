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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Modules\Monitoring\Jobs\RunHeadCheckJob;
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
            'refreshIntervalMs' => self::DASHBOARD_REFRESH_INTERVAL_MS,
            'updatedAt' => now()->toIso8601String(),
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
            ->where('is_active', true)
            ->where('is_monitored', true)
            ->first();

        abort_if(! $site instanceof Site, 404);

        // Reescaneo rapido: disponibilidad del sitio, sin bloquear la interfaz.
        $site->forceFill(['last_checked_at' => null])->save();
        $this->dispatchMonitoringJob(RunHeadCheckJob::class, (int) $site->id);

        return back()->with('success', 'Se programo la actualizacion del sitio seleccionado.');
    }

    public function scanAll(Request $request)
    {
        if (! Cache::add('monitoring:manual-scan-all:cooldown', now()->timestamp, 45)) {
            return back()->with('warning', 'Ya hay una actualizacion masiva en curso. Espera unos segundos e intenta de nuevo.');
        }

        $limit = max(
            1,
            (int) Site::query()->where('is_active', true)->where('is_monitored', true)->count()
        );

        $batch = max(10, min(60, (int) config('monitoring.scan.default_batch_size', 40)));

        // Estrategia durable: solo marca los sitios para que el scheduler los procese progresivamente.
        Site::query()
            ->where('is_active', true)
            ->where('is_monitored', true)
            ->update(['last_checked_at' => null]);

        app()->terminating(function () use ($limit, $batch): void {
            $this->dispatchMassiveScan(min($limit, $batch), $batch);
        });

        return back()->with('success', 'Se programo una actualizacion masiva progresiva. El tablero se actualizara por lotes sin saturar el sistema.');
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
            ->where('is_active', true)
            ->where('is_monitored', true)
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
            ->where('is_active', true)
            ->where('is_monitored', true)
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
            ->where('is_active', true)
            ->where('is_monitored', true)
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

}
