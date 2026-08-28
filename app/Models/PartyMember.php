<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartyMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'party_id',
        'player_id',
        'is_accepted_invite',
        'is_leader',
        'conjurer_role',
    ];

    protected $casts = [
        'is_accepted_invite' => 'boolean',
        'is_leader' => 'boolean',
    ];

    public function party()
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
