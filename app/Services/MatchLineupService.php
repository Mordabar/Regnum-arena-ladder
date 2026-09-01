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
     *     own: array<int, array{name: string, subclass: string, subclass_name: string, accepted: bool, is_viewer: bool}>,
     *     rival: array<int, array{name: string, subclass: string, subclass_name: string, accepted: bool, is_viewer: bool}>,
     *     own_realm: string|null,
     *     rival_realm: string|null,
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

        $own = $this->line($match->getTeamBySide($ownSide), $accepted, $viewerPlayerId, true, $revealed);
        $rival = $this->line($match->getTeamBySide($rivalSide), $accepted, $viewerPlayerId, false, $revealed);

        return [
            'viewer_player_id' => $viewerPlayerId,
            'viewer_accepted' => $accepted->contains($viewerPlayerId),
            'own' => $own,
            'rival' => $rival,
            'own_realm' => $ownSide === 'team_a' ? $match->team_a_realm : $match->team_b_realm,
            'rival_realm' => $rivalSide === 'team_a' ? $match->team_a_realm : $match->team_b_realm,
            'accepted_count' => $accepted->count(),
            'player_count' => (int) $match->player_count,
            'names_revealed' => $revealed,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $team
     */
    private function line(array $team, Collection $accepted, int $viewerPlayerId, bool $isOwnTeam, bool $revealed): array
    {
        return collect($team)->map(function ($player) use ($accepted, $viewerPlayerId, $isOwnTeam, $revealed) {
            $playerId = (int) ($player['player_id'] ?? 0);
            $subclass = (string) ($player['subclass'] ?? 'knight');

            // La subclase del rival si se ve (hace falta para preparar la
            // pelea); el nombre no, hasta el final.
            $showName = $isOwnTeam || $revealed;

            return [
                'player_id' => $playerId,
                'name' => $showName ? (string) ($player['character_name'] ?? 'Sin nombre') : 'Guerrero Anónimo',
                'subclass' => $subclass,
                'subclass_name' => Player::SUBCLASSES[$subclass] ?? ucfirst($subclass),
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
