<?php

namespace App\Models;

use App\Support\ArenaMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Queue extends Model
{
    use HasFactory;

    // Sin modalidad en la etiqueta: la cola ya sabe su arena_mode.
    const QUEUE_TYPES = [
        'random' => 'Random',
        'premade' => 'Premade'
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
        'arena_mode',
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

    protected static function booted(): void
    {
        // Red de seguridad: una cola sin modalidad explicita entra a la que
        // este activa por defecto, nunca queda en null.
        static::creating(function (Queue $queue) {
            // resolve() ademas canoniza (' 3V3 ' -> '3v3'): guardar el
            // valor crudo romperia las comparaciones por modalidad.
            $queue->arena_mode = ArenaMode::resolve($queue->arena_mode);
        });
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function getTeamSizeAttribute(): int
    {
        return ArenaMode::teamSize($this->arena_mode);
    }

    public function scopeForMode($query, ?string $mode)
    {
        return $query->where('arena_mode', ArenaMode::resolve($mode));
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
