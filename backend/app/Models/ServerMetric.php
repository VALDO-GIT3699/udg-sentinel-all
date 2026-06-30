<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ServerMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id',
        'recorded_at',
        'cpu_usage_pct',
        'ram_usage_pct',
        'ram_used_mb',
        'ram_total_mb',
        'disk_usage_pct',
        'disk_used_gb',
        'disk_total_gb',
        'load_avg_1',
        'load_avg_5',
        'load_avg_15',
    ];

    protected $casts = [
        'recorded_at'    => 'immutable_datetime',
        'created_at'     => 'immutable_datetime',
        'cpu_usage_pct'  => 'float',
        'ram_usage_pct'  => 'float',
        'ram_used_mb'    => 'integer',
        'ram_total_mb'   => 'integer',
        'disk_usage_pct' => 'float',
        'disk_used_gb'   => 'float',
        'disk_total_gb'  => 'float',
        'load_avg_1'     => 'float',
        'load_avg_5'     => 'float',
        'load_avg_15'    => 'float',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param \Illuminate\Database\Eloquent\Builder<ServerMetric> $query
     * @param int $hours
     * @return \Illuminate\Database\Eloquent\Builder<ServerMetric>
     */
    public function scopeInLastHours(\Illuminate\Database\Eloquent\Builder $query, int $hours): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('recorded_at', '>=', now()->subHours($hours));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isCpuCritical(float $threshold = 90.0): bool
    {
        return $this->cpu_usage_pct !== null && $this->cpu_usage_pct >= $threshold;
    }

    public function isRamCritical(float $threshold = 90.0): bool
    {
        return $this->ram_usage_pct !== null && $this->ram_usage_pct >= $threshold;
    }

    public function isDiskCritical(float $threshold = 85.0): bool
    {
        return $this->disk_usage_pct !== null && $this->disk_usage_pct >= $threshold;
    }
}
