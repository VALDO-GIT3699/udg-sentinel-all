<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Support\AssetIntelligenceSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Inventory\Services\Classification\AssetClassificationService;

final class SiteController extends Controller
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly AssetClassificationService $assetClassificationService,
        private readonly AssetIntelligenceSchema $assetSchema,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString(),
            'group_id' => $request->integer('group_id') ?: null,
            'search' => $request->string('search')->toString(),
            'priority' => $request->integer('priority') ?: null,
            'asset_type' => $request->string('asset_type')->toString(),
            'asset_role' => $request->string('asset_role')->toString(),
            'classification_mode' => $request->string('classification_mode')->toString(),
            'confidence_min' => $request->integer('confidence_min') ?: null,
            'confidence_max' => $request->integer('confidence_max') ?: null,
        ];

        return response()->json([
            'data' => $this->siteRepository->paginate(
                perPage: max(1, min(100, $request->integer('per_page', 20))),
                filters: array_filter($filters, static fn ($value): bool => $value !== null && $value !== '')
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_group_id' => ['required', 'integer', 'exists:site_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:sites,slug'],
            'domain' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'is_monitored' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', Rule::in([1, 2, 3])],
            'check_interval_min' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:60'],
        ]);

        $site = $this->siteRepository->create($validated);

        if (function_exists('activity')) {
            activity()
                ->performedOn($site)
                ->withProperties(['action' => 'site.created'])
                ->log('Sitio monitoreado creado');
        }

        return response()->json(['data' => $site], 201);
    }

    public function show(Site $site): JsonResponse
    {
        $record = $this->siteRepository->findById($site->id);

        if ($record === null) {
            return response()->json(['message' => 'Sitio no encontrado.'], 404);
        }

        return response()->json(['data' => $record]);
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        $validated = $request->validate([
            'site_group_id' => ['sometimes', 'required', 'integer', 'exists:site_groups,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:100', 'alpha_dash', Rule::unique('sites', 'slug')->ignore($site->id)],
            'domain' => ['sometimes', 'required', 'string', 'max:255'],
            'url' => ['sometimes', 'required', 'url', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'is_monitored' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', Rule::in([1, 2, 3])],
            'check_interval_min' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:60'],
        ]);

        $this->siteRepository->update($site, $validated);

        if (function_exists('activity')) {
            activity()
                ->performedOn($site)
                ->withProperties(['action' => 'site.updated'])
                ->log('Sitio monitoreado actualizado');
        }

        return response()->json(['data' => $site->fresh()]);
    }

    public function destroy(Site $site): JsonResponse
    {
        $this->siteRepository->delete($site);

        if (function_exists('activity')) {
            activity()
                ->withProperties(['action' => 'site.deleted', 'site_id' => $site->id])
                ->log('Sitio monitoreado eliminado');
        }

        return response()->json(status: 204);
    }

    public function setManualClassification(Request $request, Site $site): JsonResponse
    {
        if (! $this->assetSchema->isReady()) {
            return response()->json([
                'message' => 'Asset Intelligence aun no esta disponible en la base de datos. Ejecuta migraciones para habilitarlo.',
            ], 409);
        }

        $validated = $request->validate([
            'asset_type' => ['required', 'string', 'max:60'],
            'asset_role' => ['required', 'string', 'max:80'],
            'confidence_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $result = $this->assetClassificationService->setManualClassification(
            $site,
            $validated,
            $request->user()?->id,
        );

        if (function_exists('activity')) {
            activity()
                ->performedOn($site)
                ->withProperties([
                    'action' => 'site.asset_classification.manual',
                    'asset_type' => $result->assetType,
                    'asset_role' => $result->assetRole,
                    'confidence_pct' => $result->confidencePct,
                ])
                ->log('Clasificacion manual de activo aplicada');
        }

        return response()->json([
            'message' => 'Clasificacion manual aplicada y bloqueada para sobrescritura automatica.',
            'data' => $site->fresh(),
        ]);
    }

    public function approveAutomaticClassification(Request $request, Site $site): JsonResponse
    {
        if (! $this->assetSchema->isReady()) {
            return response()->json([
                'message' => 'Asset Intelligence aun no esta disponible en la base de datos. Ejecuta migraciones para habilitarlo.',
            ], 409);
        }

        $assetType = (string) ($site->asset_type ?? 'unknown');
        $assetRole = (string) ($site->asset_role ?? 'unknown');

        if ($assetType === 'unknown' && $assetRole === 'unknown') {
            return response()->json([
                'message' => 'No existe una clasificacion sugerida para aprobar en este activo.',
            ], 422);
        }

        $confidence = (int) ($site->asset_confidence_pct ?? 0);

        $this->assetClassificationService->setManualClassification(
            $site,
            [
                'asset_type' => $assetType,
                'asset_role' => $assetRole,
                'confidence_pct' => $confidence,
                'notes' => 'Aprobacion rapida desde cola de revision.',
            ],
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Clasificacion aprobada y bloqueada como manual.',
            'data' => $site->fresh(),
        ]);
    }
}
