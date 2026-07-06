<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Reconciliation;

use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\InventoryReconciliationBatch;
use Modules\Inventory\Models\InventoryReconciliationRow;

final class InventoryReconciliationService
{
    private InventorySourceParser $parser;
    private InventoryReconciler $reconciler;

    public function __construct(?InventorySourceParser $parser = null, ?InventoryReconciler $reconciler = null)
    {
        $this->parser = $parser ?? InventorySourceParser::default();
        $this->reconciler = $reconciler ?? new InventoryReconciler();
    }

    public function importAndAnalyze(UploadedFile $file, ?int $uploadedBy = null): InventoryReconciliationBatch
    {
        $storedPath = $file->store('inventory-reconciliation');
        $absolutePath = Storage::path($storedPath);
        $sourceRows = $this->parser->parse($absolutePath);

        $batch = InventoryReconciliationBatch::query()->create([
            'uploaded_by' => $uploadedBy,
            'source_name' => $file->getClientOriginalName(),
            'source_type' => strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)),
            'source_hash' => hash_file('sha256', $absolutePath),
            'status' => 'analyzed',
            'total_rows' => 0,
            'exact_matches' => 0,
            'probable_matches' => 0,
            'new_rows' => 0,
            'obsolete_sites' => 0,
            'conflicts' => 0,
            'summary' => [],
            'analyzed_at' => now(),
        ]);

        $summary = [
            'rows' => [],
            'site_keys' => [],
            'matches' => [
                'exact' => 0,
                'probable' => 0,
                'new' => 0,
                'conflict' => 0,
            ],
        ];

        foreach ($sourceRows as $index => $sourceRow) {
            $reconciliation = $this->reconciler->reconcile($sourceRow);

            InventoryReconciliationRow::query()->create([
                'batch_id' => $batch->id,
                'site_id' => $reconciliation['site_id'],
                'row_number' => $index + 1,
                'match_kind' => $reconciliation['match_kind'],
                'match_score' => $reconciliation['match_score'],
                'normalized_domain' => $reconciliation['normalized_domain'],
                'normalized_name' => $reconciliation['normalized_name'],
                'normalized_cms' => $reconciliation['normalized_cms'],
                'normalized_ip' => $reconciliation['normalized_ip'],
                'source_active' => $reconciliation['source_active'],
                'needs_review' => $reconciliation['needs_review'],
                'source_payload' => $sourceRow,
                'evidence' => $reconciliation['evidence'],
                'proposed_changes' => $reconciliation['proposed_changes'],
                'notes' => [
                    'clasificacion' => $sourceRow['clasificacion'] ?? $sourceRow['classification'] ?? null,
                    'entidad' => $sourceRow['entidad'] ?? null,
                    'estatus_proyecto' => $sourceRow['estatus_proyecto'] ?? null,
                ],
                'matched_at' => now(),
            ]);

            $summary['rows'][] = $sourceRow;
            if (($reconciliation['normalized_domain'] ?? null) !== null) {
                $summary['site_keys'][] = $reconciliation['normalized_domain'];
            }

            $summary['matches'][$reconciliation['match_kind']] = ($summary['matches'][$reconciliation['match_kind']] ?? 0) + 1;
        }

        $currentSourceKeys = array_values(array_unique(array_filter($summary['site_keys'])));
        $obsoleteSites = 0;
        if ($currentSourceKeys !== []) {
            $obsoleteSites = Site::query()
                ->whereNotIn('domain', $currentSourceKeys)
                ->where('is_active', true)
                ->count();
        }

        $batch->update([
            'total_rows' => count($sourceRows),
            'exact_matches' => $summary['matches']['exact'],
            'probable_matches' => $summary['matches']['probable'],
            'new_rows' => $summary['matches']['new'],
            'obsolete_sites' => $obsoleteSites,
            'conflicts' => $summary['matches']['conflict'],
            'summary' => [
                'source_file' => $file->getClientOriginalName(),
                'current_source_keys' => $currentSourceKeys,
                'match_totals' => $summary['matches'],
            ],
        ]);

        return $batch->load(['rows', 'uploader']);
    }

    public function getRecentBatches(int $limit = 10)
    {
        return InventoryReconciliationBatch::query()
            ->with(['rows', 'uploader'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
