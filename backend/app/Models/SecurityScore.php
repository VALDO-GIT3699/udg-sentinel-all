<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SecurityScore extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'score',
        'level',
        'calculated_at',
        'breakdown',
        'recommendations',
    ];

    protected $casts = [
        'score'           => 'integer',
        'calculated_at'   => 'immutable_datetime',
        'created_at'      => 'immutable_datetime',
        'breakdown'       => 'array',
        'recommendations' => 'array',
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
     * @param \Illuminate\Database\Eloquent\Builder<SecurityScore> $query
     * @return \Illuminate\Database\Eloquent\Builder<SecurityScore>
     */
    public function scopeCritical(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('level', 'critical');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<SecurityScore> $query
     * @return \Illuminate\Database\Eloquent\Builder<SecurityScore>
     */
    public function scopeLatest(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->orderByDesc('calculated_at');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public static function levelFromScore(int $score): string
    {
        return match (true) {
            $score >= 90 => 'excellent',
            $score >= 70 => 'good',
            $score >= 50 => 'medium',
            $score >= 30 => 'low',
            default      => 'critical',
        };
    }
}
