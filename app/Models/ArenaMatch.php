<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ArenaMatch extends Model
{
    use HasFactory;

    protected $table = 'matches';

    const ZONES = [
        'frozen_bridge' => ['number' => 1, 'name' => 'Frozen Bridge'],
        'emerald_pass' => ['number' => 2, 'name' => 'Emerald Pass'],
        'red_cliff_pass' => ['number' => 3, 'name' => 'Red Cliff Pass'],
        'black_fort_shore' => ['number' => 4, 'name' => 'Black Fort Shore'],
        'merchant_coast' => ['number' => 5, 'name' => 'Merchant Coast'],
        'crimson_canyon' => ['number' => 6, 'name' => 'Crimson Canyon'],
        'central_ruins' => ['number' => 7, 'name' => 'Central Ruins'],
        'etreng_outskirts' => ['number' => 8, 'name' => 'Etreng Outskirts'],
        'obsidian_watch' => ['number' => 9, 'name' => 'Obsidian Watch'],
        'green_camp' => ['number' => 10, 'name' => 'Green Camp'],
        'jagaros_crossroads' => ['number' => 11, 'name' => 'Jagaros Crossroads'],
        'bridge_watch' => ['number' => 12, 'name' => 'Bridge Watch'],
        'aggersborg_bay' => ['number' => 13, 'name' => 'Aggersborg Bay'],
        'herth_gulf' => ['number' => 14, 'name' => 'Herth Gulf'],
    ];

    /**
     * Zonas recomendadas segun que dos reinos se cruzan, en orden de
     * preferencia.
     *
     * En la temporada 0 la zona salia por sorteo entre todas las libres, y eso
     * mandaba a la gente al otro extremo del mapa: dos reinos vecinos podian
     * acabar peleando en la frontera del tercero. Encontrar al rival costaba
     * mas que el propio combate. Estas listas son la frontera real de cada
     * cruce, elegidas por los jugadores durante las pruebas.
     *
     * La clave son los dos reinos en orden alfabetico, unidos por "|", para que
     * ignis-syrtis y syrtis-ignis caigan en la misma entrada.
     *
     * Ojo: esto NO bloquea el resto de zonas. Solo las ordena. Si las
     * recomendadas de un cruce estan todas ocupadas, se sigue jugando en las
     * demas antes que hacer esperar a nadie.
     */
    public const ZONE_PREFERENCES = [
        // Syrtis contra Ignis: 8, 9, 3, 6, 7, 5, 4.
        'ignis|syrtis' => [
            'etreng_outskirts',
            'obsidian_watch',
            'red_cliff_pass',
            'crimson_canyon',
            'central_ruins',
            'merchant_coast',
            'black_fort_shore',
        ],
        // Ignis contra Alsius: 2, 1, 14, 3.
        'alsius|ignis' => [
            'emerald_pass',
            'frozen_bridge',
            'herth_gulf',
            'red_cliff_pass',
        ],
        // Alsius contra Syrtis: 13, 12, 11, 10.
        'alsius|syrtis' => [
            'aggersborg_bay',
            'bridge_watch',
            'jagaros_crossroads',
            'green_camp',
        ],
    ];

    private const ZONE_ALIASES = [
        'central ruins' => 'central_ruins',
        'centralruins' => 'central_ruins',
        'ruins' => 'central_ruins',
        'emerald pass' => 'emerald_pass',
        'emeraldpass' => 'emerald_pass',
        'pass' => 'emerald_pass',
        'crimson canyon' => 'crimson_canyon',
        'crimsoncanyon' => 'crimson_canyon',
        'canyon' => 'crimson_canyon',
        'frozen bridge' => 'frozen_bridge',
        'frozenbridge' => 'frozen_bridge',
        'bridge' => 'frozen_bridge',
        'merchant coast' => 'merchant_coast',
        'merchantcoast' => 'merchant_coast',
        'coast' => 'merchant_coast',
        'red cliff pass' => 'red_cliff_pass',
        'redcliffpass' => 'red_cliff_pass',
        'black fort shore' => 'black_fort_shore',
        'blackfortshore' => 'black_fort_shore',
        'etreng outskirts' => 'etreng_outskirts',
        'etrengoutskirts' => 'etreng_outskirts',
        'green camp' => 'green_camp',
        'greencamp' => 'green_camp',
        'jagaros crossroads' => 'jagaros_crossroads',
        'jagaroscrossroads' => 'jagaros_crossroads',
        'bridge watch' => 'bridge_watch',
        'bridgewatch' => 'bridge_watch',
        'aggersborg bay' => 'aggersborg_bay',
        'aggersborgbay' => 'aggersborg_bay',
        'herth gulf' => 'herth_gulf',
        'herthgulf' => 'herth_gulf',
        'obsidian watch' => 'obsidian_watch',
        'obsidianwatch' => 'obsidian_watch',
        'watch' => 'obsidian_watch',
    ];

    private const INVALID_ZONE_VALUES = [
        'ign',
        'ignis',
        'als',
        'alsius',
        'syr',
        'syrtis',
    ];

    const STATUSES = [
        'pending_acceptance' => 'Esperando aceptacion',
        'in_progress'        => 'En progreso',
        'completed'          => 'Completado',
        'cancelled'          => 'Cancelado',
        'void'               => 'Anulado',
        'disputed'           => 'En disputa',
    ];

    const REALMS = [
        'ignis' => 'Ignis',
        'syrtis' => 'Syrtis',
        'alsius' => 'Alsius',
    ];

    // La modalidad (2v2 / 3v3) se agrega aparte desde arena_mode.
    const QUEUE_MODES = [
        'random' => 'Random',
        'premade' => 'Premade',
    ];

    protected $fillable = [
        'match_code',
        'report_token',
        'queue_mode',
        'arena_mode',
        'team_a_queue_type',
        'team_b_queue_type',
        'team_a_realm',
        'team_b_realm',
        'team_a',
        'team_b',
        'team_a_party_signature',
        'team_b_party_signature',
        'team_ignis',
        'team_syrtis',
        'team_alsius',
        'zone',
        'status',
        'winner_team',
        'winner_realm',
        'estimated_mmr_avg',
        'accepted_at',
        'started_at',
        'completed_at',
        'reported_at',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'team_a' => 'array',
        'team_b' => 'array',
        'team_ignis' => 'array',
        'team_syrtis' => 'array',
        'team_alsius' => 'array',
        'accepted_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'reported_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function results()
    {
        return $this->hasMany(MatchResult::class, 'match_id');
    }

    public function report()
    {
        return $this->hasOne(MatchReport::class, 'match_id');
    }

    public function getAllPlayers()
    {
        $newTeams = collect()
            ->merge($this->team_a ?? [])
            ->merge($this->team_b ?? []);

        if ($newTeams->isNotEmpty()) {
            return $newTeams;
        }

        return collect()
            ->merge($this->team_ignis ?? [])
            ->merge($this->team_syrtis ?? [])
            ->merge($this->team_alsius ?? []);
    }

    public function getTeamBySide(string $side): array
    {
        return $this->{$side} ?? [];
    }

    public function getTeamPlayerIds(string $side): array
    {
        return collect($this->getTeamBySide($side))
            ->pluck('player_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function getTeamByRealm(string $realm): array
    {
        if ($this->team_a_realm === $realm) {
            return $this->team_a ?? [];
        }

        if ($this->team_b_realm === $realm) {
            return $this->team_b ?? [];
        }

        return $this->{'team_' . $realm} ?? [];
    }

    public function getTeamSideForPlayer(?int $playerId = null, ?string $discordId = null): ?string
    {
        foreach (['team_a', 'team_b'] as $side) {
            foreach ($this->getTeamBySide($side) as $player) {
                if ($playerId !== null && (int) ($player['player_id'] ?? 0) === $playerId) {
                    return $side;
                }

                if ($discordId !== null && (string) ($player['discord_id'] ?? '') === $discordId) {
                    return $side;
                }
            }
        }

        return null;
    }

    public function getOpponentRealmForPlayer(?int $playerId = null, ?string $discordId = null): ?string
    {
        $side = $this->getTeamSideForPlayer($playerId, $discordId);

        return match ($side) {
            'team_a' => $this->team_b_realm,
            'team_b' => $this->team_a_realm,
            default => null,
        };
    }

    public function getPlayerCountAttribute(): int
    {
        return $this->getAllPlayers()->count();
    }

    public function isExpired()
    {
        return $this->expires_at && now()->gt($this->expires_at);
    }

    public function isPendingAcceptance()
    {
        return $this->status === 'pending_acceptance';
    }

    public function isActive()
    {
        return in_array($this->status, ['pending_acceptance', 'accepted', 'in_progress'], true);
    }

    public function hasPendingReport(): bool
    {
        return $this->report !== null
            && in_array($this->report->status, ['pending_confirmation', 'rejected', 'disputed'], true);
    }

    public function getArenaModeLabelAttribute(): string
    {
        return \App\Support\ArenaMode::label($this->arena_mode);
    }

    public function getQueueModeNameAttribute()
    {
        $teamAType = $this->team_a_queue_type;
        $teamBType = $this->team_b_queue_type;
        $mode = $this->arena_mode_label;

        if ($teamAType && $teamBType && $teamAType !== $teamBType) {
            return 'Random vs Premade ' . $mode;
        }

        $queueMode = self::QUEUE_MODES[$this->queue_mode] ?? $this->queue_mode;

        return trim($queueMode . ' ' . $mode);
    }

    public function getTeamQueueType(string $side): string
    {
        $column = $side === 'team_b' ? 'team_b_queue_type' : 'team_a_queue_type';

        return (string) ($this->{$column} ?: $this->queue_mode ?: 'random');
    }

    public function getOpponentQueueTypeForSide(string $side): string
    {
        return $this->getTeamQueueType($side === 'team_a' ? 'team_b' : 'team_a');
    }

    public function getZoneKeyAttribute(): ?string
    {
        return self::normalizeZoneKey($this->zone);
    }

    public function getZoneNumberAttribute(): ?int
    {
        $metadata = self::zoneMetadata($this->zone);

        return $metadata['number'] ?? null;
    }

    public function getZoneNameAttribute()
    {
        $rawZone = trim((string) $this->zone);

        if ($rawZone === '') {
            return 'Zona pendiente de normalizar';
        }

        $label = self::zoneLabel($this->zone);
        if ($label !== null) {
            return $label;
        }

        if (in_array(Str::lower($rawZone), self::INVALID_ZONE_VALUES, true)) {
            return 'Zona invalida (' . Str::upper($rawZone) . ')';
        }

        return Str::headline(str_replace(['_', '-'], ' ', $rawZone));
    }

    public function getStatusNameAttribute()
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public static function generateMatchCode()
    {
        do {
            $code = 'ARENA-' . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        } while (self::where('match_code', $code)->exists());

        return $code;
    }

    public static function generateReportToken()
    {
        do {
            $token = strtoupper(Str::random(10));
        } while (self::where('report_token', $token)->exists());

        return $token;
    }

    public static function zoneKeys(): array
    {
        return array_keys(self::ZONES);
    }

    /**
     * Las zonas recomendadas para este cruce de reinos, en orden de
     * preferencia. Devuelve una lista vacia si el cruce no tiene recomendacion
     * (mismo reino, un reino desconocido, o un dato a medias).
     *
     * @return array<int, string>
     */
    public static function preferredZonesFor(?string $realmA, ?string $realmB): array
    {
        $realms = [Str::lower(trim((string) $realmA)), Str::lower(trim((string) $realmB))];

        if (in_array('', $realms, true) || $realms[0] === $realms[1]) {
            return [];
        }

        sort($realms);

        return self::ZONE_PREFERENCES[implode('|', $realms)] ?? [];
    }

    public static function zoneMetadata(?string $zone): ?array
    {
        $key = self::normalizeZoneKey($zone);

        return $key !== null ? (self::ZONES[$key] ?? null) : null;
    }

    public static function zoneLabel(array|string|null $zone): ?string
    {
        $metadata = is_array($zone) ? $zone : self::zoneMetadata($zone);

        if ($metadata === null) {
            return null;
        }

        return 'Zona ' . $metadata['number'] . ' - ' . $metadata['name'];
    }

    public static function normalizeZoneKey(?string $zone): ?string
    {
        $zone = trim((string) $zone);
        if ($zone === '') {
            return null;
        }

        if (isset(self::ZONES[$zone])) {
            return $zone;
        }

        $normalized = Str::of($zone)
            ->replace(['-', '_'], ' ')
            ->squish()
            ->lower()
            ->value();

        return self::ZONE_ALIASES[$normalized] ?? null;
    }
}
