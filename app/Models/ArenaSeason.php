<?php

namespace App\Models;

use App\Support\ArenaMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ArenaSeason extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'enabled_modes',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled_modes' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        if (!Schema::hasTable('arena_seasons')) {
            return null;
        }

        return static::query()
            ->where('status', self::STATUS_ACTIVE)
            ->latest('starts_at')
            ->first();
    }

    public function enabledModes(): array
    {
        return collect($this->enabled_modes ?? [])
            ->map(fn ($mode) => ArenaMode::normalize((string) $mode))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function stats()
    {
        return $this->hasMany(SeasonPlayerStat::class, 'season_id');
    }

    public function matches()
    {
        return $this->hasMany(ArenaMatch::class, 'season_id');
    }
}
