<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Party extends Model
{
    use HasFactory, HasUuids;

    public const TEAM_SIZE = 2;
    public const ACTIVE_STATUSES = ['forming', 'ready', 'queued'];

    protected $fillable = [
        'leader_player_id',
        'status',
        'realm',
    ];

    public function leader()
    {
        return $this->belongsTo(Player::class, 'leader_player_id');
    }

    public function members()
    {
        return $this->hasMany(PartyMember::class, 'party_id');
    }

    public function isFull()
    {
        return $this->members()->where('is_accepted_invite', true)->count() === self::TEAM_SIZE;
    }

    public function areAllInvitesAccepted()
    {
        return $this->members()->where('is_accepted_invite', false)->doesntExist();
    }
}
