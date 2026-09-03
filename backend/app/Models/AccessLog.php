<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'ip_address',
        'user_agent',
        'endpoint',
        'request_method',
        'response_code',
        'duration_ms',
    ];

    protected $casts = [
        'created_at' => 'immutable_datetime',
        'response_code' => 'integer',
        'duration_ms' => 'integer',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param  Builder<AccessLog>  $query
     * @return Builder<AccessLog>
     */
    public function scopeFailedLogins(Builder $query): Builder
    {
        return $query->where('action', 'failed_login');
    }

    /**
     * @param  Builder<AccessLog>  $query
     * @return Builder<AccessLog>
     */
    public function scopeFromIp(Builder $query, string $ip): Builder
    {
        return $query->where('ip_address', $ip);
    }

    /**
     * @param  Builder<AccessLog>  $query
     * @return Builder<AccessLog>
     */
    public function scopeInLastMinutes(Builder $query, int $minutes): Builder
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }
}
