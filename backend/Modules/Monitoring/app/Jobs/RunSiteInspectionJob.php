<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Models\SiteInspectionProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Monitoring\Services\SiteInspection\VerticalSiteInspectionEngine;
use Modules\Monitoring\Support\MassScanProgress;

final class RunSiteInspectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        private readonly int $siteId,
        private readonly ?string $massScanRunId = null,
        private readonly bool $forceScan = false,
    ) {
        $this->onQueue((string) env('SENTINEL_QUEUE_SITE_INSPECTION', env('SENTINEL_QUEUE_UPTIME', 'monitoring-uptime')));
    }

    public function handle(
        SiteRepositoryInterface $siteRepository,
        VerticalSiteInspectionEngine $engine,
    ): void {
        $site = null;

        try {
            $site = $siteRepository->findById($this->siteId);

            if (! $site instanceof Site || (! $this->forceScan && ! $site->is_monitored)) {
                return;
            }

            $inspection = $engine->inspect($site);
            $fingerprintPayload = $this->sanitizeForJson((array) ($inspection['fingerprint'] ?? []));
            $fingerprintPayload['instrumentation'] = $this->sanitizeForJson((array) ($inspection['instrumentation'] ?? []));
            $analysisErrors = $this->buildAnalysisErrors($inspection);
            $resolvedStatus = $this->resolveOperationalStatus($inspection, $analysisErrors);

            SiteInspectionProfile::query()->updateOrCreate(
                ['site_id' => (int) $site->id],
                [
                    'scan_run_id' => $this->massScanRunId,
                    'analysis_version' => 'vertical-inspector-v1',
                    'dns_status' => (string) ($inspection['dns']['status'] ?? 'error'),
                    'dns_error' => $inspection['dns']['error'] ?? null,
                    'dns_records' => $this->sanitizeForJson($inspection['dns']['records'] ?? []),
                    'http_status' => $inspection['http']['status_code'] ?? null,
                    'https_status' => $inspection['https']['status_code'] ?? null,
                    'redirect_chain' => $this->sanitizeForJson($inspection['redirect_chain'] ?? []),
                    'response_time_ms' => $inspection['response_time_ms'] ?? null,
                    'ttfb_ms' => $inspection['ttfb_ms'] ?? null,
                    'ssl_status' => (string) ($inspection['ssl']['status'] ?? 'error'),
                    'ssl_error' => $inspection['ssl']['error'] ?? null,
                    'ssl_payload' => $this->sanitizeForJson($inspection['ssl'] ?? []),
                    'security_headers_status' => (string) ($inspection['security_headers']['status'] ?? 'error'),
                    'security_headers_payload' => $this->sanitizeForJson($inspection['security_headers'] ?? []),
                    'body_status' => (string) ($inspection['body']['status'] ?? 'error'),
                    'body_error' => $inspection['body']['error'] ?? null,
                    'fingerprint_status' => (string) ($inspection['fingerprint']['status'] ?? 'error'),
                    'fingerprint_payload' => $fingerprintPayload,
                    'cms_name' => (string) ($inspection['cms']['name'] ?? 'No determinado'),
                    'cms_version' => (string) ($inspection['cms']['version'] ?? 'No determinado'),
                    'cms_confidence' => (string) ($inspection['cms']['confidence'] ?? 'low'),
                    'server_signature' => (string) ($inspection['server'] ?? 'No determinado'),
                    'runtime_name' => (string) ($inspection['runtime']['name'] ?? 'No determinado'),
                    'runtime_version' => (string) ($inspection['runtime']['version'] ?? 'No determinado'),
                    'js_frameworks' => $this->sanitizeForJson($inspection['js_frameworks'] ?? []),
                    'risk_score' => (int) ($inspection['risk_score'] ?? 100),
                    'risk_level' => (string) ($inspection['risk_level'] ?? 'Crítico'),
                    'essential_checks_complete' => (bool) ($inspection['essential_checks_complete'] ?? false),
                    'analysis_errors' => $this->sanitizeForJson($analysisErrors),
                    'inspected_at' => now(),
                    // Cualquier inspeccion real y completa (esta) reemplaza la marca de
                    // "interrumpido" que haya dejado una cancelacion anterior.
                    'scan_interrupted_at' => null,
                ],
            );

            $site->forceFill([
                'current_status' => $resolvedStatus,
                'last_checked_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            if ($site instanceof Site) {
                $this->persistFailedInspection($site, $exception->getMessage());
            }

            if (is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                MassScanProgress::recordFailure(
                    $this->massScanRunId,
                    'inspection',
                    $this->siteId,
                    $exception->getMessage(),
                );
            }
        } finally {
            if (is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                MassScanProgress::completeTask($this->massScanRunId, 'inspection', $this->siteId);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @return array<int, string>
     */
    private function buildAnalysisErrors(array $inspection): array
    {
        $errors = [];

        $dnsStatus = (string) ($inspection['dns']['status'] ?? 'error');
        $dnsError = trim((string) ($inspection['dns']['error'] ?? ''));
        $httpStatus = $inspection['http']['status_code'] ?? null;
        $httpError = trim((string) ($inspection['http']['error'] ?? ''));
        $sslStatus = (string) ($inspection['ssl']['status'] ?? 'error');
        $sslError = trim((string) ($inspection['ssl']['error'] ?? ''));
        $sslValid = (bool) ($inspection['ssl']['valid'] ?? false);
        $bodyStatus = (string) ($inspection['body']['status'] ?? 'error');
        $fingerprintStatus = (string) ($inspection['fingerprint']['status'] ?? 'error');

        if ($dnsStatus === 'no_records' || str_contains(mb_strtolower($dnsError), 'no dns')) {
            $errors[] = 'No existen registros DNS para el dominio.';
        } elseif ($dnsStatus === 'error' && $dnsError !== '') {
            $errors[] = 'No fue posible resolver el DNS del dominio.';
        }

        if (! is_int($httpStatus) || $httpStatus <= 0) {
            $normalizedHttpError = mb_strtolower($httpError);

            $errors[] = match (true) {
                str_contains($normalizedHttpError, 'timeout') || str_contains($normalizedHttpError, 'timed out') => 'La inspección agotó el tiempo de espera al establecer conexión HTTP.',
                str_contains($normalizedHttpError, 'refused') => 'La conexión HTTP fue rechazada por el servidor.',
                str_contains($normalizedHttpError, 'ssl') || str_contains($normalizedHttpError, 'handshake') => 'No fue posible completar el handshake SSL del sitio.',
                $httpError !== '' => 'No fue posible establecer conexión HTTP.',
                default => 'La inspección no obtuvo respuesta HTTP del sitio.',
            };
        } elseif ($httpStatus >= 400) {
            $errors[] = sprintf('El sitio respondió con HTTP %d.', $httpStatus);
        }

        if (is_int($httpStatus) && $httpStatus > 0) {
            if (in_array($sslStatus, ['error', 'timeout'], true) || (! $sslValid && $sslStatus === 'ok')) {
                $errors[] = $sslError !== ''
                    ? 'El sitio respondió, pero presentó un problema SSL: '.$sslError
                    : 'El sitio respondió, pero el certificado SSL es inválido o está vencido.';
            }

            if ($bodyStatus === 'empty') {
                $errors[] = 'El sitio respondió con contenido vacío.';
            } elseif ($bodyStatus === 'error') {
                $errors[] = 'El contenido HTTP no pudo analizarse completamente.';
            }

            if ($fingerprintStatus !== 'ok') {
                $errors[] = 'El fingerprint de tecnología no pudo completarse.';
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @param  array<int, string>  $analysisErrors
     */
    private function resolveOperationalStatus(array $inspection, array $analysisErrors): string
    {
        $httpStatus = $inspection['http']['status_code'] ?? null;
        $sslStatus = (string) ($inspection['ssl']['status'] ?? 'error');
        $sslValid = (bool) ($inspection['ssl']['valid'] ?? false);
        $bodyStatus = (string) ($inspection['body']['status'] ?? 'error');

        if (! is_int($httpStatus) || $httpStatus <= 0) {
            return 'down';
        }

        if ($httpStatus >= 400) {
            return 'degraded';
        }

        if (in_array($sslStatus, ['error', 'timeout'], true) || (! $sslValid && $sslStatus === 'ok')) {
            return 'degraded';
        }

        if (in_array($bodyStatus, ['empty', 'error'], true)) {
            return 'degraded';
        }

        return $analysisErrors === [] ? 'up' : 'degraded';
    }

    private function persistFailedInspection(Site $site, string $errorMessage): void
    {
        $diagnostic = 'La inspección no finalizó correctamente durante el escaneo masivo.';
        $normalizedError = trim($errorMessage);

        SiteInspectionProfile::query()->updateOrCreate(
            ['site_id' => (int) $site->id],
            [
                'scan_run_id' => $this->massScanRunId,
                'analysis_version' => 'vertical-inspector-v1',
                'dns_status' => 'error',
                'http_status' => null,
                'https_status' => null,
                'ssl_status' => 'error',
                'ssl_error' => $normalizedError !== '' ? mb_substr($normalizedError, 0, 1000) : null,
                'security_headers_status' => 'error',
                'body_status' => 'error',
                'body_error' => $normalizedError !== '' ? mb_substr($normalizedError, 0, 1000) : $diagnostic,
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
                'analysis_errors' => $this->sanitizeForJson([$normalizedError !== '' ? mb_substr($normalizedError, 0, 1000) : $diagnostic]),
                'inspected_at' => now(),
                'scan_interrupted_at' => null,
            ],
        );

        $site->forceFill([
            'current_status' => 'down',
            'last_checked_at' => now(),
        ])->save();
    }

    private function sanitizeForJson(mixed $value): mixed
    {
        if (is_string($value)) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');

            if (is_string($converted) && $converted !== '') {
                return $converted;
            }

            return @iconv('UTF-8', 'UTF-8//IGNORE', $value) ?: '';
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizeForJson($item);
            }

            return $sanitized;
        }

        return $value;
    }
}
