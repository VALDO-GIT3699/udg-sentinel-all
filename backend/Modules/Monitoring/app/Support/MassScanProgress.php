<?php

declare(strict_types=1);

namespace Modules\Monitoring\Support;

use App\Models\MonitoringMassScanRun;
use App\Models\Site;
use App\Models\SiteInspectionProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Monitoring\Http\Controllers\DashboardController;

final class MassScanProgress
{
    private const CURRENT_RUN_KEY = 'monitoring:mass-scan:current-run';

    private const CACHE_TTL_SECONDS = 7200;

    private const STALE_RUNNING_MINUTES = 45;

    /**
     * @param  array<int, int>  $siteIds
     * @return array<string, mixed>
     */
    public static function start(int $totalSites, ?int $initiatedByUserId = null, string $triggerMode = 'manual', array $siteIds = []): array
    {
        $runId = (string) Str::uuid();
        $startedAt = now()->toIso8601String();
        $safeTotalSites = max(0, $totalSites);
        $tasksPerSite = count(self::stages());

        self::finalizeStaleRunIfNeeded();

        Cache::put(self::CURRENT_RUN_KEY, $runId, self::CACHE_TTL_SECONDS);

        // Se guarda el set exacto de sitios de esta corrida para poder reconciliar al
        // final SOLO esos sitios, nunca todos los que por cualquier otra razon esten en
        // 'unknown' (p. ej. un sitio recien creado o una corrida distinta en paralelo).
        Cache::put(
            self::siteIdsKey($runId),
            array_values(array_unique(array_map('intval', $siteIds))),
            self::CACHE_TTL_SECONDS,
        );

        Cache::put(self::metaKey($runId), [
            'run_id' => $runId,
            'status' => 'running',
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
            'completed_at' => null,
            'total_sites' => $safeTotalSites,
            'total_tasks' => $safeTotalSites * $tasksPerSite,
        ], self::CACHE_TTL_SECONDS);

        Cache::put(self::doneTasksKey($runId), 0, self::CACHE_TTL_SECONDS);
        Cache::put(self::failedTasksKey($runId), 0, self::CACHE_TTL_SECONDS);

        foreach (self::stages() as $stage) {
            Cache::put(self::stageDoneKey($runId, $stage), 0, self::CACHE_TTL_SECONDS);
            Cache::put(self::stageFailedKey($runId, $stage), 0, self::CACHE_TTL_SECONDS);
        }

        if (self::canPersistHistory()) {
            MonitoringMassScanRun::query()->create([
                'run_id' => $runId,
                'initiated_by_user_id' => $initiatedByUserId,
                'trigger_mode' => $triggerMode,
                'status' => 'running',
                'total_sites' => $safeTotalSites,
                'total_tasks' => $safeTotalSites * $tasksPerSite,
                'completed_tasks' => 0,
                'failed_tasks' => 0,
                'started_at' => now(),
                'last_progress_at' => now(),
            ]);
        }

        return self::get($runId) ?? [
            'run_id' => $runId,
            'status' => 'running',
            'started_at' => $startedAt,
            'completed_at' => null,
            'total_sites' => $safeTotalSites,
            'total_tasks' => $safeTotalSites * $tasksPerSite,
            'completed_tasks' => 0,
            'failed_tasks' => 0,
            'remaining_tasks' => $safeTotalSites * $tasksPerSite,
            'progress_pct' => $safeTotalSites > 0 ? 0.0 : 100.0,
            'stages' => self::emptyStages($safeTotalSites),
        ];
    }

