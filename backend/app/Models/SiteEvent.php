<?php

declare(strict_types=1);

namespace App\Models;

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
        'created_at'  => 'immutable_datetime',
        'metadata'    => 'array',
    ];

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
     * @param \Illuminate\Database\Eloquent\Builder<SiteEvent> $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder<SiteEvent>
     */
    public function scopeOfType(\Illuminate\Database\Eloquent\Builder $query, string $type): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('event_type', $type);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<SiteEvent> $query
     * @return \Illuminate\Database\Eloquent\Builder<SiteEvent>
     */
    public function scopeCritical(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('severity', 'critical');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<SiteEvent> $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder<SiteEvent>
     */
    public function scopeInLastDays(\Illuminate\Database\Eloquent\Builder $query, int $days): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('occurred_at', '>=', now()->subDays($days));
    }

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
    ): self {
        return self::create([
            'site_id'     => $siteId,
            'event_type'  => $eventType,
            'title'       => $title,
            'description' => $description,
            'severity'    => $severity,
            'metadata'    => $metadata,
            'occurred_at' => now(),
            'created_by'  => $createdBy,
        ]);
    }
}
