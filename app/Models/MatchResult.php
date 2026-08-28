<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MatchResult extends Model
{
    use HasFactory;

    public $timestamps = false;

    const RESULTS = [
        'win' => 'Victoria',
        'loss' => 'Derrota',
        'draw' => 'Empate',
        'no_show' => 'No Show'
    ];

    const PL_CHANGES = [
        'win' => 3,
        'loss' => -2,
        'draw' => 0,
        'no_show' => -2
    ];

    protected $fillable = [
        'match_id',
        'player_id',
        'result',
        'pl_change',
        'mmr_change',
        'pl_before',
        'pl_after',
        'mmr_before',
        'mmr_after',
        'reported_by_admin',
        'scoring_context',
        'created_at',
    ];

    protected $casts = [
        'pl_change' => 'float',
        'pl_before' => 'float',
        'pl_after' => 'float',
        'mmr_change' => 'integer',
        'mmr_before' => 'integer',
        'mmr_after' => 'integer',
        'reported_by_admin' => 'boolean',
        'scoring_context' => 'array',
        'created_at' => 'datetime'
    ];

    public function match()
    {
        return $this->belongsTo(ArenaMatch::class, 'match_id');
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function getResultNameAttribute()
    {
        return self::RESULTS[$this->result] ?? $this->result;
    }

    public function isWin()
    {
        return $this->result === 'win';
    }

    public function isLoss()
    {
        return $this->result === 'loss';
    }

    public function isDraw()
    {
        return $this->result === 'draw';
    }

    public function isNoShow()
    {
        return $this->result === 'no_show';
    }

    public static function calculateMMRChange($playerMMR, $opponentAvgMMR, $result)
    {
        $kFactor = 30;
        $expectedScore = 1 / (1 + pow(10, ($opponentAvgMMR - $playerMMR) / 400));
        
        $actualScore = match($result) {
            'win' => 1,
            'loss' => 0,
            'no_show' => 0,
            default => 0
        };
        
        return round($kFactor * ($actualScore - $expectedScore));
    }
}
