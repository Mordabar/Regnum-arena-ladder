<?php

namespace App\Models;

use App\Support\ArenaMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Party extends Model
{
    use HasFactory, HasUuids;

    public const ACTIVE_STATUSES = ['forming', 'ready', 'queued'];

    protected $fillable = [
        'leader_player_id',
        'status',
        'realm',
        'arena_mode',
    ];

    protected static function booted(): void
    {
        static::creating(function (Party $party) {
            // resolve() ademas canoniza (' 3V3 ' -> '3v3'): guardar el
            // valor crudo romperia las comparaciones por modalidad.
            $party->arena_mode = ArenaMode::resolve($party->arena_mode);
        });
    }

    public function leader()
    {
        return $this->belongsTo(Player::class, 'leader_player_id');
    }

    public function members()
    {
        return $this->hasMany(PartyMember::class, 'party_id');
    }

    /**
     * Cuantos integrantes necesita esta party segun su modalidad.
     */
    public function teamSize(): int
    {
        return ArenaMode::teamSize($this->arena_mode);
    }

    public function isFull()
    {
        return $this->members()->where('is_accepted_invite', true)->count() === $this->teamSize();
    }

    public function areAllInvitesAccepted()
    {
        return $this->members()->where('is_accepted_invite', false)->doesntExist();
    }

    public function scopeForMode($query, ?string $mode)
    {
        return $query->where('arena_mode', ArenaMode::resolve($mode));
    }
}