    public static function recordFailure(string $runId, string $stage, int $siteId, string $errorMessage): void
    {
        if (! in_array($stage, self::stages(), true)) {
            return;
        }

        $meta = self::getMeta($runId);

        if ($meta === null || ($meta['status'] ?? 'running') !== 'running') {
            return;
        }

        $markerKey = self::failureMarkerKey($runId, $stage, $siteId);

        if (! Cache::add($markerKey, 1, self::CACHE_TTL_SECONDS)) {
            return;
        }

        Cache::increment(self::failedTasksKey($runId));
        Cache::increment(self::stageFailedKey($runId, $stage));

        $thisMoment = now()->toIso8601String();
        $meta['last_progress_at'] = $thisMoment;
        Cache::put(self::metaKey($runId), $meta, self::CACHE_TTL_SECONDS);

        if (! self::canPersistHistory()) {
            return;
        }

        $run = MonitoringMassScanRun::query()->where('run_id', $runId)->first();

        if (! $run instanceof MonitoringMassScanRun) {
            return;
        }

        $run->forceFill([
            'failed_tasks' => max(0, (int) Cache::get(self::failedTasksKey($runId), 0)),
            'last_progress_at' => now(),
            'last_error' => mb_substr($errorMessage, 0, 1000),
        ])->save();
    }

    public static function completeTask(string $runId, string $stage, int $siteId): void
    {
        if (! in_array($stage, self::stages(), true)) {
            return;
        }

        $meta = self::getMeta($runId);

        if ($meta === null || ($meta['status'] ?? 'running') !== 'running') {
            return;
        }

        $markerKey = self::markerKey($runId, $stage, $siteId);

        if (! Cache::add($markerKey, 1, self::CACHE_TTL_SECONDS)) {
            return;
        }

        Cache::increment(self::doneTasksKey($runId));
        Cache::increment(self::stageDoneKey($runId, $stage));

        $thisMoment = now()->toIso8601String();
        $meta['last_progress_at'] = $thisMoment;
        Cache::put(self::metaKey($runId), $meta, self::CACHE_TTL_SECONDS);

        $updatedMeta = self::getMeta($runId);

        if ($updatedMeta === null) {
            return;
        }

        $doneTasks = max(0, (int) Cache::get(self::doneTasksKey($runId), 0));
        $totalTasks = max(0, (int) ($updatedMeta['total_tasks'] ?? 0));
        $failedTasks = max(0, (int) Cache::get(self::failedTasksKey($runId), 0));

        if ($totalTasks > 0 && $doneTasks >= $totalTasks) {
            $pendingSites = self::reconcilePendingSites($runId);
            $updatedMeta['status'] = ($failedTasks > 0 || $pendingSites > 0) ? 'completed_with_errors' : 'completed_ok';
            $updatedMeta['completed_at'] = now()->toIso8601String();
            $updatedMeta['last_progress_at'] = now()->toIso8601String();

            if ($pendingSites > 0) {
                $updatedMeta['last_error'] = sprintf('Mass scan finished with %d assets still marked as SIN_ACTUALIZAR.', $pendingSites);
            }

            Cache::put(self::metaKey($runId), $updatedMeta, self::CACHE_TTL_SECONDS);

            if (self::canPersistHistory()) {
                $payload = [
                    'status' => $updatedMeta['status'],
                    'completed_tasks' => $doneTasks,
                    'failed_tasks' => $failedTasks,
                    'completed_at' => now(),
                    'last_progress_at' => now(),
                ];

                if ($pendingSites > 0) {
                    $payload['last_error'] = (string) $updatedMeta['last_error'];
                }

                MonitoringMassScanRun::query()
                    ->where('run_id', $runId)
                    ->update($payload);
            }

            Cache::forget(self::CURRENT_RUN_KEY);

            // Sin esto, el dashboard sigue mostrando los conteos/diagnosticos
            // cacheados de ANTES del escaneo hasta que expire su TTL (45s) —
            // el usuario ve el aviso de "escaneo completado" pero las cifras
            // en pantalla no reflejan el resultado real todavia.
            DashboardController::forgetDashboardCache();

            return;
        }

        if (self::canPersistHistory()) {
            MonitoringMassScanRun::query()
                ->where('run_id', $runId)
                ->update([
                    'completed_tasks' => $doneTasks,
                    'failed_tasks' => $failedTasks,
                    'last_progress_at' => now(),
                ]);
        }
    }

