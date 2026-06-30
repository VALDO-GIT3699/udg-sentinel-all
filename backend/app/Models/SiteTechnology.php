<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SiteTechnology extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'technology_id',
        'version',
        'confidence_pct',
        'is_primary',
        'detected_at',
        'detection_method',
        'metadata',
    ];

    protected $casts = [
        'confidence_pct'   => 'integer',
        'is_primary'       => 'boolean',
        'detected_at'      => 'immutable_datetime',
        'metadata'         => 'array',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class);
    }
}
