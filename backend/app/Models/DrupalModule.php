<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DrupalModule extends Model
{
    use HasFactory;

    protected $fillable = [
        'cms_detail_id',
        'module_name',
        'module_version',
        'is_enabled',
        'is_core',
        'project_url',
        'has_update_available',
        'security_update_available',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_core' => 'boolean',
        'has_update_available' => 'boolean',
        'security_update_available' => 'boolean',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function cmsDetail(): BelongsTo
    {
        return $this->belongsTo(CmsDetail::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param  Builder<DrupalModule>  $query
     * @return Builder<DrupalModule>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * @param  Builder<DrupalModule>  $query
     * @return Builder<DrupalModule>
     */
    public function scopeWithSecurityUpdates(Builder $query): Builder
    {
        return $query->where('security_update_available', true);
    }

    /**
     * @param  Builder<DrupalModule>  $query
     * @return Builder<DrupalModule>
     */
    public function scopeContrib(Builder $query): Builder
    {
        return $query->where('is_core', false);
    }
}
