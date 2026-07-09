<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Monitoring\Jobs\DispatchMassScanRunJob;
use Modules\Monitoring\Support\MassScanProgress;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

DB::transaction(static function (): void {
    DB::table('sites')->update([
        'current_status' => 'unknown',
        'last_checked_at' => null,
        'updated_at' => now(),
    ]);

    DB::table('site_checks')->delete();
});

$siteIds = DB::table('sites')
    ->orderBy('id')
    ->pluck('id')
    ->map(static fn ($id): int => (int) $id)
    ->all();

$progress = MassScanProgress::start(
    totalSites: count($siteIds),
    initiatedByUserId: null,
    triggerMode: 'manual',
);

$runId = (string) ($progress['run_id'] ?? '');

if ($runId === '') {
    fwrite(STDERR, "No se pudo generar run_id para el escaneo masivo.\n");
    exit(1);
}

DispatchMassScanRunJob::dispatch($runId, $siteIds);

$totalSites = (int) DB::table('sites')->count();
$unknownNow = (int) DB::table('sites')->where('current_status', 'unknown')->count();
$checkedNullNow = (int) DB::table('sites')->whereNull('last_checked_at')->count();
$checksNow = (int) DB::table('site_checks')->count();

fwrite(STDOUT, "run_id={$runId}\n");
fwrite(STDOUT, "sites_total={$totalSites}\n");
fwrite(STDOUT, "sites_unknown={$unknownNow}\n");
fwrite(STDOUT, "sites_last_checked_null={$checkedNullNow}\n");
fwrite(STDOUT, "site_checks_after_reset={$checksNow}\n");
fwrite(STDOUT, "mass_scan_dispatched=1\n");
