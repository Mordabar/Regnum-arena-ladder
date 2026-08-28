<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Queue extends Model
{
    use HasFactory;

    const QUEUE_TYPES = [
        'random' => 'Random 2v2',
        'premade' => 'Premade 2v2'
    ];

    const STATUSES = [
        'waiting' => 'En cola',
        'matched' => 'Emparejado', 
        'accepted' => 'Aceptado',
        'cancelled' => 'Cancelado'
    ];

    protected $fillable = [
        'player_id',
        'queue_type',
        'status',
        'conjurer_role',
        'estimated_mmr',
        'team_composition',
        'premade_leader_discord_id',
        'party_signature',
        'joined_at',
        'matched_at',
        'expires_at',
        'team_id',
        'match_id'
    ];

    protected $casts = [
        'team_composition' => 'array',
        'joined_at' => 'datetime',
        'matched_at' => 'datetime',
        'expires_at' => 'datetime'
    ];

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function user()
    {
        return $this->hasOneThrough(User::class, Player::class, 'id', 'id', 'player_id', 'user_id');
    }

    public function scopeWaiting($query)
    {
        return $query->where('status', 'waiting');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('queue_type', $type);
    }

    public function scopeByRealm($query, $realm)
    {
        return $query->whereHas('player', function ($q) use ($realm) {
            $q->where('realm', $realm);
        });
    }

    public function isExpired()
    {
        return $this->expires_at && now()->gt($this->expires_at);
    }

    public function getWaitTimeAttribute()
    {
        return $this->joined_at->diffForHumans();
    }
}
