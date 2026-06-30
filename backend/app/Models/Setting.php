<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

final class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    // -----------------------------------------------------------------
    // Static helpers
    // -----------------------------------------------------------------

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember(
            "setting:{$key}",
            now()->addHour(),
            fn () => self::where('key', $key)->first()
        );

        if ($setting === null) {
            return $default;
        }

        return self::cast($setting->value, $setting->type);
    }

    public static function set(string $key, mixed $value): void
    {
        $type = match (true) {
            is_bool($value)    => 'boolean',
            is_int($value)     => 'integer',
            is_array($value)   => 'json',
            default            => 'string',
        };

        $stored = is_array($value)
            ? json_encode($value, JSON_THROW_ON_ERROR)
            : (string) $value;

        self::updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'type' => $type]
        );

        Cache::forget("setting:{$key}");
    }

    private static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR),
            default   => $value,
        };
    }
}
