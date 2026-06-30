<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BrokenLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'url',
        'found_on',
        'http_code',
        'first_detected_at',
        'last_checked_at',
        'is_resolved',
        'resolved_at',
    ];

    protected $casts = [
        'http_code'         => 'integer',
        'first_detected_at' => 'immutable_datetime',
        'last_checked_at'   => 'immutable_datetime',
        'resolved_at'       => 'immutable_datetime',
        'is_resolved'       => 'boolean',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param \Illuminate\Database\Eloquent\Builder<BrokenLink> $query
     * @return \Illuminate\Database\Eloquent\Builder<BrokenLink>
     */
    public function scopeUnresolved(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_resolved', false);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<BrokenLink> $query
     * @return \Illuminate\Database\Eloquent\Builder<BrokenLink>
     */
    public function scopeNotFound(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('http_code', 404);
    }
}
