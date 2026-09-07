<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use Illuminate\Support\Collection;

/**
 * Quien pelea contra quien, contado desde el lado del jugador que mira.
 *
 * Existe porque el aviso de cruce necesita exactamente los mismos datos que la
 * pagina del enfrentamiento, y esa regla no puede vivir escrita dos veces: el
 * ladder promete que los rivales son anonimos hasta que termina la partida, y
 * basta con que UNA de las dos pantallas se olvide para romper la promesa.
 */
class MatchLineupService
{
    /** Estados en los que ya se pueden ver los nombres del rival. */
    public const REVEAL_STATUSES = ['completed', 'disputed', 'void'];

    /**
     * @param  array<int, int>  $viewerPlayerIds  personajes de quien mira
     * @return array{
     *     viewer_player_id: int|null,
     *     viewer_accepted: bool,
     *     own: array<int, array{name: string, subclass: string, subclass_name: string, race: string, gender: string, accepted: bool, is_viewer: bool}>,
     *     rival: array<int, array{name: string, subclass: string, subclass_name: string, race: string, gender: string, accepted: bool, is_viewer: bool}>,
     *     own_realm: string|null,
     *     rival_realm: string|null,
     *     own_side: string,
     *     rival_side: string,
     *     accepted_count: int,
     *     player_count: int,
     *     names_revealed: bool
     * }|null
     */
    public function forViewer(ArenaMatch $match, array $viewerPlayerIds): ?array
    {
        $viewer = $match->getAllPlayers()->first(
            fn ($player) => in_array((int) ($player['player_id'] ?? 0), $viewerPlayerIds, true)
        );

        if (!$viewer) {
            return null;
        }

        $viewerPlayerId = (int) $viewer['player_id'];
        $ownSide = $match->getTeamSideForPlayer($viewerPlayerId, $viewer['discord_id'] ?? null) ?? 'team_a';
        $rivalSide = $ownSide === 'team_a' ? 'team_b' : 'team_a';

        $accepted = $this->acceptedPlayerIds($match);
        $revealed = in_array($match->status, self::REVEAL_STATUSES, true);

        $ownRealm = $ownSide === 'team_a' ? $match->team_a_realm : $match->team_b_realm;
        $rivalRealm = $rivalSide === 'team_a' ? $match->team_a_realm : $match->team_b_realm;

        $own = $this->line($match->getTeamBySide($ownSide), $accepted, $viewerPlayerId, true, $revealed, $ownRealm);
        $rival = $this->line($match->getTeamBySide($rivalSide), $accepted, $viewerPlayerId, false, $revealed, $rivalRealm);

        return [
            'viewer_player_id' => $viewerPlayerId,
            'viewer_accepted' => $accepted->contains($viewerPlayerId),
            'own' => $own,
            'rival' => $rival,
            'own_realm' => $ownRealm,
            'rival_realm' => $rivalRealm,
            'own_side' => $ownSide,
            'rival_side' => $rivalSide,
            'accepted_count' => $accepted->count(),
            'player_count' => (int) $match->player_count,
            'names_revealed' => $revealed,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $team
     */
    private function line(array $team, Collection $accepted, int $viewerPlayerId, bool $isOwnTeam, bool $revealed, ?string $realm = null): array
    {
        // El aspecto (raza y sexo) no viaja en el equipo guardado del
        // enfrentamiento, asi que se consulta. Solo para los propios: al rival
        // se le dibuja con el maniqui neutro del reino.
        $looks = collect();

        if ($isOwnTeam || $revealed) {
            $ids = collect($team)->pluck('player_id')->filter()->all();
            $looks = Player::query()->whereIn('id', $ids)->get(['id', 'race', 'gender'])->keyBy('id');
        }

        return collect($team)->map(function ($player) use ($accepted, $viewerPlayerId, $isOwnTeam, $revealed, $looks, $realm) {
            $playerId = (int) ($player['player_id'] ?? 0);
            $subclass = (string) ($player['subclass'] ?? 'knight');
            $playerRealm = (string) ($player['realm'] ?? $realm ?? 'ignis');

            // La subclase del rival si se ve (hace falta para preparar la
            // pelea); el nombre no, hasta el final.
            $showName = $isOwnTeam || $revealed;
            $look = $looks->get($playerId);

            return [
                'player_id' => $playerId,
                'name' => $showName ? (string) ($player['character_name'] ?? 'Sin nombre') : 'Guerrero Anónimo',
                'subclass' => $subclass,
                'subclass_name' => Player::SUBCLASSES[$subclass] ?? ucfirst($subclass),
                // La raza y el sexo del rival NO se publican: son rasgos que,
                // sumados al reino y la subclase que ya se ven, ayudarian a
                // ponerle nombre a quien todavia debe ser anonimo. Su figura se
                // dibuja con el maniqui humano del reino.
                'race' => $look?->race ?? Player::defaultRace($playerRealm),
                'gender' => $look?->gender ?? 'male',
                'accepted' => $accepted->contains($playerId),
                'is_viewer' => $playerId === $viewerPlayerId,
            ];
        })->values()->all();
    }

    /** Quien ha confirmado ya, leido de las colas enganchadas a este match. */
    private function acceptedPlayerIds(ArenaMatch $match): Collection
    {
        return Queue::query()
            ->where('match_id', (string) $match->id)
            ->where('status', 'accepted')
            ->pluck('player_id')
            ->map(fn ($id) => (int) $id);
    }
}
