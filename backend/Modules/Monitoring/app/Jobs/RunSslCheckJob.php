<?php

declare(strict_types=1);

namespace Modules\Monitoring\Jobs;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SslCertificate;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Monitoring\Events\CertificateExpiring;
use Modules\Monitoring\Events\AlertResolved;
use Modules\Monitoring\Events\AlertTriggered;
use Modules\Monitoring\Events\SslExpired;
use Modules\Monitoring\Events\SslExpiringSoon;
use Modules\Monitoring\Support\MassScanProgress;

final class RunSslCheckJob implements ShouldQueue
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
    )
    {
        $this->onQueue((string) env('SENTINEL_QUEUE_SSL', 'monitoring-ssl'));
    }

    public function handle(
        SiteRepositoryInterface $siteRepository,
        AlertRepositoryInterface $alertRepository,
    ): void {
        try {
            $site = $siteRepository->findById($this->siteId);

            if (! $site instanceof Site || (! $this->forceScan && (! $site->is_active || ! $site->is_monitored))) {
                return;
            }

            if (! str_starts_with(strtolower($site->url), 'https://')) {
                return;
            }

            try {
                $parsed = parse_url($site->url);
                $host = (string) ($parsed['host'] ?? '');
                $port = (int) ($parsed['port'] ?? 443);

            if ($host === '') {
                return;
            }

            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'SNI_enabled' => true,
                    'peer_name' => $host,
                ],
            ]);

            $client = @stream_socket_client(
                sprintf('ssl://%s:%d', $host, $port),
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (! is_resource($client)) {
                $this->openSslAlertIfNeeded(
                    $alertRepository,
                    $site,
                    'SSL requiere atencion',
                    'No fue posible abrir conexion SSL: ' . $errstr,
                    'high',
                    'ssl.expiring.soon'
                );
                return;
            }

            $params = stream_context_get_params($client);
            fclose($client);

            $certificateResource = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($certificateResource === null) {
                $this->openSslAlertIfNeeded(
                    $alertRepository,
                    $site,
                    'SSL requiere atencion',
                    'No se pudo obtener certificado SSL remoto.',
                    'high',
                    'ssl.expiring.soon'
                );
                return;
            }

            $parsedCert = openssl_x509_parse($certificateResource, false);
            $fingerprint = openssl_x509_fingerprint($certificateResource, 'sha256');

            if (! is_array($parsedCert) || $fingerprint === false) {
                $this->openSslAlertIfNeeded(
                    $alertRepository,
                    $site,
                    'SSL requiere atencion',
                    'No se pudo parsear certificado SSL.',
                    'high',
                    'ssl.expiring.soon'
                );
                return;
            }

            $validFrom = isset($parsedCert['validFrom_time_t'])
                ? CarbonImmutable::createFromTimestampUTC((int) $parsedCert['validFrom_time_t'])
                : null;

            $validUntil = isset($parsedCert['validTo_time_t'])
                ? CarbonImmutable::createFromTimestampUTC((int) $parsedCert['validTo_time_t'])
                : null;

            $daysRemaining = $validUntil !== null
                ? (int) now()->diffInDays($validUntil, false)
                : null;
            $isExpired = $daysRemaining !== null ? $daysRemaining < 0 : false;

            SslCertificate::create([
                'site_id' => $site->id,
                'common_name' => (string) ($parsedCert['subject']['CN'] ?? ''),
                'issuer' => (string) ($parsedCert['issuer']['CN'] ?? ''),
                'issuer_org' => (string) ($parsedCert['issuer']['O'] ?? ''),
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'days_remaining' => $daysRemaining,
                'is_valid' => ! $isExpired,
                'is_expired' => $isExpired,
                'algorithm' => (string) ($parsedCert['signatureTypeLN'] ?? ''),
                'key_size' => isset($parsedCert['bits']) ? (int) $parsedCert['bits'] : null,
                'signature_alg' => (string) ($parsedCert['signatureTypeSN'] ?? ''),
                'san_domains' => $this->extractSanDomains($parsedCert),
                'fingerprint_sha256' => $fingerprint,
                'last_checked_at' => now(),
            ]);

            $warningDays = (int) env('SENTINEL_SSL_ALERT_DAYS_WARNING', 30);
            $criticalDays = (int) env('SENTINEL_SSL_ALERT_DAYS_CRITICAL', 7);

            if ($daysRemaining !== null && $daysRemaining < 0) {
                $this->openSslAlertIfNeeded(
                    $alertRepository,
                    $site,
                    'SSL expirado',
                    sprintf('Certificado SSL expirado hace %d dias.', abs($daysRemaining)),
                    'critical',
                    'ssl.expired',
                    abs($daysRemaining)
                );
            } elseif ($daysRemaining !== null && $daysRemaining <= $criticalDays) {
                $this->openSslAlertIfNeeded(
                    $alertRepository,
                    $site,
                    'SSL en estado critico',
                    sprintf('Certificado SSL critico: %d dias restantes.', $daysRemaining),
                    'critical',
                    'ssl.expiring.soon',
                    $daysRemaining
                );
            } elseif ($daysRemaining !== null && $daysRemaining <= $warningDays) {
                $this->openSslAlertIfNeeded(
                    $alertRepository,
                    $site,
                    'SSL por vencer',
                    sprintf('Certificado SSL en aviso: %d dias restantes.', $daysRemaining),
                    'high',
                    'ssl.expiring.soon',
                    $daysRemaining
                );
            }
            } catch (\Throwable $exception) {
                $this->openSslAlertIfNeeded(
                    $alertRepository,
                    $site,
                    'SSL requiere atencion',
                    'Error en escaneo SSL: ' . $exception->getMessage(),
                    'high',
                    'ssl.expiring.soon'
                );

                if (is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                    MassScanProgress::recordFailure(
                        $this->massScanRunId,
                        'ssl',
                        $this->siteId,
                        $exception->getMessage(),
                    );
                }
            }
        } finally {
            if (is_string($this->massScanRunId) && $this->massScanRunId !== '') {
                MassScanProgress::completeTask($this->massScanRunId, 'ssl', $this->siteId);
            }
        }
    }

    private function extractSanDomains(array $parsedCert): array
    {
        $san = (string) ($parsedCert['extensions']['subjectAltName'] ?? '');

        if ($san === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $san));
        $domains = [];

        foreach ($parts as $part) {
            if (str_starts_with($part, 'DNS:')) {
                $domains[] = mb_substr($part, 4);
            }
        }

        return array_values(array_unique($domains));
    }

    private function openSslAlertIfNeeded(
        AlertRepositoryInterface $alertRepository,
        Site $site,
        string $title,
        string $message,
        string $severity,
        string $event,
        int $daysContext = 0,
    ): void
    {
        $openAlerts = $alertRepository->openForSite($site->id);
        $matchingOpenAlerts = $openAlerts->filter(
            fn ($alert) => data_get($alert->context, 'event') === $event
        );

        if ($matchingOpenAlerts->isNotEmpty()) {
            return;
        }

        if ($event === 'ssl.expired') {
            $openAlerts
                ->filter(fn ($alert) => data_get($alert->context, 'event') === 'ssl.expiring.soon')
                ->each(function ($alert): void {
                    $alert->update([
                        'status' => 'resolved',
                        'resolved_at' => now(),
                        'resolved_by' => null,
                    ]);

                    event(new AlertResolved(
                        alertId: (int) $alert->id,
                        siteId: $alert->site_id !== null ? (int) $alert->site_id : null,
                        event: (string) data_get($alert->context, 'event', 'alert.resolved'),
                        resolvedAt: now()->toIso8601String(),
                    ));
                });
        }

        SiteEvent::record(
            siteId: $site->id,
            eventType: $event,
            title: $title,
            severity: $severity,
            description: mb_substr($message, 0, 1000),
            metadata: [
                'event' => $event,
            ],
        );

        $createdAlert = $alertRepository->create([
            'site_id' => $site->id,
            'title' => $title,
            'message' => mb_substr($message, 0, 1000),
            'severity' => $severity,
            'status' => 'open',
            'triggered_at' => now(),
            'context' => [
                'event' => $event,
            ],
        ]);

        event(new AlertTriggered(
            alertId: (int) $createdAlert->id,
            siteId: $createdAlert->site_id !== null ? (int) $createdAlert->site_id : null,
            severity: (string) $createdAlert->severity,
            event: $event,
            triggeredAt: now()->toIso8601String(),
        ));

        if ($event === 'ssl.expired') {
            SslExpired::dispatch(
                siteId: (int) $site->id,
                daysOverdue: $daysContext,
                checkedAt: now()->toIso8601String(),
            );
        } else {
            SslExpiringSoon::dispatch(
                siteId: (int) $site->id,
                daysRemaining: $daysContext,
                severity: $severity,
                checkedAt: now()->toIso8601String(),
            );

            event(new CertificateExpiring(
                siteId: (int) $site->id,
                daysRemaining: $daysContext,
                severity: $severity,
                checkedAt: now()->toIso8601String(),
            ));
        }
    }
}
