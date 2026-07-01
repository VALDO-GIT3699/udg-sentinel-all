<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SiteDownNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $siteName,
        private readonly string $siteUrl,
        private readonly string $previousStatus,
        private readonly string $currentStatus,
        private readonly string $severity,
        private readonly string $detectedAtIso,
        private readonly int $alertId,
        private readonly string $incidentMessage,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'log'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(sprintf('[UDG Sentinel][%s] Incidente critico en %s', mb_strtoupper($this->severity), $this->siteName))
            ->view('emails.monitoring.site-down-notification', [
                'siteName' => $this->siteName,
                'siteUrl' => $this->siteUrl,
                'previousStatus' => $this->previousStatus,
                'currentStatus' => $this->currentStatus,
                'severity' => $this->severity,
                'detectedAtIso' => $this->detectedAtIso,
                'alertId' => $this->alertId,
                'incidentMessage' => $this->incidentMessage,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'alert_id' => $this->alertId,
            'site_name' => $this->siteName,
            'site_url' => $this->siteUrl,
            'previous_status' => $this->previousStatus,
            'current_status' => $this->currentStatus,
            'severity' => $this->severity,
            'detected_at' => $this->detectedAtIso,
            'message' => $this->incidentMessage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLog(object $notifiable): array
    {
        return [
            'channel' => 'monitoring',
            'type' => 'site_down_notification',
            ...$this->toArray($notifiable),
        ];
    }
}
