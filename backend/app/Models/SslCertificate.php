<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SslCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'common_name',
        'issuer',
        'issuer_org',
        'valid_from',
        'valid_until',
        'days_remaining',
        'is_valid',
        'is_expired',
        'algorithm',
        'key_size',
        'signature_alg',
        'san_domains',
        'fingerprint_sha256',
        'last_checked_at',
    ];

    protected $casts = [
        'valid_from'      => 'immutable_datetime',
        'valid_until'     => 'immutable_datetime',
        'last_checked_at' => 'immutable_datetime',
        'days_remaining'  => 'integer',
        'is_valid'        => 'boolean',
        'is_expired'      => 'boolean',
        'key_size'        => 'integer',
        'san_domains'     => 'array',
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
     * @param \Illuminate\Database\Eloquent\Builder<SslCertificate> $query
     * @return \Illuminate\Database\Eloquent\Builder<SslCertificate>
     */
    public function scopeExpiringSoon(\Illuminate\Database\Eloquent\Builder $query, int $days = 30): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('days_remaining', '<=', $days)->where('is_expired', false);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<SslCertificate> $query
     * @return \Illuminate\Database\Eloquent\Builder<SslCertificate>
     */
    public function scopeCritical(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('days_remaining', '<=', (int) config('sentinel.ssl_alert_days_critical', 7));
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<SslCertificate> $query
     * @return \Illuminate\Database\Eloquent\Builder<SslCertificate>
     */
    public function scopeExpired(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_expired', true);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function getExpiryLevelAttribute(): string
    {
        if ($this->is_expired) {
            return 'expired';
        }

        $criticalDays = (int) config('sentinel.ssl_alert_days_critical', 7);
        $warningDays  = (int) config('sentinel.ssl_alert_days_warning', 30);

        return match (true) {
            $this->days_remaining <= $criticalDays => 'critical',
            $this->days_remaining <= $warningDays  => 'warning',
            default                                => 'ok',
        };
    }
}
