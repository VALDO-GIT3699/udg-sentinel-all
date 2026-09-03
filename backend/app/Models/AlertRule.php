<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'is_active' => 'boolean',
        'target_id' => 'integer',
        'cooldown_minutes' => 'integer',
        'channel_ids' => 'array',
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
     * @param  Builder<AlertRule>  $query
     * @return Builder<AlertRule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<AlertRule>  $query
     * @return Builder<AlertRule>
     */
    public function scopeForMetric(Builder $query, string $metric): Builder
    {
        return $query->where('metric_type', $metric);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function appliesToSite(int $siteId): bool
    {
        return match ($this->applies_to) {
            'all' => true,
            'site' => $this->target_id === $siteId,
            default => false,
        };
    }
}