    public static function abortRun(string $runId, string $errorMessage): void
    {
        $meta = self::getMeta($runId);

        if ($meta === null) {
            return;
        }

        $meta['status'] = 'completed_with_errors';
        $meta['completed_at'] = now()->toIso8601String();
        $meta['last_progress_at'] = now()->toIso8601String();

        $pendingSites = self::reconcilePendingSites($runId);

        if ($pendingSites > 0) {
            $meta['last_error'] = sprintf('Mass scan aborted with %d assets still marked as SIN_ACTUALIZAR.', $pendingSites);
        }

        Cache::put(self::metaKey($runId), $meta, self::CACHE_TTL_SECONDS);
        Cache::forget(self::CURRENT_RUN_KEY);
        DashboardController::forgetDashboardCache();

        if (! self::canPersistHistory()) {
            return;
        }

        $failedTasks = max(1, (int) Cache::get(self::failedTasksKey($runId), 0));

        $payload = [
            'status' => 'completed_with_errors',
            'failed_tasks' => $failedTasks,
            'last_error' => mb_substr($errorMessage, 0, 1000),
            'completed_at' => now(),
            'last_progress_at' => now(),
        ];

        if ($pendingSites > 0) {
            $payload['last_error'] = sprintf(
                '%s | Mass scan aborted with %d assets still marked as SIN_ACTUALIZAR.',
                mb_substr($errorMessage, 0, 800),
                $pendingSites,
            );
        }

        MonitoringMassScanRun::query()
            ->where('run_id', $runId)
            ->update($payload);
    }

