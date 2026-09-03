<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'http_code' => 'integer',
        'first_detected_at' => 'immutable_datetime',
        'last_checked_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'is_resolved' => 'boolean',
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
     * @param  Builder<BrokenLink>  $query
     * @return Builder<BrokenLink>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('is_resolved', false);
    }

    /**
     * @param  Builder<BrokenLink>  $query
     * @return Builder<BrokenLink>
     */
    public function scopeNotFound(Builder $query): Builder
    {
        return $query->where('http_code', 404);
    }
}
