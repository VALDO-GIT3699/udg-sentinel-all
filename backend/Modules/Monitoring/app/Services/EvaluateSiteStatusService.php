<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services;

use App\Contracts\Repositories\AlertRepositoryInterface;
use App\Contracts\Repositories\SiteCheckRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Services\AlertNotificationService;
use DateTimeInterface;
use Modules\Monitoring\Events\SiteDownDetected;
use Modules\Monitoring\Events\SiteRecovered;
use Modules\Monitoring\Events\SiteStatusChanged;

final class EvaluateSiteStatusService
{
    public function __construct(
        private readonly SiteCheckRepositoryInterface $siteCheckRepository,
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly AlertRepositoryInterface $alertRepository,
        private readonly AlertNotificationService $alertNotificationService,
    ) {
    }

    public function apply(Site $site, string $latestCheckStatus, ?string $errorMessage = null, ?DateTimeInterface $checkedAt = null): string
    {
        $lockedSite = null;

        if ($site->exists) {
            $lockedSite = Site::query()->whereKey($site->id)->lockForUpdate()->first();
        }

        $lockedSite = $lockedSite instanceof Site ? $lockedSite : $site;

        $previousStatus = (string) $lockedSite->current_status;
        $nextStatus = $previousStatus;

        if ($latestCheckStatus === 'up') {
            $consecutiveUp = $this->siteCheckRepository->consecutiveStatusCount($lockedSite->id, 'up', 5);
            $nextStatus = $consecutiveUp >= 2 ? 'up' : 'degraded';
        } else {
            $requiredFailures = ((int) $lockedSite->priority === 1) ? 2 : 3;
            $consecutiveDown = $this->siteCheckRepository->consecutiveStatusCount($lockedSite->id, 'down', 5);
            $nextStatus = $consecutiveDown >= $requiredFailures ? 'down' : 'degraded';
        }

        if ($nextStatus === $previousStatus) {
            return $nextStatus;
        }

        $this->siteRepository->update($site, [
            'last_checked_at' => $checkedAt ?? now(),
            'current_status' => $nextStatus,
        ]);

        $openAlerts = $this->alertRepository->openForSite($lockedSite->id);

        $eventType = $nextStatus === 'down'
            ? 'site.down.detected'
            : ($nextStatus === 'up' && $openAlerts->isNotEmpty() ? 'site.recovered' : 'site.status.changed');
        $severity = $nextStatus === 'down'
            ? (((int) $lockedSite->priority === 1) ? 'critical' : 'high')
            : 'info';

        SiteEvent::record(
            siteId: $lockedSite->id,
            eventType: $eventType,
            title: sprintf('Estado del sitio actualizado a %s', $this->statusLabel($nextStatus)),
            severity: $severity,
            description: $errorMessage,
            metadata: [
                'before' => $previousStatus,
                'after' => $nextStatus,
            ],
        );

        SiteStatusChanged::dispatch(
            siteId: $lockedSite->id,
            payload: [
                'event' => $eventType,
                'severity' => $severity,
                'siteName' => $lockedSite->name,
                'url' => $lockedSite->url,
                'statusBefore' => $this->statusLabel($previousStatus),
                'statusAfter' => $this->statusLabel($nextStatus),
                'statusBeforeCode' => $previousStatus,
                'statusAfterCode' => $nextStatus,
                'detectedAt' => now()->toIso8601String(),
                'cause' => $errorMessage,
            ]
        );

        if (function_exists('activity')) {
            activity('monitoring')
                ->performedOn($lockedSite)
                ->withProperties([
                    'action' => 'site.status.transition',
                    'before' => $previousStatus,
                    'after' => $nextStatus,
                    'event' => $eventType,
                ])
                ->log('Transicion de estado de sitio monitoreado');
        }

        if ($nextStatus === 'down') {
            if ($openAlerts->isEmpty()) {
                $severity = ((int) $lockedSite->priority === 1) ? 'critical' : 'high';

                $alert = $this->alertRepository->create([
                    'site_id' => $lockedSite->id,
                    'title' => 'Sitio oficial caido',
                    'message' => $errorMessage ?? 'Fallas consecutivas de disponibilidad detectadas.',
                    'severity' => $severity,
                    'status' => 'open',
                    'triggered_at' => now(),
                    'context' => [
                        'event' => 'site.down.detected',
                        'site_status_before' => $previousStatus,
                        'site_status_after' => $nextStatus,
                    ],
                ]);

                $this->alertNotificationService->dispatch($alert, [
                    'trigger' => 'site_down',
                    'site_status_before' => $previousStatus,
                    'site_status_after' => $nextStatus,
                ]);

                SiteDownDetected::dispatch(
                    siteId: (int) $lockedSite->id,
                    siteName: $lockedSite->name,
                    url: $lockedSite->url,
                    severity: $severity,
                    cause: $errorMessage,
                    detectedAt: now()->toIso8601String(),
                );
            }
        } elseif ($eventType === 'site.recovered') {
            foreach ($openAlerts as $openAlert) {
                $openAlert->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'resolved_by' => null,
                ]);
            }

            SiteEvent::record(
                siteId: $lockedSite->id,
                eventType: 'site.recovered',
                title: 'Sitio recuperado',
                severity: 'info',
                description: 'El sitio respondió de forma estable y se resolvieron alertas activas.',
                metadata: [
                    'resolved_alerts' => $openAlerts->count(),
                ],
            );

            if (function_exists('activity')) {
                activity('monitoring')
                    ->performedOn($lockedSite)
                    ->withProperties([
                        'action' => 'site.alerts.auto_resolved',
                        'resolved_alerts' => $openAlerts->count(),
                    ])
                    ->log('Resolucion automatica de alertas tras recuperacion');
            }

            SiteRecovered::dispatch(
                siteId: (int) $lockedSite->id,
                siteName: $lockedSite->name,
                url: $lockedSite->url,
                recoveredAt: now()->toIso8601String(),
            );
        }

        return $nextStatus;
    }

    private function statusLabel(string $statusCode): string
    {
        return match ($statusCode) {
            'up' => 'ACTIVO',
            'degraded' => 'DEGRADADO',
            'down' => 'CAÍDO',
            default => 'DESCONOCIDO',
        };
    }
}
