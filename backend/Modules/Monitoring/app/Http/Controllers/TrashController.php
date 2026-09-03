<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TrashController extends Controller
{
    public function index(): Response
    {
        $sites = Site::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->limit(200)
            ->get(['id', 'name', 'domain', 'deleted_at'])
            ->map(static fn (Site $site): array => [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
                'domain' => (string) $site->domain,
                'deleted_at' => optional($site->deleted_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        $users = User::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->limit(200)
            ->get(['id', 'name', 'email', 'department', 'deleted_at'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'department' => $user->department,
                'deleted_at' => optional($user->deleted_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Trash', [
            'sites' => $sites,
            'users' => $users,
        ]);
    }

    public function restoreSite(Request $request, int $siteId): JsonResponse
    {
        $site = Site::onlyTrashed()->findOrFail($siteId);
        $site->restore();

        activity()->causedBy($request->user())->performedOn($site)
            ->withProperties(['domain' => $site->domain])
            ->log('Restauró el sitio '.$site->domain.' desde la papelera');

        return response()->json(['message' => 'Sitio restaurado.']);
    }

    public function restoreUser(Request $request, int $userId): JsonResponse
    {
        $user = User::onlyTrashed()->findOrFail($userId);
        $user->restore();

        activity()->causedBy($request->user())->performedOn($user)
            ->log('Restauró la cuenta de '.$user->name.' desde la papelera');

        return response()->json(['message' => 'Usuario restaurado.']);
    }
}
