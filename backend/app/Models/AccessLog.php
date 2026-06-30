<?php

declare(strict_types=1);

namespace App\Models;

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
        'created_at'    => 'immutable_datetime',
        'response_code' => 'integer',
        'duration_ms'   => 'integer',
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
     * @param \Illuminate\Database\Eloquent\Builder<AccessLog> $query
     * @return \Illuminate\Database\Eloquent\Builder<AccessLog>
     */
    public function scopeFailedLogins(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('action', 'failed_login');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<AccessLog> $query
     * @param string $ip
     * @return \Illuminate\Database\Eloquent\Builder<AccessLog>
     */
    public function scopeFromIp(\Illuminate\Database\Eloquent\Builder $query, string $ip): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('ip_address', $ip);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<AccessLog> $query
     * @param int $minutes
     * @return \Illuminate\Database\Eloquent\Builder<AccessLog>
     */
    public function scopeInLastMinutes(\Illuminate\Database\Eloquent\Builder $query, int $minutes): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }
}
