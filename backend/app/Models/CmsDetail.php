<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CmsDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'cms_type',
        'cms_version',
        'db_type',
        'db_version',
        'php_version',
        'php_is_vulnerable',
        'server_software',
        'theme_name',
        'theme_version',
        'modules_count',
        'has_updates',
        'has_security_updates',
        'last_scanned_at',
    ];

    protected $casts = [
        'php_is_vulnerable'   => 'boolean',
        'modules_count'       => 'integer',
        'has_updates'         => 'boolean',
        'has_security_updates' => 'boolean',
        'last_scanned_at'     => 'immutable_datetime',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function drupalModules(): HasMany
    {
        return $this->hasMany(DrupalModule::class);
    }

    public function enabledModules(): HasMany
    {
        return $this->hasMany(DrupalModule::class)->where('is_enabled', true);
    }

    public function modulesWithSecurityUpdates(): HasMany
    {
        return $this->hasMany(DrupalModule::class)->where('security_update_available', true);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isDrupal(): bool
    {
        return $this->cms_type === 'drupal';
    }

    public function isWordPress(): bool
    {
        return $this->cms_type === 'wordpress';
    }

    public function hasAnySecurityConcern(): bool
    {
        return $this->php_is_vulnerable || $this->has_security_updates;
    }
}
