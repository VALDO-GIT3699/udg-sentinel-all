<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneOldMetricsCommand extends Command
{
    protected $signature = 'monitoring:prune-old-metrics
                            {--keep-live-days=7 : Mantiene detalle minuto a minuto durante este periodo}
                            {--compact-days=30 : Resume y compacta datos de este rango hacia atras}
                            {--summary-years=5 : Retencion maxima de los resumentes diarios}';

    protected $description = 'Compacta metricas de alta frecuencia y purga detalle antiguo en PostgreSQL.';

    public function handle(): int
    {
        $keepLiveDays = max(1, (int) $this->option('keep-live-days'));
        $compactDays = max($keepLiveDays + 1, (int) $this->option('compact-days'));
        $summaryRetentionYears = max(1, (int) $this->option('summary-years'));

        DB::transaction(function () use ($keepLiveDays, $compactDays, $summaryRetentionYears): void {
            $this->compactSiteChecks($keepLiveDays, $compactDays);
            $this->compactServerMetrics($keepLiveDays, $compactDays);
            $this->compactTrafficMetrics($keepLiveDays, $compactDays);

            $this->purgeRawRows($keepLiveDays);
            $this->purgeOldSummaries($summaryRetentionYears);
        });

        $this->info(sprintf(
            'Pruning ejecutado: detalle vivo %d dias, resumen %d dias, retencion %d anos.',
            $keepLiveDays,
            $compactDays,
            $summaryRetentionYears
        ));

        return self::SUCCESS;
    }

    private function compactSiteChecks(int $keepLiveDays, int $compactDays): void
    {
        $sql = sprintf(
            <<<'SQL'
INSERT INTO monitoring_site_check_daily_summaries (
    site_id,
    summary_date,
    total_checks,
    up_checks,
    down_checks,
    degraded_checks,
    timeout_checks,
    avg_response_time_ms,
    created_at,
    updated_at
)
SELECT
    site_id,
    DATE_TRUNC('day', checked_at)::date AS summary_date,
    COUNT(*)::integer AS total_checks,
    SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END)::integer AS up_checks,
    SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END)::integer AS down_checks,
    SUM(CASE WHEN status = 'degraded' THEN 1 ELSE 0 END)::integer AS degraded_checks,
    SUM(CASE WHEN status = 'timeout' THEN 1 ELSE 0 END)::integer AS timeout_checks,
    ROUND(AVG(response_time_ms))::integer AS avg_response_time_ms,
    NOW(),
    NOW()
FROM site_checks
WHERE checked_at >= NOW() - INTERVAL '%d days'
  AND checked_at < NOW() - INTERVAL '%d days'
GROUP BY site_id, DATE_TRUNC('day', checked_at)::date
ON CONFLICT (site_id, summary_date) DO UPDATE SET
    total_checks = EXCLUDED.total_checks,
    up_checks = EXCLUDED.up_checks,
    down_checks = EXCLUDED.down_checks,
    degraded_checks = EXCLUDED.degraded_checks,
    timeout_checks = EXCLUDED.timeout_checks,
    avg_response_time_ms = EXCLUDED.avg_response_time_ms,
    updated_at = NOW()
SQL,
            $compactDays,
            $keepLiveDays,
            $compactDays
        );

        DB::statement($sql);
    }

    private function compactServerMetrics(int $keepLiveDays, int $compactDays): void
    {
        $sql = sprintf(
            <<<'SQL'
INSERT INTO monitoring_server_metric_daily_summaries (
    server_id,
    summary_date,
    avg_cpu_usage_pct,
    avg_ram_usage_pct,
    avg_disk_usage_pct,
    avg_load_avg_1,
    avg_load_avg_5,
    avg_load_avg_15,
    created_at,
    updated_at
)
SELECT
    server_id,
    DATE_TRUNC('day', recorded_at)::date AS summary_date,
    ROUND(AVG(cpu_usage_pct), 2) AS avg_cpu_usage_pct,
    ROUND(AVG(ram_usage_pct), 2) AS avg_ram_usage_pct,
    ROUND(AVG(disk_usage_pct), 2) AS avg_disk_usage_pct,
    ROUND(AVG(load_avg_1), 2) AS avg_load_avg_1,
    ROUND(AVG(load_avg_5), 2) AS avg_load_avg_5,
    ROUND(AVG(load_avg_15), 2) AS avg_load_avg_15,
    NOW(),
    NOW()
FROM server_metrics
WHERE recorded_at >= NOW() - INTERVAL '%d days'
  AND recorded_at < NOW() - INTERVAL '%d days'
GROUP BY server_id, DATE_TRUNC('day', recorded_at)::date
ON CONFLICT (server_id, summary_date) DO UPDATE SET
    avg_cpu_usage_pct = EXCLUDED.avg_cpu_usage_pct,
    avg_ram_usage_pct = EXCLUDED.avg_ram_usage_pct,
    avg_disk_usage_pct = EXCLUDED.avg_disk_usage_pct,
    avg_load_avg_1 = EXCLUDED.avg_load_avg_1,
    avg_load_avg_5 = EXCLUDED.avg_load_avg_5,
    avg_load_avg_15 = EXCLUDED.avg_load_avg_15,
    updated_at = NOW()
SQL,
            $compactDays,
            $keepLiveDays,
            $compactDays
        );

        DB::statement($sql);
    }

    private function compactTrafficMetrics(int $keepLiveDays, int $compactDays): void
    {
        $sql = sprintf(
            <<<'SQL'
INSERT INTO monitoring_traffic_metric_daily_summaries (
    site_id,
    summary_date,
    total_requests,
    unique_visitors,
    bandwidth_bytes,
    avg_error_rate_pct,
    avg_response_time_ms,
    created_at,
    updated_at
)
SELECT
    site_id,
    DATE_TRUNC('day', recorded_at)::date AS summary_date,
    ROUND(AVG(requests_per_min))::integer AS total_requests,
    ROUND(AVG(unique_visitors))::integer AS unique_visitors,
    SUM(bandwidth_bytes)::bigint AS bandwidth_bytes,
    ROUND(AVG(error_rate_pct), 2) AS avg_error_rate_pct,
    ROUND(AVG(avg_response_time_ms))::integer AS avg_response_time_ms,
    NOW(),
    NOW()
FROM traffic_metrics
WHERE recorded_at >= NOW() - INTERVAL '%d days'
  AND recorded_at < NOW() - INTERVAL '%d days'
GROUP BY site_id, DATE_TRUNC('day', recorded_at)::date
ON CONFLICT (site_id, summary_date) DO UPDATE SET
    total_requests = EXCLUDED.total_requests,
    unique_visitors = EXCLUDED.unique_visitors,
    bandwidth_bytes = EXCLUDED.bandwidth_bytes,
    avg_error_rate_pct = EXCLUDED.avg_error_rate_pct,
    avg_response_time_ms = EXCLUDED.avg_response_time_ms,
    updated_at = NOW()
SQL,
            $compactDays,
            $keepLiveDays,
            $compactDays
        );

        DB::statement($sql);
    }

    private function purgeRawRows(int $keepLiveDays): void
    {
        $cutoff = now()->subDays($keepLiveDays);

        DB::table('site_checks')->where('checked_at', '<', $cutoff)->delete();
        DB::table('server_metrics')->where('recorded_at', '<', $cutoff)->delete();
        DB::table('traffic_metrics')->where('recorded_at', '<', $cutoff)->delete();
    }

    private function purgeOldSummaries(int $summaryRetentionYears): void
    {
        $cutoff = now()->subYears($summaryRetentionYears)->toDateString();

        DB::table('monitoring_site_check_daily_summaries')->where('summary_date', '<', $cutoff)->delete();
        DB::table('monitoring_server_metric_daily_summaries')->where('summary_date', '<', $cutoff)->delete();
        DB::table('monitoring_traffic_metric_daily_summaries')->where('summary_date', '<', $cutoff)->delete();
    }
}