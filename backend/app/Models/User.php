<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

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
        'two_factor_last_verified_timestamp',
    ];

    // ── Relaciones ───────────────────────────────────────────
    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class);
    }

    public function acknowledgedAlerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'acknowledged_by');
    }

    public function resolvedAlerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'resolved_by');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActive(Builder $query): Builder
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

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            // Cifrados en reposo (Laravel usa APP_KEY): el secreto TOTP y los
            // codigos de recuperacion son equivalentes a una contraseña
            // permanente de la cuenta, no deben quedar en texto plano en la BD.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'notify_on_critical_incidents' => 'boolean',
        ];
    }
}
