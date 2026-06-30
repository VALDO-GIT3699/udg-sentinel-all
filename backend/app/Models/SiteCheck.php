<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SiteCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'checked_at',
        'status',
        'http_code',
        'response_time_ms',
        'response_size_bytes',
        'ip_resolved',
        'redirect_url',
        'error_message',
        'checked_from',
    ];

    protected $casts = [
        'checked_at'          => 'immutable_datetime',
        'created_at'          => 'immutable_datetime',
        'http_code'           => 'integer',
        'response_time_ms'    => 'integer',
        'response_size_bytes' => 'integer',
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
     * @param \Illuminate\Database\Eloquent\Builder<SiteCheck> $query
     * @return \Illuminate\Database\Eloquent\Builder<SiteCheck>
     */
    public function scopeUp(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'up');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<SiteCheck> $query
     * @return \Illuminate\Database\Eloquent\Builder<SiteCheck>
     */
    public function scopeDown(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'down');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<SiteCheck> $query
     * @param int $hours
     * @return \Illuminate\Database\Eloquent\Builder<SiteCheck>
     */
    public function scopeInLastHours(\Illuminate\Database\Eloquent\Builder $query, int $hours): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('checked_at', '>=', now()->subHours($hours));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isSuccessful(): bool
    {
        return $this->status === 'up';
    }

    public function getUptimePctForSiteInHours(int $siteId, int $hours): float
    {
        $total = self::where('site_id', $siteId)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->count();

        if ($total === 0) {
            return 0.0;
        }

        $up = self::where('site_id', $siteId)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->where('status', 'up')
            ->count();

        return round(($up / $total) * 100, 2);
    }
}
