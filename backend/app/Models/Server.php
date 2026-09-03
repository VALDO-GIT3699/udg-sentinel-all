<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'hostname',
        'ip_address',
        'os',
        'provider',
        'location',
        'ssh_port',
        'ssh_user',
        'is_accessible',
        'cpu_cores',
        'ram_gb',
        'disk_gb',
        'notes',
    ];

    protected $casts = [
        'ssh_port' => 'integer',
        'is_accessible' => 'boolean',
        'cpu_cores' => 'integer',
        'ram_gb' => 'float',
        'disk_gb' => 'float',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_server')
            ->withPivot('is_primary');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(ServerMetric::class)->latestOfMany('recorded_at');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param  Builder<Server>  $query
     * @return Builder<Server>
     */
    public function scopeAccessible(Builder $query): Builder
    {
        return $query->where('is_accessible', true);
    }
}
