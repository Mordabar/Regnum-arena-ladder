<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'character_name',
        'subclass',
        'realm',
        'pl_points',
        'mmr',
        'matches_played',
        'wins',
        'losses',
        'trust_score',
        'penalty_strikes',
        'queue_locked_until',
        'queue_lock_reason',
        'last_penalty_type',
        'last_penalty_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pl_points' => 'float',
            'mmr' => 'integer',
            'matches_played' => 'integer',
            'wins' => 'integer', 
            'losses' => 'integer',
            'trust_score' => 'integer',
            'penalty_strikes' => 'integer',
            'is_active' => 'boolean',
            'queue_locked_until' => 'datetime',
            'last_penalty_at' => 'datetime',
        ];
    }

    // Relación con User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Constantes para reinos
    const REALMS = [
        'ignis' => 'Ignis',
        'alsius' => 'Alsius', 
        'syrtis' => 'Syrtis'
    ];

    // Constantes para subclases
    const SUBCLASSES = [
        'knight' => 'Knight',
        'barbarian' => 'Barbarian',
        'hunter' => 'Hunter',
        'marksman' => 'Marksman',
        'conjurer' => 'Conjurer',
        'warlock' => 'Warlock'
    ];

    const PENALTY_TYPES = [
        'abandonment' => 'Abandono',
        'support_infraction' => 'Infraccion de soporte',
        'manual_lock' => 'Bloqueo manual',
    ];

    // Relaciones
    public function queues()
    {
        return $this->hasMany(Queue::class);
    }

    public function partyMembers()
    {
        return $this->hasMany(PartyMember::class);
    }

    public function matchResults()
    {
        return $this->hasMany(MatchResult::class);
    }

    public function currentQueue()
    {
        return $this->hasOne(Queue::class)->where('status', 'waiting');
    }

    // Scopes útiles
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByRealm($query, $realm)
    {
        return $query->where('realm', $realm);
    }

    public function scopeInQueue($query)
    {
        return $query->whereHas('queues', function ($q) {
            $q->where('status', 'waiting');
        });
    }

    public function scopeOrderByPublicLadder($query)
    {
        return $query
            ->orderByDesc('pl_points')
            ->orderByDesc('mmr')
            ->orderByDesc('wins')
            ->orderBy('id');
    }

    // Métodos útiles
    public function isInQueue()
    {
        return $this->currentQueue()->exists();
    }

    public function isQueueLocked(): bool
    {
        return $this->queue_locked_until !== null && now()->lt($this->queue_locked_until);
    }

    public function getQueueLockReasonNameAttribute(): ?string
    {
        if (!$this->queue_lock_reason) {
            return null;
        }

        return self::PENALTY_TYPES[$this->queue_lock_reason] ?? ucfirst(str_replace('_', ' ', $this->queue_lock_reason));
    }

    public function getLastPenaltyTypeNameAttribute(): ?string
    {
        if (!$this->last_penalty_type) {
            return null;
        }

        return self::PENALTY_TYPES[$this->last_penalty_type] ?? ucfirst(str_replace('_', ' ', $this->last_penalty_type));
    }

    public function getWinRateAttribute()
    {
        if ($this->matches_played == 0) return 0;
        return round(($this->wins / $this->matches_played) * 100, 1);
    }

    public function getRankingPositionAttribute()
    {
        $cacheKey = "player:{$this->id}:ranking_position";
        
        return cache()->remember($cacheKey, now()->addHours(2), function () {
            return Player::active()
                ->where(function ($query) {
                    $query->where('pl_points', '>', $this->pl_points)
                        ->orWhere(function ($tieBreaker) {
                            $tieBreaker->where('pl_points', '=', $this->pl_points)
                                ->where('mmr', '>', $this->mmr);
                        })
                        ->orWhere(function ($tieBreaker) {
                            $tieBreaker->where('pl_points', '=', $this->pl_points)
                                ->where('mmr', '=', $this->mmr)
                                ->where('wins', '>', $this->wins);
                        })
                        ->orWhere(function ($tieBreaker) {
                            $tieBreaker->where('pl_points', '=', $this->pl_points)
                                ->where('mmr', '=', $this->mmr)
                                ->where('wins', '=', $this->wins)
                                ->where('id', '<', $this->id);
                        });
                })
                ->count() + 1;
        });
    }
}
