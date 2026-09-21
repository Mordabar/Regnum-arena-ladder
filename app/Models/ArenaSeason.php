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
        'prizes',
        'prize_currency',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled_modes' => 'array',
            'prizes' => 'array',
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

    /**
     * Lo que repartio esta temporada, por puesto.
     *
     * @return array<int, int>
     */
    public function reparto(): array
    {
        $reparto = [];

        foreach ((array) ($this->prizes ?? []) as $puesto => $cantidad) {
            if ((int) $cantidad > 0) {
                $reparto[(int) $puesto] = (int) $cantidad;
            }
        }

        ksort($reparto);

        return $reparto;
    }

    public function totalRepartido(): int
    {
        return array_sum($this->reparto());
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
