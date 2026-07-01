<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Contracts\Repositories\SiteGroupRepositoryInterface;
use App\Models\SiteGroup;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SiteGroupController extends Controller
{
    public function __construct(private readonly SiteGroupRepositoryInterface $siteGroupRepository)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->siteGroupRepository->withMonitoredSiteCount(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:site_groups,slug'],
            'description' => ['nullable', 'string'],
            'responsible_name' => ['nullable', 'string', 'max:255'],
            'responsible_email' => ['nullable', 'email', 'max:255'],
            'color' => ['nullable', 'string', 'size:7', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'parent_id' => ['prohibited'],
        ]);

        $group = $this->siteGroupRepository->create($validated);

        if (function_exists('activity')) {
            activity()
                ->performedOn($group)
                ->withProperties(['action' => 'site_group.created'])
                ->log('Grupo de monitoreo creado');
        }

        return response()->json(['data' => $group], 201);
    }

    public function show(SiteGroup $siteGroup): JsonResponse
    {
        return response()->json(['data' => $siteGroup->loadCount('sites')]);
    }

    public function update(Request $request, SiteGroup $siteGroup): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:100', 'alpha_dash', Rule::unique('site_groups', 'slug')->ignore($siteGroup->id)],
            'description' => ['sometimes', 'nullable', 'string'],
            'responsible_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'responsible_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'color' => ['sometimes', 'nullable', 'string', 'size:7', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'parent_id' => ['prohibited'],
        ]);

        $this->siteGroupRepository->update($siteGroup, $validated);

        if (function_exists('activity')) {
            activity()
                ->performedOn($siteGroup)
                ->withProperties(['action' => 'site_group.updated'])
                ->log('Grupo de monitoreo actualizado');
        }

        return response()->json(['data' => $siteGroup->fresh()]);
    }

    public function destroy(SiteGroup $siteGroup): JsonResponse
    {
        $siteCount = $siteGroup->sites()->count();

        if ($siteCount > 0) {
            return response()->json([
                'message' => 'No se puede eliminar un grupo con sitios asociados.',
            ], 422);
        }

        $this->siteGroupRepository->delete($siteGroup);

        if (function_exists('activity')) {
            activity()
                ->withProperties(['action' => 'site_group.deleted', 'site_group_id' => $siteGroup->id])
                ->log('Grupo de monitoreo eliminado');
        }

        return response()->json(status: 204);
    }
}
