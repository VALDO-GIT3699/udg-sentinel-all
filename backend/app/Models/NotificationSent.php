<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationSent extends Model
{
    public $timestamps = false;

    // La migracion crea la tabla como "notifications_sent" (no
    // "notification_sents", que es lo que Eloquent adivinaria por
    // convencion a partir del nombre de esta clase) — sin esto, cualquier
    // query contra el modelo fallaba con "no such table". Nunca se detecto
    // porque la tabla seguia en cero filas.
    protected $table = 'notifications_sent';

    protected $fillable = [
        'alert_id',
        'channel_id',
        'status',
        'sent_at',
        'error_message',
    ];

    protected $casts = [
        'sent_at' => 'immutable_datetime',
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
     * @param  Builder<NotificationSent>  $query
     * @return Builder<NotificationSent>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * @param  Builder<NotificationSent>  $query
     * @return Builder<NotificationSent>
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }
}
