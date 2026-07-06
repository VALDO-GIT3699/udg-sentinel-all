<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Models\InventoryReconciliationRow;

final class InventoryReconciliationBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'uploaded_by',
        'source_name',
        'source_type',
        'source_hash',
        'status',
        'total_rows',
        'exact_matches',
        'probable_matches',
        'new_rows',
        'obsolete_sites',
        'conflicts',
        'summary',
        'analyzed_at',
        'reviewed_at',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'exact_matches' => 'integer',
        'probable_matches' => 'integer',
        'new_rows' => 'integer',
        'obsolete_sites' => 'integer',
        'conflicts' => 'integer',
        'summary' => 'array',
        'analyzed_at' => 'immutable_datetime',
        'reviewed_at' => 'immutable_datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(InventoryReconciliationRow::class, 'batch_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'uploaded_by');
    }
}
