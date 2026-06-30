<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationSent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'alert_id',
        'channel_id',
        'status',
        'sent_at',
        'error_message',
    ];

    protected $casts = [
        'sent_at'    => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'channel_id');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param \Illuminate\Database\Eloquent\Builder<NotificationSent> $query
     * @return \Illuminate\Database\Eloquent\Builder<NotificationSent>
     */
    public function scopeFailed(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<NotificationSent> $query
     * @return \Illuminate\Database\Eloquent\Builder<NotificationSent>
     */
    public function scopeSent(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'sent');
    }
}
