<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SiteEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'event_type',
        'title',
        'description',
        'severity',
        'metadata',
        'occurred_at',
        'created_by',
    ];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    // -----------------------------------------------------------------
    // Factory helpers
    // -----------------------------------------------------------------

    public static function record(
        int $siteId,
        string $eventType,
        string $title,
        string $severity = 'info',
        ?string $description = null,
        array $metadata = [],
        ?int $createdBy = null,
        ?DateTimeInterface $occurredAt = null,
    ): self {
        return self::create([
            'site_id' => $siteId,
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'severity' => $severity,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt ?? now(),
            'created_by' => $createdBy,
        ]);
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param  Builder<SiteEvent>  $query
     * @return Builder<SiteEvent>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('event_type', $type);
    }

    /**
     * @param  Builder<SiteEvent>  $query
     * @return Builder<SiteEvent>
     */
    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', 'critical');
    }

    /**
     * @param  Builder<SiteEvent>  $query
     * @return Builder<SiteEvent>
     */
    public function scopeInLastDays(Builder $query, int $days): Builder
    {
        return $query->where('occurred_at', '>=', now()->subDays($days));
    }
}
