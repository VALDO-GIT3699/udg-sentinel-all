<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SiteGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'responsible_name',
        'responsible_email',
        'color',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param  Builder<SiteGroup>  $query
     * @return Builder<SiteGroup>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    // -----------------------------------------------------------------
    // Accessors / computed
    // -----------------------------------------------------------------

    public function getSiteCountAttribute(): int
    {
        return $this->sites()->count();
    }

    public function getActiveSiteCountAttribute(): int
    {
        return $this->sites()->where('is_active', true)->count();
    }
}
