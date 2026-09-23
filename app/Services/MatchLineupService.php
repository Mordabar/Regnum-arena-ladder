<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Support\ArenaMode;
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
    /**
     * Estados en los que ya se pueden ver los nombres del rival.
     *
     * Todos son finales: el combate termino y el anonimato ya no protege nada.
     * 'abandoned' y 'cancelled' entran por eso mismo -son partidas jugadas que
     * acabaron mal-, y estaban en la lista que la pagina del enfrentamiento se
     * calculaba por su cuenta. Una sola lista, aqui.
     */
    public const REVEAL_STATUSES = ['completed', 'disputed', 'void', 'abandoned', 'cancelled'];

    /**
     * Si a este enfrentamiento se le ven los nombres del rival.
     *
     * Dos motivos distintos: o la partida ya acabo, o es un duelo YA ACEPTADO.
     *
     * El duelo los publica porque con un rival suelto el anonimato deja de
     * proteger y empieza a estorbar: los dos llegan a la zona sin saber a quien
     * buscar, y entre varios cazadores del mismo reino se pelea con quien no
     * era. En 2v2 y 3v3 el anonimato sigue exactamente igual.
     *
     * Pero NO antes de aceptar. Enseñar el nombre en la pantalla de "combate
     * encontrado" convierte el boton de rechazar en un filtro: se acepta al que
     * conviene y se rechaza al que no, y el rechazado se come la espera sin
     * enterarse de por que. El nombre aparece cuando ya no se puede elegir.
     *
     * Vive aqui, junto al resto de la regla, y no repartido por las vistas: la
     * promesa de anonimato se rompe con que UNA pantalla se despiste.
     */
    public static function namesRevealed(ArenaMatch $match): bool
    {
        if (in_array($match->status, self::REVEAL_STATUSES, true)) {
            return true;
        }

        return ArenaMode::revealsRivalNames($match->arena_mode)
            && $match->status !== 'pending_acceptance';
    }

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
        $revealed = self::namesRevealed($match);

        $ownRealm = $ownSide === 'team_a' ? $match->team_a_realm : $match->team_b_realm;
        $rivalRealm = $rivalSide === 'team_a' ? $match->team_a_realm : $match->team_b_realm;

        $own = $this->line($match, $match->getTeamBySide($ownSide), $accepted, $viewerPlayerId, true, $revealed, $ownRealm);
        $rival = $this->line($match, $match->getTeamBySide($rivalSide), $accepted, $viewerPlayerId, false, $revealed, $rivalRealm);

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
     * El identificador con el que la pantalla se refiere a un luchador.
     *
     * Mientras el rival es anonimo NO puede ser su player_id. El id es la
     * direccion de su perfil publico -/ladder/player/{id}- asi que publicarlo
     * en un data- del HTML es publicar su nombre con un paso de mas: se mira el
     * inspector, se abre el perfil y ya se sabe contra quien juegas. Eso es
     * exactamente el filtro que el duelo acaba de cerrar, reabierto por detras.
     *
     * El sustituto es un hash del cruce y el jugador, firmado con la clave de
     * la aplicacion: distinto en cada enfrentamiento -asi que no sirve para
     * seguirle la pista de una partida a otra-, estable dentro de uno -asi que
     * el bocadillo sabe sobre que figura ponerse- e imposible de invertir sin
     * la clave.
     */
    public static function fighterId(ArenaMatch $match, int $playerId, bool $revelado = false): string
    {
        // Siempre el opaco, tambien con los nombres ya revelados.
        //
        // El `$revelado` se ignora a proposito. Esto solo sirve para casar un
        // aviso con su figura en la pantalla, y para eso el id de verdad no
        // hace falta. Cuando dependia del anonimato, el identificador cambiaba
        // justo en el instante en que se revelan los nombres: el HTML ya
        // pintado seguia con el opaco, el sondeo empezaba a mandar el crudo, y
        // los bocadillos dejaban de aparecer sobre nadie hasta el siguiente
        // repintado. Con uno solo, no hay instante en que discrepen.
        return substr(hash_hmac('sha256', $match->id . '|' . $playerId, (string) config('app.key')), 0, 12);
    }

    /**
     * @param  array<int, array<string, mixed>>  $team
     */
    private function line(ArenaMatch $match, array $team, Collection $accepted, int $viewerPlayerId, bool $isOwnTeam, bool $revealed, ?string $realm = null): array
    {
        // El aspecto (raza y sexo) no viaja en el equipo guardado del
        // enfrentamiento, asi que se consulta. Solo para los propios: al rival
        // se le dibuja con el maniqui neutro del reino.
        $looks = collect();

        // En el duelo, el rival es uno y su nombre se ve en cuanto aceptais:
        // esconder su raza y su sexo mientras tanto solo servia para enseñar
        // otra figura (el maniqui del reino) y confundir -una elfa salia como
        // esquelio-. Se dibuja como es desde el cruce.
        $duelo = ArenaMode::revealsRivalNames($match->arena_mode);

        if ($isOwnTeam || $revealed || $duelo) {
            $ids = collect($team)->pluck('player_id')->filter()->all();
            $looks = Player::query()->whereIn('id', $ids)->get(['id', 'race', 'gender'])->keyBy('id');
        }

        return collect($team)->map(function ($player) use ($match, $accepted, $viewerPlayerId, $isOwnTeam, $revealed, $looks, $realm) {
            $playerId = (int) ($player['player_id'] ?? 0);
            $subclass = (string) ($player['subclass'] ?? 'knight');
            $playerRealm = (string) ($player['realm'] ?? $realm ?? 'ignis');

            // La subclase del rival si se ve (hace falta para preparar la
            // pelea); el nombre no, hasta el final.
            $showName = $isOwnTeam || $revealed;
            $look = $looks->get($playerId);

            return [
                'player_id' => $playerId,
                // Lo que SI sale al HTML. El player_id se queda en el servidor
                // mientras el rival sea anonimo.
                'fighter_id' => self::fighterId($match, $playerId, $showName),
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
