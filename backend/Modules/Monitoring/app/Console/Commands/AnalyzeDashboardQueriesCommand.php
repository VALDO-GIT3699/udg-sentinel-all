<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AnalyzeDashboardQueriesCommand extends Command
{
    protected $signature = 'monitoring:analyze-dashboard-queries {--group-id=1 : Group id sample for group-scoped queries} {--format=table : Output format table|json}';

    protected $description = 'Ejecuta EXPLAIN sobre queries criticas del dashboard de monitoreo.';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();
        $groupId = max(1, (int) $this->option('group-id'));
        $format = (string) $this->option('format');

        $queries = $this->criticalQueries($groupId);
        $hasErrors = false;

        $this->info(sprintf('Analizando %d queries criticas con driver %s', count($queries), $driver));

        foreach ($queries as $item) {
            $label = $item['label'];
            $sql = $item['sql'];
            $bindings = $item['bindings'];

            try {
                $plan = $this->runExplain($driver, $sql, $bindings);
                $this->line('');
                $this->line(sprintf('[%s]', $label));

                if ($format === 'json') {
                    $this->line(json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '[]');
                    continue;
                }

                $rows = array_map(static fn (object $row): array => (array) $row, $plan);

                if ($rows === []) {
                    $this->line('Sin filas en plan de ejecucion.');
                    continue;
                }

                $this->table(array_keys($rows[0]), $rows);
            } catch (\Throwable $exception) {
                $hasErrors = true;
                $this->error(sprintf('[%s] %s', $label, $exception->getMessage()));
            }
        }

        if ($hasErrors) {
            $this->warn('Algunas consultas no pudieron analizarse. Verifica conectividad DB y esquema migrado.');
            return self::FAILURE;
        }

        $this->info('Analisis de planes completado.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{label:string,sql:string,bindings:array<int,mixed>}>
     */
    private function criticalQueries(int $groupId): array
    {
        $windowStart = now()->subHour();

        $dashboardSites = DB::table('sites')
            ->where('is_active', true)
            ->where('is_monitored', true)
            ->orderByRaw("CASE current_status WHEN 'down' THEN 1 WHEN 'degraded' THEN 2 WHEN 'up' THEN 3 ELSE 4 END")
            ->orderBy('priority')
            ->limit(20);

        $statusByGroup = DB::table('site_groups')
            ->leftJoin('sites', 'sites.site_group_id', '=', 'site_groups.id')
            ->selectRaw(
                "site_groups.id, site_groups.name,
                SUM(CASE WHEN sites.is_active = true AND sites.is_monitored = true THEN 1 ELSE 0 END) as monitored_sites_count,
                SUM(CASE WHEN sites.is_active = true AND sites.is_monitored = true AND sites.current_status = 'up' THEN 1 ELSE 0 END) as up_count,
                SUM(CASE WHEN sites.is_active = true AND sites.is_monitored = true AND sites.current_status = 'degraded' THEN 1 ELSE 0 END) as degraded_count,
                SUM(CASE WHEN sites.is_active = true AND sites.is_monitored = true AND sites.current_status = 'down' THEN 1 ELSE 0 END) as down_count,
                SUM(CASE WHEN sites.is_active = true AND sites.is_monitored = true AND sites.current_status = 'unknown' THEN 1 ELSE 0 END) as unknown_count"
            )
            ->groupBy('site_groups.id', 'site_groups.name')
            ->orderBy('site_groups.name');

        $pipelineSummary = DB::table('site_checks')
            ->join('sites', 'sites.id', '=', 'site_checks.site_id')
            ->where('site_checks.checked_at', '>=', $windowStart)
            ->where('sites.is_active', true)
            ->where('sites.is_monitored', true)
            ->selectRaw('COUNT(*) as total_checks')
            ->selectRaw("SUM(CASE WHEN site_checks.status = 'down' THEN 1 ELSE 0 END) as down_checks")
            ->selectRaw('AVG(site_checks.response_time_ms) as avg_latency_ms');

        $openAlertsByGroup = DB::table('alerts')
            ->join('sites', 'sites.id', '=', 'alerts.site_id')
            ->where('alerts.status', 'open')
            ->where('sites.site_group_id', $groupId)
            ->orderByRaw("CASE alerts.severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderByDesc('alerts.triggered_at')
            ->limit(20);

        return [
            [
                'label' => 'dashboard_sites',
                'sql' => $dashboardSites->toSql(),
                'bindings' => $dashboardSites->getBindings(),
            ],
            [
                'label' => 'status_by_group',
                'sql' => $statusByGroup->toSql(),
                'bindings' => $statusByGroup->getBindings(),
            ],
            [
                'label' => 'pipeline_summary',
                'sql' => $pipelineSummary->toSql(),
                'bindings' => $pipelineSummary->getBindings(),
            ],
            [
                'label' => 'open_alerts_by_group',
                'sql' => $openAlertsByGroup->toSql(),
                'bindings' => $openAlertsByGroup->getBindings(),
            ],
        ];
    }

    /**
     * @param array<int, mixed> $bindings
     * @return array<int, object>
     */
    private function runExplain(string $driver, string $sql, array $bindings): array
    {
        $prefix = $driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ';

        return DB::select($prefix . $sql, $bindings);
    }
}
