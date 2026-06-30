<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'department',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'        => 'datetime',
            'last_login_at'            => 'datetime',
            'two_factor_confirmed_at'  => 'datetime',
            'password'                 => 'hashed',
            'is_active'                => 'boolean',
        ];
    }

    // ── Auditoría ────────────────────────────────────────────
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'department', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ── Relaciones ───────────────────────────────────────────
    public function accessLogs(): HasMany
    {
        return $this->hasMany(\App\Models\AccessLog::class);
    }

    public function acknowledgedAlerts(): HasMany
    {
        return $this->hasMany(\App\Models\Alert::class, 'acknowledged_by');
    }

    public function resolvedAlerts(): HasMany
    {
        return $this->hasMany(\App\Models\Alert::class, 'resolved_by');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ──────────────────────────────────────────────
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    public function hasVerifiedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }
}

