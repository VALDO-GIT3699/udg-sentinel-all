<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    public const LIFECYCLE_STATUSES = [
        '1ra Etapa',
        '2da Etapa',
        'Migrando',
        'Migrado',
        'Eliminado',
        'Solicitud de baja',
        'Migrado y publicado',
        'N/A',
        'Migración de CMS',
        'Sistema',
        'Otro',
    ];

    protected $fillable = [
        'site_group_id',
        'name',
        'slug',
        'domain',
        'url',
        'asset_type',
        'asset_role',
        'asset_confidence_pct',
        'asset_classification_source',
        'asset_classifier_version',
        'asset_last_classified_at',
        'asset_classification_locked_at',
        'asset_classification_evidence',
        'is_active',
        'is_monitored',
        'priority',
        'current_status',
        'lifecycle_status',
        'elimination_ticket',
        'current_score',
        'current_score_level',
        'last_checked_at',
        'check_interval_min',
        'notes',
        'tags',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_monitored' => 'boolean',
        'priority' => 'integer',
        'current_score' => 'integer',
        'asset_confidence_pct' => 'integer',
        'lifecycle_status' => 'string',
        'check_interval_min' => 'integer',
        'last_checked_at' => 'immutable_datetime',
        'asset_last_classified_at' => 'immutable_datetime',
        'asset_classification_locked_at' => 'immutable_datetime',
        'asset_classification_evidence' => 'array',
        'tags' => 'array',
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

    public function inspectionProfile(): HasOne
    {
        return $this->hasOne(SiteInspectionProfile::class);
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

    public function assetClassifications(): HasMany
    {
        return $this->hasMany(AssetClassification::class);
    }

    public function latestAssetClassification(): HasOne
    {
        return $this->hasOne(AssetClassification::class)
            ->where('is_current', true)
            ->latestOfMany('classified_at');
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
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeMonitored(Builder $query): Builder
    {
        return $query->where('is_monitored', true);
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeDown(Builder $query): Builder
    {
        return $query->where('current_status', 'down');
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('priority', 1);
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeByDomain(Builder $query, string $domain): Builder
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
            'up' => 'green',
            'down' => 'red',
            'degraded' => 'amber',
            default => 'gray',
        };
    }
}
