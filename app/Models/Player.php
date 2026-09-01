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
        'deactivated_reason',
        'deactivated_at',
    ];

    /** El jugador borro el personaje. Sus partidas jugadas se conservan. */
    public const DEACTIVATED_BY_PLAYER = 'deleted_by_player';

    /** Un administrador lo apago desde el panel. */
    public const DEACTIVATED_BY_ADMIN = 'disabled_by_admin';

    /** Marca que se anade al nombre de un personaje borrado por su dueno. */
    public const DELETED_NAME_SUFFIX = ' [ELIMINADO]';

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
            'deactivated_at' => 'datetime',
            'queue_locked_until' => 'datetime',
            'last_penalty_at' => 'datetime',
        ];
    }

    /**
     * Como se llama el estado del personaje de cara a quien lo lee.
     *
     * "Inactivo" ya no se usa: se confundia con la metrica de actividad del
     * ladder, que mide otra cosa (cuanto hace que su dueno no entra).
     */
    public function statusLabel(): string
    {
        if ($this->is_active) {
            return 'Activo';
        }

        return match ($this->deactivated_reason) {
            self::DEACTIVATED_BY_PLAYER => 'Eliminado',
            self::DEACTIVATED_BY_ADMIN => 'Deshabilitado',
            // Filas anteriores a que se guardase el motivo.
            default => 'Deshabilitado',
        };
    }

    /**
     * Personajes que el jugador todavia ve en su lobby.
     *
     * Un personaje eliminado desaparece de su vista y del ranking: la fila
     * sigue existiendo solo para no romper el historial de enfrentamientos ya
     * jugados. Recuperarlo hay que pedirselo a un administrador.
     */
    public function scopeVisibleToOwner($query)
    {
        return $query->where(fn ($builder) => $builder
            ->whereNull('deactivated_reason')
            ->orWhere('deactivated_reason', '!=', self::DEACTIVATED_BY_PLAYER));
    }

    public function scopeDeletedByOwner($query)
    {
        return $query->where('deactivated_reason', self::DEACTIVATED_BY_PLAYER);
    }

    public function isDeletedByOwner(): bool
    {
        return $this->deactivated_reason === self::DEACTIVATED_BY_PLAYER;
    }

    /** Nombre sin la marca de eliminado, para reactivar o para mostrarlo limpio. */
    public function cleanName(): string
    {
        return str_replace([self::DELETED_NAME_SUFFIX, ' [INACTIVO]'], '', $this->character_name);
    }

    // Relación con User
    /**
     * Dias sin aparecer a partir de los cuales un personaje se considera
     * dormido. Configurable desde el panel; por defecto dos semanas.
     */
    public static function dormancyDays(): int
    {
        return max(1, (int) \App\Models\AppSetting::getValue('inactive_after_days', 14));
    }

    /**
     * Personajes cuyo dueno lleva mucho sin pasar por el ladder.
     *
     * OJO: esto NO es lo mismo que `is_active`. `is_active` dice si el personaje
     * esta habilitado para jugar (se apaga al borrarlo teniendo partidas, o a
     * mano desde el panel). Dormido dice que hace tiempo que nadie lo usa. Un
     * personaje puede estar perfectamente habilitado y llevar un mes dormido, y
     * mezclar las dos cosas impediria volver a jugar a quien regresa.
     */
    public function scopeDormant($query, ?int $days = null)
    {
        $days = $days ?? self::dormancyDays();

        return $query->whereHas('user', function ($userQuery) use ($days) {
            $userQuery->where(function ($inner) use ($days) {
                $inner->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subDays($days));
            });
        });
    }

    public function scopeSeenRecently($query, ?int $days = null)
    {
        $days = $days ?? self::dormancyDays();

        return $query->whereHas('user', function ($userQuery) use ($days) {
            $userQuery->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', now()->subDays($days));
        });
    }

    public function isDormant(?int $days = null): bool
    {
        $lastSeen = $this->user?->last_seen_at;

        return $lastSeen === null
            || $lastSeen->lt(now()->subDays($days ?? self::dormancyDays()));
    }

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
    /**
     * Las claves son las del juego y no se tocan: van en la base de datos y en
     * los equipos guardados de cada enfrentamiento. Lo que se traduce es la
     * etiqueta, porque el resto del sitio esta en espanol.
     */
    const SUBCLASSES = [
        'knight' => 'Caballero',
        'barbarian' => 'Bárbaro',
        'hunter' => 'Cazador',
        'marksman' => 'Tirador',
        'conjurer' => 'Conjurador',
        'warlock' => 'Brujo'
    ];

    /** Que hace cada subclase en la arena, en una linea, para quien no conoce el juego. */
    const SUBCLASS_NOTES = [
        'knight' => 'Aguanta el frente y protege a los suyos.',
        'barbarian' => 'Cuerpo a cuerpo, mucho daño y poca defensa.',
        'hunter' => 'Daño a distancia con trampas y mascota.',
        'marksman' => 'El mayor daño a distancia del juego.',
        'conjurer' => 'Cura y protege, o castiga desde atrás.',
        'warlock' => 'Control y daño mágico en área.',
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
