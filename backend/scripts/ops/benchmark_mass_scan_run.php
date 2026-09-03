<?php

declare(strict_types=1);

use App\Models\MonitoringMassScanRun;
use App\Models\SiteInspectionProfile;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$runId = isset($argv[1]) ? trim((string) $argv[1]) : '';

if ($runId === '') {
    fwrite(STDERR, "Uso: php scripts/ops/benchmark_mass_scan_run.php <run_id>\n");
    exit(1);
}

$startedAt = microtime(true);
$deadlineSeconds = max(900, (int) env('SENTINEL_BENCHMARK_WAIT_TIMEOUT_SECONDS', 1800));

while (true) {
    $run = MonitoringMassScanRun::query()->where('run_id', $runId)->first();

    if (! $run instanceof MonitoringMassScanRun) {
        fwrite(STDERR, "No existe el run_id proporcionado.\n");
        exit(1);
    }

    $status = (string) $run->status;

    if ($status !== 'running') {
        break;
    }

    if ((microtime(true) - $startedAt) > $deadlineSeconds) {
        fwrite(STDERR, "Timeout esperando finalizacion del escaneo.\n");
        exit(2);
    }

    usleep(500000);
}

$run = MonitoringMassScanRun::query()->where('run_id', $runId)->first();

if (! $run instanceof MonitoringMassScanRun) {
    fwrite(STDERR, "No existe el run_id proporcionado.\n");
    exit(1);
}

$start = $run->started_at;
$end = $run->completed_at;

if ($start === null || $end === null) {
    fwrite(STDERR, "El run no tiene timestamps completos.\n");
    exit(3);
}

$durationSeconds = max(0.001, (float) ($end->getTimestamp() - $start->getTimestamp()));
$totalSites = max(1, (int) $run->total_sites);
$completedTasks = (int) $run->completed_tasks;
$failedTasks = (int) $run->failed_tasks;

$profiles = SiteInspectionProfile::query()
    ->where('scan_run_id', $runId)
    ->whereNotNull('inspected_at')
    ->get(['site_id', 'fingerprint_payload']);

$profilesCount = $profiles->count();
$httpRequests = [];

foreach ($profiles as $profile) {
    $payload = is_array($profile->fingerprint_payload) ? $profile->fingerprint_payload : [];
    $instrumentation = is_array($payload['instrumentation'] ?? null) ? $payload['instrumentation'] : [];
    $requests = (int) ($instrumentation['http_requests'] ?? 0);

    if ($requests > 0) {
        $httpRequests[] = $requests;
    }
}

$avgHttpRequestsPerSite = $httpRequests !== []
    ? array_sum($httpRequests) / count($httpRequests)
    : 0.0;

$sitesPerSecond = $totalSites / $durationSeconds;
$avgSecondsPerSite = $durationSeconds / $totalSites;

fwrite(STDOUT, json_encode([
    'run_id' => $runId,
    'status' => (string) $run->status,
    'started_at' => $start->toIso8601String(),
    'completed_at' => $end->toIso8601String(),
    'duration_seconds' => round($durationSeconds, 3),
    'duration_minutes' => round($durationSeconds / 60, 3),
    'total_sites' => $totalSites,
    'completed_tasks' => $completedTasks,
    'failed_tasks' => $failedTasks,
    'sites_per_second' => round($sitesPerSecond, 4),
    'avg_seconds_per_site' => round($avgSecondsPerSite, 4),
    'profiles_with_inspected_at' => $profilesCount,
    'avg_http_requests_per_site' => round($avgHttpRequestsPerSite, 3),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
