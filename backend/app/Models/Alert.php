<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Alert extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'alert_rule_id',
        'title',
        'message',
        'severity',
        'status',
        'triggered_at',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
        'resolved_by',
        'context',
    ];

    protected $casts = [
        'triggered_at'    => 'immutable_datetime',
        'acknowledged_at' => 'immutable_datetime',
        'resolved_at'     => 'immutable_datetime',
        'context'         => 'array',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationSent::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Alert> $query
     * @return \Illuminate\Database\Eloquent\Builder<Alert>
     */
    public function scopeOpen(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Alert> $query
     * @return \Illuminate\Database\Eloquent\Builder<Alert>
     */
    public function scopeCritical(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('severity', 'critical');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Alert> $query
     * @return \Illuminate\Database\Eloquent\Builder<Alert>
     */
    public function scopeUnresolved(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn('status', ['open', 'acknowledged']);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Alert> $query
     * @param string $severity
     * @return \Illuminate\Database\Eloquent\Builder<Alert>
     */
    public function scopeOfSeverity(\Illuminate\Database\Eloquent\Builder $query, string $severity): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('severity', $severity);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    public function acknowledge(int $userId): bool
    {
        return $this->update([
            'status'          => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => $userId,
        ]);
    }

    public function resolve(int $userId): bool
    {
        return $this->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $userId,
        ]);
    }
}
