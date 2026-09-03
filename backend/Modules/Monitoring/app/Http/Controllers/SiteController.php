<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteGroup;
use App\Support\AssetIntelligenceSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Inventory\Services\Classification\AssetClassificationService;

final class SiteController extends Controller
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly AssetClassificationService $assetClassificationService,
        private readonly AssetIntelligenceSchema $assetSchema,
    ) {}

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
                filters: array_filter($filters, static fn ($value): bool => $value !== null && $value !== ''),
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

    public function registerMonitoredSite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url:http,https'],
            'clave' => ['required', 'string', 'regex:/^\d{10}$/'],
        ], [
            'url.url' => 'La URL proporcionada no es válida.',
            'clave.regex' => 'La clave debe tener el formato ddmmaaaaHH (10 dígitos).',
        ]);

        if (! $this->isRegistrationKeyValid($validated['clave'])) {
            return response()->json([
                'message' => 'Clave inválida o expirada. Usa el código vigente (fecha y hora actual, formato ddmmaaaaHH, hora de Guadalajara).',
            ], 422);
        }

        $url = trim($validated['url']);
        $host = parse_url($url, PHP_URL_HOST);
        $domain = $host !== null ? mb_strtolower((string) $host) : '';

        if ($domain === '') {
            return response()->json(['message' => 'La URL proporcionada no es válida.'], 422);
        }

        if ($this->hostPointsToPrivateNetwork($domain)) {
            return response()->json([
                'message' => 'La URL apunta a una red interna o no permitida y no puede registrarse.',
            ], 422);
        }

        if ($this->siteRepository->findByDomain($domain) !== null) {
            return response()->json([
                'message' => 'Ya existe un sitio registrado con ese dominio.',
            ], 422);
        }

        $siteGroup = SiteGroup::query()->firstOrCreate(
            ['slug' => 'altas-manuales'],
            [
                'name' => 'Altas manuales',
                'description' => 'Sitios registrados manualmente desde el dashboard.',
                'color' => '#0EA5E9',
            ],
        );

        $site = $this->siteRepository->create([
            'site_group_id' => $siteGroup->id,
            'name' => $domain,
            'slug' => $this->buildUniqueSiteSlug($domain),
            'domain' => $domain,
            'url' => $url,
        ]);

        if (function_exists('activity')) {
            activity()
                ->performedOn($site)
                ->causedBy($request->user())
                ->withProperties(['action' => 'site.registered_manual', 'url' => $url])
                ->log('Sitio registrado manualmente desde el dashboard');
        }

        return response()->json([
            'message' => 'Sitio registrado correctamente.',
            'data' => $site,
        ], 201);
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

    /**
     * Eliminar (soft delete) un sitio exige la misma clave ddmmaaaaHH que se
     * usa para registrar uno nuevo -reutilizando isRegistrationKeyValid()-,
     * ademas del permiso dedicado monitoring.delete_sites. Es una accion
     * destructiva e irreversible desde la UI, asi que no basta con el permiso:
     * tambien hay que demostrar que se está viendo el reloj en este momento.
     */
    public function destroy(Request $request, Site $site): JsonResponse
    {
        $validated = $request->validate([
            'clave' => ['required', 'string', 'regex:/^\d{10}$/'],
        ], [
            'clave.regex' => 'La clave debe tener el formato ddmmaaaaHH (10 dígitos).',
        ]);

        if (! $this->isRegistrationKeyValid($validated['clave'])) {
            return response()->json([
                'message' => 'Clave inválida o expirada. Usa el código vigente (fecha y hora actual, formato ddmmaaaaHH, hora de Guadalajara).',
            ], 422);
        }

        $domain = $site->domain;
        $siteId = $site->id;

        $this->siteRepository->delete($site);

        if (function_exists('activity')) {
            activity()
                ->causedBy($request->user())
                ->withProperties(['action' => 'site.deleted', 'site_id' => $siteId, 'domain' => $domain])
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

    public function updateLifecycleStatus(Request $request, Site $site): JsonResponse
    {
        // No se restringe a Site::LIFECYCLE_STATUSES: el frontend permite capturar un
        // estatus personalizado (opcion "Otro") como texto libre.
        $validated = $request->validate([
            'lifecycle_status' => ['required', 'string', 'max:120'],
            'elimination_ticket' => ['nullable', 'string', 'max:120'],
        ]);

        $lifecycleStatus = trim((string) $validated['lifecycle_status']);
        $eliminationTicket = trim((string) ($validated['elimination_ticket'] ?? ''));

        if ($lifecycleStatus === 'Eliminado' && $eliminationTicket === '') {
            return response()->json([
                'message' => 'El ticket es obligatorio para marcar un sitio como Eliminado.',
            ], 422);
        }

        $site->lifecycle_status = $lifecycleStatus;
        $site->elimination_ticket = $lifecycleStatus === 'Eliminado' ? $eliminationTicket : null;
        $site->save();

        return response()->json([
            'message' => 'Estatus actualizado correctamente.',
            'data' => $site->fresh(),
        ]);
    }

    /**
     * The registration key is the current moment expressed as ddmmyyyyHH
     * (day, month, 4-digit year, 24h hour) in the Guadalajara timezone.
     * A one-hour grace window is accepted so a code typed right before the
     * hour rolls over still works.
     */
    private function isRegistrationKeyValid(string $clave): bool
    {
        $now = now('America/Mexico_City');

        foreach ([0, -1] as $hourOffset) {
            $expected = $now->copy()->addHours($hourOffset)->format('dmYH');

            if (hash_equals($expected, $clave)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Basic SSRF guard: this endpoint lets any authorized user submit an
     * arbitrary URL that monitoring jobs will later fetch from the server,
     * so hosts resolving to loopback/private/link-local ranges are rejected.
     */
    private function hostPointsToPrivateNetwork(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        $resolvedIps = @gethostbynamel($host);

        if ($resolvedIps === false || $resolvedIps === []) {
            return false;
        }

        foreach ($resolvedIps as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }

    private function buildUniqueSiteSlug(string $domain): string
    {
        $base = Str::slug($domain);

        if ($base === '') {
            $base = 'sitio';
        }

        $slug = $base;
        $suffix = 2;

        while (Site::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
