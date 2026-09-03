<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Models\NotificationSent;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationSettingsController extends Controller
{
    public function index(): Response
    {
        $channels = NotificationChannel::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (NotificationChannel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type,
                'is_active' => (bool) $channel->is_active,
                // El destino se muestra, pero nunca la URL completa de un
                // webhook (podria ser un secreto de un solo uso) — solo el
                // correo, que ya es de por si el dato menos sensible.
                'destination' => $channel->type === 'email' ? (string) ($channel->config['to'] ?? '') : null,
            ])
            ->values()
            ->all();

        $recentDeliveries = NotificationSent::query()
            ->with(['channel:id,name,type', 'alert:id,title,severity'])
            ->orderByDesc('sent_at')
            ->limit(20)
            ->get()
            ->map(static fn (NotificationSent $sent): array => [
                'id' => $sent->id,
                'channel_name' => $sent->channel?->name ?? 'Canal eliminado',
                'alert_title' => $sent->alert?->title ?? 'Alerta eliminada',
                'status' => $sent->status,
                'error_message' => $sent->error_message,
                'sent_at' => optional($sent->sent_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Notifications', [
            'globalEnabled' => (bool) Setting::get('monitoring.notifications_enabled', false),
            'channels' => $channels,
            'recentDeliveries' => $recentDeliveries,
        ]);
    }

    public function updateGlobal(Request $request): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        Setting::set('monitoring.notifications_enabled', (bool) $validated['enabled']);

        activity()->causedBy($request->user())
            ->log(
                $validated['enabled']
                    ? 'Activó el envío de notificaciones externas de incidentes'
                    : 'Desactivó el envío de notificaciones externas de incidentes',
            );

        return response()->json(['enabled' => (bool) $validated['enabled']]);
    }

    public function storeChannel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['email', 'slack', 'webhook'])],
            'destination' => ['required', 'string', 'max:500'],
        ]);

        $config = $validated['type'] === 'email'
            ? ['to' => $validated['destination']]
            : ['webhook_url' => $validated['destination']];

        $channel = NotificationChannel::query()->create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'config' => $config,
            'is_active' => true,
        ]);

        activity()->causedBy($request->user())->performedOn($channel)
            ->log('Agregó el canal de notificación "'.$channel->name.'"');

        return response()->json(['id' => $channel->id, 'message' => 'Canal creado.'], 201);
    }

    public function toggleChannel(Request $request, NotificationChannel $channel): JsonResponse
    {
        $channel->update(['is_active' => ! $channel->is_active]);

        activity()->causedBy($request->user())->performedOn($channel)
            ->log(($channel->is_active ? 'Activó' : 'Desactivó').' el canal de notificación "'.$channel->name.'"');

        return response()->json(['is_active' => $channel->is_active]);
    }

    public function destroyChannel(Request $request, NotificationChannel $channel): JsonResponse
    {
        $name = $channel->name;
        $channel->delete();

        activity()->causedBy($request->user())
            ->log('Eliminó el canal de notificación "'.$name.'"');

        return response()->json(['message' => 'Canal eliminado.']);
    }
}
