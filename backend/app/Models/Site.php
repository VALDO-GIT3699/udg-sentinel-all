<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Site extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'site_group_id',
        'name',
        'slug',
        'domain',
        'url',
        'is_active',
        'is_monitored',
        'priority',
        'current_status',
        'current_score',
        'current_score_level',
        'last_checked_at',
        'check_interval_min',
        'notes',
        'tags',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'is_monitored'    => 'boolean',
        'priority'        => 'integer',
        'current_score'   => 'integer',
        'check_interval_min' => 'integer',
        'last_checked_at' => 'immutable_datetime',
        'tags'            => 'array',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function siteGroup(): BelongsTo
    {
        return $this->belongsTo(SiteGroup::class);
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'site_server')
            ->withPivot('is_primary');
    }

    public function primaryServer(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'site_server')
            ->wherePivot('is_primary', true);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(SiteCheck::class);
    }

    public function latestCheck(): HasOne
    {
        return $this->hasOne(SiteCheck::class)->latestOfMany('checked_at');
    }

    public function sslCertificate(): HasOne
    {
        return $this->hasOne(SslCertificate::class)->latestOfMany('last_checked_at');
    }

    public function sslCertificates(): HasMany
    {
        return $this->hasMany(SslCertificate::class);
    }

    public function cmsDetail(): HasOne
    {
        return $this->hasOne(CmsDetail::class);
    }

    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class, 'site_technologies')
            ->withPivot(['version', 'confidence_pct', 'is_primary', 'detected_at', 'detection_method', 'metadata'])
            ->withTimestamps();
    }

    public function siteTechnologies(): HasMany
    {
        return $this->hasMany(SiteTechnology::class);
    }

    public function securityScores(): HasMany
    {
        return $this->hasMany(SecurityScore::class);
    }

    public function latestSecurityScore(): HasOne
    {
        return $this->hasOne(SecurityScore::class)->latestOfMany('calculated_at');
    }

    public function securityHeaders(): HasMany
    {
        return $this->hasMany(SecurityHeader::class);
    }

    public function latestSecurityHeader(): HasOne
    {
        return $this->hasOne(SecurityHeader::class)->latestOfMany('checked_at');
    }

    public function scanResults(): HasMany
    {
        return $this->hasMany(ScanResult::class);
    }

    public function vulnerabilities(): HasMany
    {
        return $this->hasMany(Vulnerability::class);
    }

    public function brokenLinks(): HasMany
    {
        return $this->hasMany(BrokenLink::class);
    }

    public function trafficMetrics(): HasMany
    {
        return $this->hasMany(TrafficMetric::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SiteEvent::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Site> $query
     * @return \Illuminate\Database\Eloquent\Builder<Site>
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Site> $query
     * @return \Illuminate\Database\Eloquent\Builder<Site>
     */
    public function scopeMonitored(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_monitored', true);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Site> $query
     * @return \Illuminate\Database\Eloquent\Builder<Site>
     */
    public function scopeDown(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('current_status', 'down');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Site> $query
     * @return \Illuminate\Database\Eloquent\Builder<Site>
     */
    public function scopeCritical(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('priority', 1);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Site> $query
     * @param string $domain
     * @return \Illuminate\Database\Eloquent\Builder<Site>
     */
    public function scopeByDomain(\Illuminate\Database\Eloquent\Builder $query, string $domain): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('domain', $domain);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isUp(): bool
    {
        return $this->current_status === 'up';
    }

    public function isDown(): bool
    {
        return $this->current_status === 'down';
    }

    public function isDegraded(): bool
    {
        return $this->current_status === 'degraded';
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->current_status) {
            'up'       => 'green',
            'down'     => 'red',
            'degraded' => 'amber',
            default    => 'gray',
        };
    }
}
