<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AppSetting extends Model
{
    use HasFactory;

    protected static ?bool $settingsTableExists = null;
    protected static ?Collection $settingsCache = null;

    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    public static function getValue(string $key, mixed $default = null): mixed
    {
        if (! static::hasSettingsTable()) {
            return $default;
        }

        $setting = static::loadSettingsCache()->get($key);

        if (!$setting) {
            return $default;
        }

        return match ($setting->type) {
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $setting->value,
            'float' => (float) $setting->value,
            'json' => json_decode((string) $setting->value, true) ?? $default,
            default => $setting->value,
        };
    }

    public static function setValue(
        string $key,
        mixed $value,
        string $group = 'general',
        string $type = 'string',
        bool $isPublic = false
    ): self {
        if (! static::hasSettingsTable()) {
            throw new \RuntimeException('The app_settings table does not exist.');
        }

        $setting = static::query()->updateOrCreate(
            ['key' => $key],
            [
                'group' => $group,
                'type' => $type,
                'value' => $type === 'json' ? json_encode($value) : (string) $value,
                'is_public' => $isPublic,
            ]
        );

        static::$settingsCache = null;

        return $setting;
    }

    protected static function hasSettingsTable(): bool
    {
        if (static::$settingsTableExists !== null) {
            return static::$settingsTableExists;
        }

        try {
            return static::$settingsTableExists = Schema::hasTable('app_settings');
        } catch (\Throwable) {
            return static::$settingsTableExists = false;
        }
    }

    protected static function loadSettingsCache(): Collection
    {
        if (static::$settingsCache !== null) {
            return static::$settingsCache;
        }

        try {
            return static::$settingsCache = static::query()
                ->get()
                ->keyBy('key');
        } catch (QueryException) {
            static::$settingsTableExists = false;

            return static::$settingsCache = collect();
        }
    }
}
