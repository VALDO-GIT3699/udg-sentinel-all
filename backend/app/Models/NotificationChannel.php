<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

final class NotificationChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'config',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The config column is stored encrypted in JSON.
     * Use getConfig() / setConfig() to work with the decrypted array.
     */
    public function getConfigAttribute(string $value): array
    {
        try {
            return json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function setConfigAttribute(array $value): void
    {
        $this->attributes['config'] = Crypt::encryptString(
            json_encode($value, JSON_THROW_ON_ERROR)
        );
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function notificationsSent(): HasMany
    {
        return $this->hasMany(NotificationSent::class, 'channel_id');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * @param \Illuminate\Database\Eloquent\Builder<NotificationChannel> $query
     * @return \Illuminate\Database\Eloquent\Builder<NotificationChannel>
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<NotificationChannel> $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder<NotificationChannel>
     */
    public function scopeOfType(\Illuminate\Database\Eloquent\Builder $query, string $type): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('type', $type);
    }
}