    /**
     * Cancelacion pedida por un usuario (a diferencia de abortRun, que es para
     * fallos del propio proceso). A proposito NO usa reconcilePendingSites: ese
     * metodo marca los sitios pendientes como 'down' y borra su tecnologia
     * detectada, que es correcto para un fallo real pero no para una cancelacion
     * manual. Aqui los sitios que no alcanzaron a re-inspeccionarse conservan su
     * ultimo estado y tecnologia conocidos, y solo quedan marcados como
     * interrumpidos por esta corrida especifica.
     *
     * @return array<string, mixed>|null null si no hay nada que cancelar (el run
     *                                   ya no existe o ya no esta 'running')
     */
    public static function cancel(string $runId): ?array
    {
        $meta = self::getMeta($runId);

        if ($meta === null || ($meta['status'] ?? 'running') !== 'running') {
            return null;
        }

        $interruptedSites = self::markPendingSitesInterrupted($runId);

        $meta['status'] = 'cancelled';
        $meta['completed_at'] = now()->toIso8601String();
        $meta['last_progress_at'] = now()->toIso8601String();
        Cache::put(self::metaKey($runId), $meta, self::CACHE_TTL_SECONDS);
        Cache::forget(self::CURRENT_RUN_KEY);
        DashboardController::forgetDashboardCache();

        if (self::canPersistHistory()) {
            MonitoringMassScanRun::query()
                ->where('run_id', $runId)
                ->update([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                    'last_progress_at' => now(),
                    'last_error' => $interruptedSites > 0
                        ? sprintf('Escaneo cancelado manualmente con %d sitio(s) sin re-inspeccionar.', $interruptedSites)
                        : null,
                ]);
        }

        return self::get($runId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getCurrent(): ?array
    {
        self::finalizeStaleRunIfNeeded();

        $runId = Cache::get(self::CURRENT_RUN_KEY);

        if (! is_string($runId) || $runId === '') {
            return null;
        }

        $progress = self::get($runId);

        if ($progress === null) {
            Cache::forget(self::CURRENT_RUN_KEY);
        }

        return $progress;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $runId): ?array
    {
        $meta = self::getMeta($runId);

        if ($meta === null) {
            self::finalizeStalePersistedRunIfNeeded($runId);

            return null;
        }

        $totalSites = max(0, (int) ($meta['total_sites'] ?? 0));
        $totalTasks = max(0, (int) ($meta['total_tasks'] ?? 0));
        $completedTasks = max(0, (int) Cache::get(self::doneTasksKey($runId), 0));
        $failedTasks = max(0, (int) Cache::get(self::failedTasksKey($runId), 0));
        $remainingTasks = max(0, $totalTasks - $completedTasks);
        $progressPct = $totalTasks > 0
            ? min(100, round(($completedTasks / $totalTasks) * 100, 2))
            : 100.0;

        $stages = [];

        foreach (self::stages() as $stage) {
            $stageCompleted = max(0, (int) Cache::get(self::stageDoneKey($runId, $stage), 0));
            $stageRemaining = max(0, $totalSites - $stageCompleted);
            $stages[$stage] = [
                'completed' => $stageCompleted,
                'failed' => max(0, (int) Cache::get(self::stageFailedKey($runId, $stage), 0)),
                'total' => $totalSites,
                'remaining' => $stageRemaining,
                'progress_pct' => $totalSites > 0
                    ? min(100, round(($stageCompleted / $totalSites) * 100, 2))
                    : 100.0,
            ];
        }

        return [
            'run_id' => (string) ($meta['run_id'] ?? $runId),
            'status' => (string) ($meta['status'] ?? 'running'),
            'started_at' => (string) ($meta['started_at'] ?? now()->toIso8601String()),
            'last_progress_at' => $meta['last_progress_at'] ?? null,
            'completed_at' => $meta['completed_at'] ?? null,
            'total_sites' => $totalSites,
            'total_tasks' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'failed_tasks' => $failedTasks,
            'remaining_tasks' => $remainingTasks,
            'progress_pct' => $progressPct,
            'stages' => $stages,
        ];
    }

    /**
     * Marca como interrumpidos los sitios de esta corrida que aun no habian
     * terminado la etapa 'inspection' al momento de cancelar. Si el sitio ya
     * tenia un perfil de inspeccion previo (de una corrida anterior), se deja
     * intacto -CMS, runtime, riesgo, etc.- y solo se agrega la marca de tiempo
     * de interrupcion.
     */
    private static function markPendingSitesInterrupted(string $runId): int
    {
        $runSiteIds = Cache::get(self::siteIdsKey($runId));

        if (! is_array($runSiteIds) || $runSiteIds === []) {
            return 0;
        }

        $pendingSiteIds = array_values(array_filter(
            array_map('intval', $runSiteIds),
            static fn (int $siteId): bool => ! Cache::has(self::markerKey($runId, 'inspection', $siteId)),
        ));

        if ($pendingSiteIds === []) {
            return 0;
        }

        $now = now();

        $existingProfiles = SiteInspectionProfile::query()
            ->whereIn('site_id', $pendingSiteIds)
            ->get()
            ->keyBy('site_id');

        foreach ($pendingSiteIds as $siteId) {
            $profile = $existingProfiles->get($siteId);

            if ($profile instanceof SiteInspectionProfile) {
                $profile->forceFill(['scan_interrupted_at' => $now])->save();

                continue;
            }

            // Sitio nunca antes inspeccionado: no hay tecnologia previa que
            // preservar, solo queda constancia minima de la interrupcion.
            SiteInspectionProfile::query()->create([
                'site_id' => $siteId,
                'scan_run_id' => $runId,
                'analysis_version' => 'vertical-inspector-v1',
                'scan_interrupted_at' => $now,
            ]);
        }

        return count($pendingSiteIds);
    }

    /**
     * @return array<int, string>
     */
    private static function stages(): array
    {
        return ['inspection'];
    }

    /**
     * @return array<string, array<string, int|float>>
     */
    private static function emptyStages(int $totalSites): array
    {
        $stages = [];

        foreach (self::stages() as $stage) {
            $stages[$stage] = [
                'completed' => 0,
                'failed' => 0,
                'total' => $totalSites,
                'remaining' => $totalSites,
                'progress_pct' => $totalSites > 0 ? 0.0 : 100.0,
            ];
        }

        return $stages;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getMeta(string $runId): ?array
    {
        $meta = Cache::get(self::metaKey($runId));

        return is_array($meta) ? $meta : null;
    }

    private static function metaKey(string $runId): string
    {
        return 'monitoring:mass-scan:'.$runId.':meta';
    }

    private static function doneTasksKey(string $runId): string
    {
        return 'monitoring:mass-scan:'.$runId.':done-tasks';
    }

    private static function siteIdsKey(string $runId): string
    {
        return 'monitoring:mass-scan:'.$runId.':site-ids';
    }

    private static function failedTasksKey(string $runId): string
    {
        return 'monitoring:mass-scan:'.$runId.':failed-tasks';
    }

    private static function stageDoneKey(string $runId, string $stage): string
    {
        return 'monitoring:mass-scan:'.$runId.':stage:'.$stage.':done';
    }

    private static function stageFailedKey(string $runId, string $stage): string
    {
        return 'monitoring:mass-scan:'.$runId.':stage:'.$stage.':failed';
    }

    private static function markerKey(string $runId, string $stage, int $siteId): string
    {
        return 'monitoring:mass-scan:'.$runId.':marker:'.$stage.':'.$siteId;
    }

    private static function failureMarkerKey(string $runId, string $stage, int $siteId): string
    {
        return 'monitoring:mass-scan:'.$runId.':failure-marker:'.$stage.':'.$siteId;
    }

    private static function canPersistHistory(): bool
    {
        try {
            return Schema::hasTable('monitoring_mass_scan_runs');
        } catch (\Throwable) {
            return false;
        }
    }

    private static function finalizeStaleRunIfNeeded(): void
    {
        $runId = Cache::get(self::CURRENT_RUN_KEY);

        if (! is_string($runId) || $runId === '') {
            return;
        }

        $meta = self::getMeta($runId);

        if ($meta === null || ($meta['status'] ?? 'running') !== 'running') {
            return;
        }

        $lastProgressAt = $meta['last_progress_at'] ?? $meta['started_at'] ?? null;

        if (! is_string($lastProgressAt) || $lastProgressAt === '') {
            return;
        }

        $lastProgress = CarbonImmutable::parse($lastProgressAt);

        if ($lastProgress->gt(now()->subMinutes(self::STALE_RUNNING_MINUTES))) {
            return;
        }

        $meta['status'] = 'incomplete';
        $meta['completed_at'] = now()->toIso8601String();
        Cache::put(self::metaKey($runId), $meta, self::CACHE_TTL_SECONDS);
        Cache::forget(self::CURRENT_RUN_KEY);

        if (! self::canPersistHistory()) {
            return;
        }

        MonitoringMassScanRun::query()
            ->where('run_id', $runId)
            ->where('status', 'running')
            ->update([
                'status' => 'incomplete',
                'completed_at' => now(),
                'last_progress_at' => now(),
            ]);
    }

    private static function finalizeStalePersistedRunIfNeeded(string $runId): void
    {
        if (! self::canPersistHistory()) {
            return;
        }

        $run = MonitoringMassScanRun::query()
            ->where('run_id', $runId)
            ->where('status', 'running')
            ->first();

        if (! $run instanceof MonitoringMassScanRun) {
            return;
        }

        $lastProgressAt = $run->last_progress_at ?? $run->started_at;

        if (! $lastProgressAt instanceof CarbonImmutable && $lastProgressAt !== null) {
            $lastProgressAt = CarbonImmutable::parse((string) $lastProgressAt);
        }

        if (! $lastProgressAt instanceof CarbonImmutable) {
            return;
        }

        if ($lastProgressAt->gt(now()->subMinutes(self::STALE_RUNNING_MINUTES))) {
            return;
        }

        $run->forceFill([
            'status' => 'incomplete',
            'completed_at' => now(),
            'last_progress_at' => now(),
        ])->save();
    }

    private static function reconcilePendingSites(string $runId): int
    {
        // Solo se reconcilian sitios que pertenecen a ESTA corrida. Sin esa lista no hay
        // forma segura de saber cuales 'unknown' son de este run, asi que no se toca nada
        // en vez de arriesgar marcar como 'down' sitios de otra corrida o recien creados.
        $runSiteIds = Cache::get(self::siteIdsKey($runId));

        if (! is_array($runSiteIds) || $runSiteIds === []) {
            return 0;
        }

        $siteIds = Site::query()
            ->whereIn('id', $runSiteIds)
            ->where('current_status', 'unknown')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $pendingCount = count($siteIds);

        if ($pendingCount === 0) {
            return 0;
        }

        $diagnostic = 'La inspección no finalizó correctamente durante el escaneo masivo.';

        Log::error(sprintf('Mass scan finished with %d assets still marked as SIN_ACTUALIZAR.', $pendingCount), [
            'run_id' => $runId,
            'site_ids' => $siteIds,
        ]);

        Site::query()
            ->whereIn('id', $siteIds)
            ->update([
                'current_status' => 'down',
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);

        $profiles = SiteInspectionProfile::query()
            ->whereIn('site_id', $siteIds)
            ->get()
            ->keyBy('site_id');

        foreach ($siteIds as $siteId) {
            $profile = $profiles->get($siteId);

            if ($profile instanceof SiteInspectionProfile) {
                $analysisErrors = self::normalizedPendingAnalysisErrors($profile, $diagnostic);

                $profile->forceFill([
                    'scan_run_id' => $runId,
                    'risk_score' => max(95, (int) ($profile->risk_score ?? 95)),
                    'risk_level' => 'Crítico',
                    'essential_checks_complete' => false,
                    'analysis_errors' => $analysisErrors,
                    'inspected_at' => $profile->inspected_at ?? now(),
                    'scan_interrupted_at' => null,
                ])->save();

                continue;
            }

            SiteInspectionProfile::query()->create([
                'site_id' => $siteId,
                'scan_run_id' => $runId,
                'analysis_version' => 'vertical-inspector-v1',
                'dns_status' => 'error',
                'http_status' => null,
                'https_status' => null,
                'ssl_status' => 'error',
                'security_headers_status' => 'error',
                'body_status' => 'error',
                'fingerprint_status' => 'error',
                'cms_name' => 'No determinado',
                'cms_version' => 'No determinado',
                'cms_confidence' => 'low',
                'server_signature' => 'No determinado',
                'runtime_name' => 'No determinado',
                'runtime_version' => 'No determinado',
                'risk_score' => 95,
                'risk_level' => 'Crítico',
                'essential_checks_complete' => false,
                'analysis_errors' => [$diagnostic],
                'inspected_at' => now(),
            ]);
        }

        return $pendingCount;
    }

    /**
     * @return array<int, string>
     */
    private static function normalizedPendingAnalysisErrors(SiteInspectionProfile $profile, string $fallbackDiagnostic): array
    {
        $errors = [];
        $dnsStatus = (string) ($profile->dns_status ?? 'error');
        $dnsError = trim((string) ($profile->dns_error ?? ''));
        $sslError = trim((string) ($profile->ssl_error ?? ''));
        $bodyError = trim((string) ($profile->body_error ?? ''));
        $rawErrors = is_array($profile->analysis_errors) ? $profile->analysis_errors : [];
        $rawText = mb_strtolower(implode(' | ', array_map(static fn (mixed $value): string => trim((string) $value), $rawErrors)));

        if ($dnsStatus === 'no_records' || str_contains(mb_strtolower($dnsError), 'no dns') || str_contains($rawText, 'name does not resolve')) {
            $errors[] = 'No existen registros DNS para el dominio.';
        }

        if ($errors === []) {
            $combinedTransportText = mb_strtolower(trim($sslError.' | '.$bodyError.' | '.$rawText));

            if (str_contains($combinedTransportText, 'timeout') || str_contains($combinedTransportText, 'timed out')) {
                $errors[] = 'La inspección agotó el tiempo de espera al establecer conexión HTTP.';
            } elseif (str_contains($combinedTransportText, 'refused')) {
                $errors[] = 'La conexión HTTP fue rechazada por el servidor.';
            } elseif (str_contains($combinedTransportText, 'ssl') || str_contains($combinedTransportText, 'handshake')) {
                $errors[] = 'No fue posible completar el handshake SSL del sitio.';
            } elseif (str_contains($combinedTransportText, 'http:no-connection')) {
                $errors[] = 'No fue posible establecer conexión HTTP.';
            }
        }

        foreach ($rawErrors as $rawError) {
            $message = trim((string) $rawError);

            if ($message === '' || in_array($message, $errors, true)) {
                continue;
            }

            if (str_starts_with(mb_strtolower($message), 'dns:') || str_starts_with(mb_strtolower($message), 'http:') || str_starts_with(mb_strtolower($message), 'ssl:')) {
                continue;
            }

            $errors[] = $message;
        }

        if ($errors === []) {
            $errors[] = $fallbackDiagnostic;
        }

        return array_values(array_unique($errors));
    }
}
