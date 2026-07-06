<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventoryReconciliationRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'site_id',
        'row_number',
        'match_kind',
        'match_score',
        'normalized_domain',
        'normalized_name',
        'normalized_cms',
        'normalized_ip',
        'source_active',
        'needs_review',
        'source_payload',
        'evidence',
        'proposed_changes',
        'notes',
        'matched_at',
        'reviewed_at',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'match_score' => 'integer',
        'source_active' => 'boolean',
        'needs_review' => 'boolean',
        'source_payload' => 'array',
        'evidence' => 'array',
        'proposed_changes' => 'array',
        'notes' => 'array',
        'matched_at' => 'immutable_datetime',
        'reviewed_at' => 'immutable_datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryReconciliationBatch::class, 'batch_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Site::class);
    }
}
