<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetClassification extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'source',
        'asset_type',
        'asset_role',
        'confidence_pct',
        'evidence',
        'scores',
        'classifier_version',
        'rule_engine_version',
        'result_hash',
        'rules_used',
        'observations',
        'recommendations',
        'is_current',
        'created_by',
        'notes',
        'classified_at',
    ];

    protected $casts = [
        'confidence_pct' => 'integer',
        'evidence' => 'array',
        'scores' => 'array',
        'rules_used' => 'array',
        'observations' => 'array',
        'recommendations' => 'array',
        'is_current' => 'boolean',
        'classified_at' => 'immutable_datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
