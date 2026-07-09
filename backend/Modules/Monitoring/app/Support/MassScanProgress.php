<?php

declare(strict_types=1);

namespace Modules\Monitoring\Support;

use App\Models\MonitoringMassScanRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MassScanProgress
{
    private const CURRENT_RUN_KEY = 'monitoring:mass-scan:current-run';

    private const CACHE_TTL_SECONDS = 7200;

    private const STALE_RUNNING_MINUTES = 45;

    /**
     * @return array<string, mixed>
     */
    public static function start(int $totalSites, ?int $initiatedByUserId = null, string $triggerMode = 'manual'): array
    {
        $runId = (string) Str::uuid();
        $startedAt = now()->toIso8601String();
        $safeTotalSites = max(0, $totalSites);

        self::finalizeStaleRunIfNeeded();

        Cache::put(self::CURRENT_RUN_KEY, $runId, self::CACHE_TTL_SECONDS);

        Cache::put(self::metaKey($runId), [
            'run_id' => $runId,
            'status' => 'running',
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
            'completed_at' => null,
            'total_sites' => $safeTotalSites,
            'total_tasks' => $safeTotalSites * 4,
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
                'total_tasks' => $safeTotalSites * 4,
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
            'total_tasks' => $safeTotalSites * 4,
            'completed_tasks' => 0,
            'failed_tasks' => 0,
            'remaining_tasks' => $safeTotalSites * 4,
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
            $updatedMeta['status'] = $failedTasks > 0 ? 'completed_with_errors' : 'completed_ok';
            $updatedMeta['completed_at'] = now()->toIso8601String();
            $updatedMeta['last_progress_at'] = now()->toIso8601String();

            Cache::put(self::metaKey($runId), $updatedMeta, self::CACHE_TTL_SECONDS);

            if (self::canPersistHistory()) {
                MonitoringMassScanRun::query()
                    ->where('run_id', $runId)
                    ->update([
                        'status' => $updatedMeta['status'],
                        'completed_tasks' => $doneTasks,
                        'failed_tasks' => $failedTasks,
                        'completed_at' => now(),
                        'last_progress_at' => now(),
                    ]);
            }

            Cache::forget(self::CURRENT_RUN_KEY);

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
     * @return array<int, string>
     */
    private static function stages(): array
    {
        return ['uptime', 'ssl', 'headers', 'technology'];
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
        return 'monitoring:mass-scan:' . $runId . ':meta';
    }

    private static function doneTasksKey(string $runId): string
    {
        return 'monitoring:mass-scan:' . $runId . ':done-tasks';
    }

    private static function failedTasksKey(string $runId): string
    {
        return 'monitoring:mass-scan:' . $runId . ':failed-tasks';
    }

    private static function stageDoneKey(string $runId, string $stage): string
    {
        return 'monitoring:mass-scan:' . $runId . ':stage:' . $stage . ':done';
    }

    private static function stageFailedKey(string $runId, string $stage): string
    {
        return 'monitoring:mass-scan:' . $runId . ':stage:' . $stage . ':failed';
    }

    private static function markerKey(string $runId, string $stage, int $siteId): string
    {
        return 'monitoring:mass-scan:' . $runId . ':marker:' . $stage . ':' . $siteId;
    }

    private static function failureMarkerKey(string $runId, string $stage, int $siteId): string
    {
        return 'monitoring:mass-scan:' . $runId . ':failure-marker:' . $stage . ':' . $siteId;
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
}
