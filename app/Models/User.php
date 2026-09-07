<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'discord_id',
        'discord_username',
        'discord_discriminator', 
        'discord_avatar',
        'email',
        'name',
        'is_admin',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    // Relación con Players (múltiples)
    public function players()
    {
        return $this->hasMany(Player::class);
    }

    // Personajes activos
    public function activePlayers()
    {
        return $this->players()->where('is_active', true);
    }

    // Personaje principal (más reciente o con más PL)
    public function mainPlayer()
    {
        return $this->players()
                    ->where('is_active', true)
                    ->orderBy('pl_points', 'desc')
                    ->orderBy('created_at', 'desc');
    }

    public function isAdmin(): bool
    {
        if ($this->is_admin) {
            return true;
        }

        return in_array($this->discord_id, config('services.discord.admin_ids', []), true);
    }
}
