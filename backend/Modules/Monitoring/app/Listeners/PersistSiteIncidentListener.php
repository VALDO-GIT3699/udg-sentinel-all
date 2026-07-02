<?php

declare(strict_types=1);

namespace Modules\Monitoring\Listeners;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Models\Alert;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SiteDownNotification;
use App\Services\AlertNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Modules\Monitoring\Events\SiteStatusChanged;
use Modules\Monitoring\Support\MonitoringPermissionMatrix;

final class PersistSiteIncidentListener
{
    public function __construct(
        private readonly AlertRepositoryInterface $alertRepository,
        private readonly AlertNotificationService $alertNotificationService,
    ) {
    }

    public function handle(SiteStatusChanged $event): void
    {
        $nextStatus = (string) data_get($event->payload, 'statusAfterCode', '');

        if (! in_array($nextStatus, ['down', 'degraded'], true)) {
            return;
        }

        $site = Site::query()->find($event->siteId);

        if (! $site instanceof Site) {
            return;
        }

        $previousStatus = (string) data_get($event->payload, 'statusBeforeCode', 'unknown');
        $detectedAt = $this->resolveDetectedAt((string) data_get($event->payload, 'detectedAt', ''));
        $severity = $this->resolveSeverity(
            (string) data_get($event->payload, 'severity', 'info'),
            $nextStatus,
            (int) $site->priority,
        );

        $alert = $this->findExistingOpenIncident($site->id, $nextStatus) ?? $this->alertRepository->create([
            'site_id' => $site->id,
            'title' => $nextStatus === 'down' ? 'Incidente critico: sitio caido' : 'Incidente: sitio degradado',
            'message' => (string) data_get($event->payload, 'cause', 'Cambio de estado detectado por el monitor.'),
            'severity' => $severity,
            'status' => 'open',
            'triggered_at' => $detectedAt,
            'context' => [
                'event' => 'site.status.incident',
                'site_status_before' => $previousStatus,
                'site_status_after' => $nextStatus,
                'severity' => $severity,
                'event_occurred_at' => $detectedAt->toIso8601String(),
            ],
        ]);

        if ($severity === 'critical') {
            if (! (bool) config('monitoring.notifications.external_enabled', false)) {
                return;
            }

            try {
                $this->notifyCriticalIncident($alert, $site, $previousStatus, $nextStatus, $detectedAt);
            } catch (\Throwable $exception) {
                Log::warning('No se pudo notificar incidente critico por correo.', [
                    'site_id' => $site->id,
                    'alert_id' => $alert->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            try {
                // Mantiene compatibilidad con canales legacy (Slack/Webhook + tracking de envios).
                $this->alertNotificationService->dispatch($alert, [
                    'trigger' => 'site_down_critical',
                    'site_status_before' => $previousStatus,
                    'site_status_after' => $nextStatus,
                    'detected_at' => $detectedAt->toIso8601String(),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('No se pudo despachar alerta en canales legacy.', [
                    'site_id' => $site->id,
                    'alert_id' => $alert->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function resolveDetectedAt(string $detectedAt): CarbonImmutable
    {
        if ($detectedAt === '') {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::parse($detectedAt);
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }

    private function resolveSeverity(string $payloadSeverity, string $nextStatus, int $priority): string
    {
        if (in_array($payloadSeverity, ['critical', 'high', 'medium', 'low', 'info'], true)) {
            return $payloadSeverity;
        }

        if ($nextStatus === 'down') {
            return $priority === 1 ? 'critical' : 'high';
        }

        return $priority === 1 ? 'high' : 'medium';
    }

    private function findExistingOpenIncident(int $siteId, string $nextStatus): ?Alert
    {
        return $this->alertRepository
            ->openForSite($siteId)
            ->first(static fn (Alert $alert): bool => data_get($alert->context, 'event') === 'site.status.incident'
                && data_get($alert->context, 'site_status_after') === $nextStatus);
    }

    private function notifyCriticalIncident(
        Alert $alert,
        Site $site,
        string $previousStatus,
        string $nextStatus,
        CarbonImmutable $detectedAt,
    ): void {
        $admins = User::query()
            ->active()
            ->role(MonitoringPermissionMatrix::ADMIN_ROLE)
            ->get();

        if ($admins->isEmpty()) {
            Log::warning('No se encontraron administradores activos para notificar incidente critico.', [
                'site_id' => $site->id,
                'alert_id' => $alert->id,
            ]);

            return;
        }

        $notification = new SiteDownNotification(
            siteName: $site->name,
            siteUrl: $site->url,
            previousStatus: $previousStatus,
            currentStatus: $nextStatus,
            severity: (string) $alert->severity,
            detectedAtIso: $detectedAt->toIso8601String(),
            alertId: (int) $alert->id,
            incidentMessage: (string) ($alert->message ?? 'Sin detalle adicional.'),
        );

        $admins->each(static function (User $admin) use ($notification, $site, $alert): void {
            try {
                $admin->notify($notification);
            } catch (\Throwable $exception) {
                Log::warning('No se pudo notificar a administrador de incidente critico.', [
                    'site_id' => $site->id,
                    'alert_id' => $alert->id,
                    'admin_id' => $admin->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }
}
