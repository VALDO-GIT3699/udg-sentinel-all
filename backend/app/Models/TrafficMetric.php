<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TrafficMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'recorded_at',
        'requests_per_min',
        'unique_visitors',
        'bandwidth_bytes',
        'error_rate_pct',
        'avg_response_time_ms',
    ];

    protected $casts = [
        'recorded_at'          => 'immutable_datetime',
        'created_at'           => 'immutable_datetime',
        'requests_per_min'     => 'integer',
        'unique_visitors'      => 'integer',
        'bandwidth_bytes'      => 'integer',
        'error_rate_pct'       => 'float',
        'avg_response_time_ms' => 'integer',
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
     * @param \Illuminate\Database\Eloquent\Builder<TrafficMetric> $query
     * @param int $hours
     * @return \Illuminate\Database\Eloquent\Builder<TrafficMetric>
     */
    public function scopeInLastHours(\Illuminate\Database\Eloquent\Builder $query, int $hours): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('recorded_at', '>=', now()->subHours($hours));
    }
}
