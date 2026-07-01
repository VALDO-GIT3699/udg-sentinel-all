<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Models\TrafficMetric;
use App\Repositories\EloquentSiteCheckRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

final class SiteDetailController extends Controller
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly EloquentSiteCheckRepository $siteCheckRepository,
        private readonly AlertRepositoryInterface $alertRepository,
    ) {
    }

    public function show(int $siteId): Response
    {
        $site = $this->siteRepository->findById($siteId);
        abort_if(! $site instanceof Site, 404);

        return Inertia::render('Monitoring/SiteDetail', $this->buildPayload($site));
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

        $latestHeader = $site->latestSecurityHeader;
        $headerMap = [
            'strict-transport-security' => ['label' => 'HSTS', 'present' => (bool) ($latestHeader?->has_hsts ?? false)],
            'content-security-policy' => ['label' => 'Content-Security-Policy', 'present' => (bool) ($latestHeader?->has_csp ?? false)],
            'x-frame-options' => ['label' => 'X-Frame-Options', 'present' => (bool) ($latestHeader?->has_x_frame_options ?? false)],
            'x-content-type-options' => ['label' => 'X-Content-Type-Options', 'present' => (bool) ($latestHeader?->has_x_content_type ?? false)],
            'referrer-policy' => ['label' => 'Referrer-Policy', 'present' => (bool) ($latestHeader?->has_referrer_policy ?? false)],
            'permissions-policy' => ['label' => 'Permissions-Policy', 'present' => (bool) ($latestHeader?->has_permissions_policy ?? false)],
        ];

        $rawHeaders = is_array($latestHeader?->raw_headers) ? $latestHeader->raw_headers : [];
        $securityHeaders = collect($headerMap)
            ->map(function (array $headerConfig, string $key) use ($rawHeaders): array {
                $rawValue = $rawHeaders[$key] ?? null;

                return [
                    'key' => $key,
                    'label' => $headerConfig['label'],
                    'present' => (bool) $headerConfig['present'],
                    'value' => is_array($rawValue) ? (string) ($rawValue[0] ?? '') : (string) ($rawValue ?? ''),
                ];
            })
            ->values()
            ->all();

        return [
            'site' => $site,
            'timeline' => $checksTimeline,
            'statusBreakdown24h' => $statusBreakdown24h,
            'uptime24h' => $this->siteCheckRepository->uptimePercentage((int) $site->id, 24),
            'avgResponse24h' => $this->siteCheckRepository->avgResponseTime((int) $site->id, 24),
            'openAlerts' => $this->alertRepository->openForSite((int) $site->id),
            'events' => $site->events()->orderByDesc('occurred_at')->limit(100)->get(),
            'trafficSeries24h' => $traffic24h->map(static fn (TrafficMetric $metric): array => [
                'at' => optional($metric->recorded_at)?->toIso8601String(),
                'rpm' => (int) ($metric->requests_per_min ?? 0),
                'error_rate_pct' => (float) ($metric->error_rate_pct ?? 0),
            ])->values()->all(),
            'trafficSeries1h' => $traffic24h
                ->filter(static fn (TrafficMetric $metric): bool =>
                    $metric->recorded_at !== null && $metric->recorded_at->greaterThanOrEqualTo(now()->subHour())
                )
                ->map(static fn (TrafficMetric $metric): array => [
                    'at' => optional($metric->recorded_at)?->toIso8601String(),
                    'rpm' => (int) ($metric->requests_per_min ?? 0),
                    'error_rate_pct' => (float) ($metric->error_rate_pct ?? 0),
                ])
                ->values()
                ->all(),
            'securityHeaders' => $securityHeaders,
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @param array<int, mixed> $timeline
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
     * @param array<string, mixed> $breakdown
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
}
