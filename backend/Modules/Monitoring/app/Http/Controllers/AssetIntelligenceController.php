<?php

declare(strict_types=1);

namespace Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssetClassification;
use App\Models\Site;
use App\Support\AssetIntelligenceSchema;
use Inertia\Inertia;
use Inertia\Response;

final class AssetIntelligenceController extends Controller
{
    public function __construct(private readonly AssetIntelligenceSchema $assetSchema)
    {
    }

    public function index(): Response
    {
        if (! $this->assetSchema->isReady()) {
            return Inertia::render('AssetIntelligence/Index', [
                'enabled' => false,
                'metrics' => [],
                'typeDistribution' => [],
                'roleDistribution' => [],
                'reviewQueue' => [],
                'recentChanges' => [],
                'updatedAt' => now()->toIso8601String(),
            ]);
        }

        $total = (int) Site::query()->where('is_active', true)->count();
        $classified = (int) Site::query()
            ->where('is_active', true)
            ->where('asset_type', '!=', 'unknown')
            ->count();
        $unknown = (int) Site::query()
            ->where('is_active', true)
            ->where('asset_type', 'unknown')
            ->count();
        $manualOverrides = (int) Site::query()
            ->where('is_active', true)
            ->where('asset_classification_source', 'manual')
            ->count();

        $avgConfidence = round((float) Site::query()
            ->where('is_active', true)
            ->avg('asset_confidence_pct'), 2);

        $typeDistribution = Site::query()
            ->where('is_active', true)
            ->selectRaw("COALESCE(asset_type, 'unknown') as key, COUNT(*) as total")
            ->groupBy('key')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(static fn ($row): array => ['key' => (string) $row->key, 'total' => (int) $row->total])
            ->values()
            ->all();

        $roleDistribution = Site::query()
            ->where('is_active', true)
            ->selectRaw("COALESCE(asset_role, 'unknown') as key, COUNT(*) as total")
            ->groupBy('key')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(static fn ($row): array => ['key' => (string) $row->key, 'total' => (int) $row->total])
            ->values()
            ->all();

        $reviewQueue = Site::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('asset_type', 'unknown')
                    ->orWhere('asset_confidence_pct', '<', 60)
                    ->orWhere(function ($nested): void {
                        $nested->where('asset_classification_source', 'automatic')
                            ->where('asset_confidence_pct', '<=', 70);
                    });
            })
            ->orderBy('asset_confidence_pct')
            ->limit(200)
            ->get(['id', 'name', 'domain', 'asset_type', 'asset_role', 'asset_confidence_pct', 'asset_classification_source'])
            ->map(static fn (Site $site): array => [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
                'domain' => (string) $site->domain,
                'asset_type' => (string) ($site->asset_type ?? 'unknown'),
                'asset_role' => (string) ($site->asset_role ?? 'unknown'),
                'confidence_pct' => (int) ($site->asset_confidence_pct ?? 0),
                'source' => (string) ($site->asset_classification_source ?? 'none'),
            ])
            ->values()
            ->all();

        $recentChanges = AssetClassification::query()
            ->with('site:id,name,domain')
            ->orderByDesc('classified_at')
            ->limit(100)
            ->get()
            ->map(static fn (AssetClassification $item): array => [
                'site_id' => (int) $item->site_id,
                'site_name' => (string) ($item->site?->name ?? 'N/A'),
                'domain' => (string) ($item->site?->domain ?? ''),
                'asset_type' => (string) $item->asset_type,
                'asset_role' => (string) $item->asset_role,
                'confidence_pct' => (int) $item->confidence_pct,
                'source' => (string) $item->source,
                'classified_at' => optional($item->classified_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('AssetIntelligence/Index', [
            'enabled' => true,
            'metrics' => [
                'total_assets' => $total,
                'classified_assets' => $classified,
                'classified_pct' => $total > 0 ? round(($classified / $total) * 100, 2) : 0.0,
                'unknown_assets' => $unknown,
                'unknown_pct' => $total > 0 ? round(($unknown / $total) * 100, 2) : 0.0,
                'avg_confidence' => $avgConfidence,
                'manual_overrides' => $manualOverrides,
            ],
            'typeDistribution' => $typeDistribution,
            'roleDistribution' => $roleDistribution,
            'reviewQueue' => $reviewQueue,
            'recentChanges' => $recentChanges,
            'updatedAt' => now()->toIso8601String(),
        ]);
    }
}
