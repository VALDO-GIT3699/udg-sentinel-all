<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AlertRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'metric_type',
        'condition_operator',
        'condition_value',
        'severity',
        'is_active',
        'applies_to',
        'target_id',
        'cooldown_minutes',
        'channel_ids',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'target_id'        => 'integer',
        'cooldown_minutes' => 'integer',
        'channel_ids'      => 'array',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param \Illuminate\Database\Eloquent\Builder<AlertRule> $query
     * @return \Illuminate\Database\Eloquent\Builder<AlertRule>
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<AlertRule> $query
     * @param string $metric
     * @return \Illuminate\Database\Eloquent\Builder<AlertRule>
     */
    public function scopeForMetric(\Illuminate\Database\Eloquent\Builder $query, string $metric): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('metric_type', $metric);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function appliesToSite(int $siteId): bool
    {
        return match ($this->applies_to) {
            'all'  => true,
            'site' => $this->target_id === $siteId,
            default => false,
        };
    }
}
