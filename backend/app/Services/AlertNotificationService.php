<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Alert;
use App\Models\NotificationChannel;
use App\Models\NotificationSent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class AlertNotificationService
{
    /**
     * @param array<string, mixed> $extra
     */
    public function dispatch(Alert $alert, array $extra = []): void
    {
        $siteName = (string) optional($alert->site)->name;
        $siteUrl = (string) optional($alert->site)->url;

        Log::warning('Alerta de monitoreo activada', [
            'alert_id' => $alert->id,
            'site_id' => $alert->site_id,
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'severity' => $alert->severity,
            'title' => $alert->title,
            'context' => $alert->context,
            'extra' => $extra,
        ]);

        $channels = NotificationChannel::query()
            ->active()
            ->orderBy('id')
            ->get();

        foreach ($channels as $channel) {
            $this->sendToChannel($alert, $channel, $siteName, $siteUrl, $extra);
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function sendToChannel(
        Alert $alert,
        NotificationChannel $channel,
        string $siteName,
        string $siteUrl,
        array $extra
    ): void {
        $type = mb_strtolower((string) $channel->type);

        try {
            if ($type === 'email') {
                $this->sendEmail($alert, $channel, $siteName, $siteUrl, $extra);
            } elseif ($type === 'slack' || $type === 'webhook') {
                $this->sendWebhook($alert, $channel, $siteName, $siteUrl, $extra);
            } else {
                $this->trackNotification($alert, $channel, 'failed', 'Tipo de canal no soportado: ' . $type);
            }
        } catch (\Throwable $exception) {
            $this->trackNotification(
                $alert,
                $channel,
                'failed',
                mb_substr($exception->getMessage(), 0, 1000)
            );
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function sendEmail(
        Alert $alert,
        NotificationChannel $channel,
        string $siteName,
        string $siteUrl,
        array $extra
    ): void {
        $config = $channel->config;
        $to = (string) ($config['to'] ?? env('MONITORING_ALERT_EMAIL_TO', ''));

        if ($to === '') {
            $this->trackNotification($alert, $channel, 'failed', 'Canal email sin destinatario configurado.');
            return;
        }

        $subject = sprintf('[UDG Sentinel][%s] %s', mb_strtoupper((string) $alert->severity), (string) $alert->title);
        $body = $this->buildMessageBody($alert, $siteName, $siteUrl, $extra);

        Mail::raw($body, static function ($message) use ($to, $subject): void {
            $message->to($to)->subject($subject);
        });

        $this->trackNotification($alert, $channel, 'sent', null);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function sendWebhook(
        Alert $alert,
        NotificationChannel $channel,
        string $siteName,
        string $siteUrl,
        array $extra
    ): void {
        $config = $channel->config;
        $webhookUrl = (string) ($config['webhook_url'] ?? env('MONITORING_ALERT_SLACK_WEBHOOK', ''));

        if ($webhookUrl === '') {
            $this->trackNotification($alert, $channel, 'failed', 'Canal webhook/slack sin URL configurada.');
            return;
        }

        $payload = [
            'text' => sprintf('[UDG Sentinel][%s] %s', mb_strtoupper((string) $alert->severity), (string) $alert->title),
            'attachments' => [[
                'color' => $this->slackColor((string) $alert->severity),
                'fields' => [
                    ['title' => 'Sitio', 'value' => $siteName !== '' ? $siteName : 'No disponible', 'short' => true],
                    ['title' => 'URL', 'value' => $siteUrl !== '' ? $siteUrl : 'No disponible', 'short' => true],
                    ['title' => 'Mensaje', 'value' => (string) ($alert->message ?? 'Sin detalle adicional'), 'short' => false],
                ],
                'footer' => 'UDG Sentinel',
            ]],
            'metadata' => [
                'alert_id' => $alert->id,
                'context' => $alert->context,
                'extra' => $extra,
            ],
        ];

        $response = Http::timeout(8)->post($webhookUrl, $payload);

        if ($response->failed()) {
            $this->trackNotification(
                $alert,
                $channel,
                'failed',
                'Webhook respondio HTTP ' . $response->status()
            );
            return;
        }

        $this->trackNotification($alert, $channel, 'sent', null);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function buildMessageBody(Alert $alert, string $siteName, string $siteUrl, array $extra): string
    {
        return implode("\n", [
            'UDG Sentinel - Alerta de monitoreo',
            '-----------------------------------',
            'Alerta: ' . (string) $alert->title,
            'Severidad: ' . (string) $alert->severity,
            'Sitio: ' . ($siteName !== '' ? $siteName : 'No disponible'),
            'URL: ' . ($siteUrl !== '' ? $siteUrl : 'No disponible'),
            'Mensaje: ' . (string) ($alert->message ?? 'Sin detalle adicional'),
            'Contexto: ' . json_encode($alert->context ?? [], JSON_UNESCAPED_UNICODE),
            'Extra: ' . json_encode($extra, JSON_UNESCAPED_UNICODE),
            'Disparada: ' . optional($alert->triggered_at)->toIso8601String(),
        ]);
    }

    private function slackColor(string $severity): string
    {
        return match ($severity) {
            'critical' => '#dc2626',
            'high' => '#f97316',
            'medium' => '#eab308',
            default => '#0ea5e9',
        };
    }

    private function trackNotification(Alert $alert, NotificationChannel $channel, string $status, ?string $error): void
    {
        NotificationSent::query()->create([
            'alert_id' => (int) $alert->id,
            'channel_id' => (int) $channel->id,
            'status' => $status,
            'sent_at' => now(),
            'error_message' => $error,
        ]);
    }
}
