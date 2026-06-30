<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Technology extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'category',
        'vendor',
        'logo_url',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_technologies')
            ->withPivot(['version', 'confidence_pct', 'is_primary', 'detected_at', 'detection_method', 'metadata'])
            ->withTimestamps();
    }

    public function siteTechnologies(): HasMany
    {
        return $this->hasMany(SiteTechnology::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Technology> $query
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Builder<Technology>
     */
    public function scopeByCategory(\Illuminate\Database\Eloquent\Builder $query, string $category): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('category', $category);
    }
}
