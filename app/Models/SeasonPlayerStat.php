<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeasonPlayerStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'player_id',
        'character_name',
        'realm',
        'subclass',
        'is_hall_eligible',
        'pl_points',
        'mmr',
        'matches_played',
        'wins',
        'losses',
    ];

    protected function casts(): array
    {
        return [
            'pl_points' => 'float',
            'mmr' => 'integer',
            'matches_played' => 'integer',
            'wins' => 'integer',
            'losses' => 'integer',
            'is_hall_eligible' => 'boolean',
        ];
    }

    public function season()
    {
        return $this->belongsTo(ArenaSeason::class, 'season_id');
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }
}
