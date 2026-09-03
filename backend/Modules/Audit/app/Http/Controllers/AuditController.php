<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        $causerId = $request->integer('causer_id') ?: null;
        $from = trim((string) $request->string('from'));
        $to = trim((string) $request->string('to'));

        // "ilike" es exclusivo de Postgres (produccion); en cualquier otro
        // driver (p. ej. sqlite en tests) es un error de sintaxis. LOWER()+LIKE
        // funciona igual de bien en ambos y evita duplicar la condicion.
        $caseInsensitiveLike = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $entries = Activity::query()
            ->with('causer:id,name,email')
            // El registro de auditoria es para acciones de administracion (quien
            // hizo que), no para telemetria automatica. Las transiciones de
            // estado y resoluciones de alerta se registran aparte con
            // log_name 'monitoring' -miles de filas por dia- y ahogaban por
            // completo las acciones reales si se mostraban aqui.
            ->where('log_name', '!=', 'monitoring')
            ->when($search !== '', function ($query) use ($search, $caseInsensitiveLike): void {
                $query->where('description', $caseInsensitiveLike, '%'.$search.'%');
            })
            ->when($causerId !== null, fn ($query) => $query->where('causer_id', $causerId))
            ->when($from !== '', fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(static function (Activity $activity): array {
                return [
                    'id' => $activity->id,
                    'description' => $activity->description,
                    'causer' => $activity->causer instanceof User ? $activity->causer->name : 'Sistema',
                    'subject_type' => $activity->subject_type !== null ? class_basename($activity->subject_type) : null,
                    'subject_id' => $activity->subject_id,
                    'properties' => $activity->properties?->toArray() ?? [],
                    'created_at' => optional($activity->created_at)?->toIso8601String(),
                ];
            });

        return Inertia::render('Audit/Index', [
            'entries' => $entries,
            'filters' => [
                'search' => $search,
                'causer_id' => $causerId,
                'from' => $from,
                'to' => $to,
            ],
            'causers' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
