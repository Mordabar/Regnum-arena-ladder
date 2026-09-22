<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\MatchPing;
use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Los avisos rapidos de un enfrentamiento: quien puede mandarlos y cuantos.
 *
 * Lo que faltaba era poder decir "voy de camino" sin salir de la pagina. Lo que
 * NO puede pasar es que eso se convierta en una forma de molestar al rival, asi
 * que hay tope: ni ráfagas, ni el mismo aviso repetido, ni avisos en un cruce
 * que ya termino.
 */
class MatchPingService
{
    /** Estados en los que todavia tiene sentido avisar de algo. */
    public const ESTADOS_ABIERTOS = ['pending_acceptance', 'accepted', 'in_progress'];

    /** Cuantos avisos se enseñan. Los de mas arriba ya no le importan a nadie. */
    public const HISTORIAL = 30;

    /**
     * Tope por jugador y minuto.
     *
     * Ocho da de sobra para avisar de todo lo que pasa en un combate y sigue
     * cortando la rafaga. Con doce, alternando las seis frases, se podia tener
     * al rival pitando y vibrando cada cinco segundos durante todo el cruce:
     * eso no es avisar, es una forma de molestar con otro nombre. El cliente
     * ademas espacia los pitidos, para que ni ocho seguidos suenen ocho veces.
     */
    private const POR_MINUTO = 8;

    /** Tope por jugador y enfrentamiento, de punta a punta. */
    private const POR_ENFRENTAMIENTO = 60;

    /**
     * Cuantas veces seguidas vale mandar EL MISMO aviso, y en cuanto tiempo.
     *
     * Antes era uno y a esperar quince segundos, y eso se cruzaba con el uso
     * normal: "voy de camino" dos veces porque el rival no contesta no es
     * spam, es insistir. Tres del mismo por minuto deja insistir y sigue
     * cortando a quien le de al boton sin parar.
     */
    private const MISMO_POR_MINUTO = 3;

    public function disponible(): bool
    {
        return Schema::hasTable('match_pings');
    }

    /** Si en este enfrentamiento todavia se puede avisar de algo. */
    public function abierto(?ArenaMatch $match): bool
    {
        return $match instanceof ArenaMatch
            && in_array((string) $match->status, self::ESTADOS_ABIERTOS, true);
    }

    /**
     * Manda un aviso.
     *
     * @return array{ok: bool, motivo?: string, ping?: MatchPing}
     */
    public function enviar(ArenaMatch $match, Player $player, string $code): array
    {
        if (!$this->disponible()) {
            return ['ok' => false, 'motivo' => 'Los avisos no estan disponibles todavia.'];
        }

        if (!MatchPing::esUnCodigo($code)) {
            return ['ok' => false, 'motivo' => 'Ese aviso no existe.'];
        }

        if (!$this->abierto($match)) {
            return ['ok' => false, 'motivo' => 'Este enfrentamiento ya esta cerrado.'];
        }

        if (!$this->juegaEnElCruce($match, $player)) {
            return ['ok' => false, 'motivo' => 'No juegas en este enfrentamiento.'];
        }

        $mios = MatchPing::query()
            ->where('match_id', (string) $match->id)
            ->where('player_id', $player->id);

        if ((clone $mios)->count() >= self::POR_ENFRENTAMIENTO) {
            return ['ok' => false, 'motivo' => 'Has mandado demasiados avisos en este combate.'];
        }

        if ((clone $mios)->where('created_at', '>=', now()->subMinute())->count() >= self::POR_MINUTO) {
            return ['ok' => false, 'motivo' => 'Vas muy rapido. Espera unos segundos.'];
        }

        $mismosSeguidos = (clone $mios)
            ->where('code', $code)
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        if ($mismosSeguidos >= self::MISMO_POR_MINUTO) {
            return ['ok' => false, 'motivo' => 'Ese aviso ya lo has mandado tres veces. Espera un poco.'];
        }

        $ping = MatchPing::create([
            'match_id' => (string) $match->id,
            'player_id' => $player->id,
            'code' => $code,
        ]);

        return ['ok' => true, 'ping' => $ping];
    }

    /**
     * Los avisos del enfrentamiento, del mas viejo al mas nuevo.
     *
     * Se devuelven ya resueltos -nombre, icono, texto- porque el sondeo los
     * pinta tal cual y no tiene a mano la tabla de jugadores.
     *
     * El `$viewer` no es un adorno: en 2v2 y 3v3 el rival es ANONIMO hasta que
     * el enfrentamiento se cierra, y un aviso firmado con su nombre seria la
     * forma mas tonta de saltarse esa regla. A quien mira desde el otro bando
     * le llega el aviso sin nombre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function historial(?ArenaMatch $match, Player|int|null $viewer = null): array
    {
        if (!$this->disponible() || !$match instanceof ArenaMatch) {
            return [];
        }

        // Basta con el id: de quien mira solo se necesita saber de que bando
        // es. Traer el jugador entero era una consulta por sondeo, cada pocos
        // segundos y por cada persona con el panel abierto, para no leer de el
        // mas que la clave primaria.
        $viewerId = $viewer instanceof Player ? (int) $viewer->id : $viewer;

        $pings = MatchPing::query()
            ->where('match_id', (string) $match->id)
            ->orderByDesc('id')
            ->limit(self::HISTORIAL)
            ->get();

        if ($pings->isEmpty()) {
            return [];
        }

        $nombres = $this->nombresDelCruce($match);
        $seVenLosNombres = MatchLineupService::namesRevealed($match);
        $miBando = $viewerId !== null ? $this->bandoDe($match, $viewerId) : null;

        return $pings
            ->sortBy('id')
            ->map(function (MatchPing $ping) use ($match, $nombres, $seVenLosNombres, $miBando) {
                $bando = $this->bandoDe($match, (int) $ping->player_id);
                $esMio = $miBando !== null && $bando === $miBando;

                return [
                    'id' => (int) $ping->id,
                    // El player_id NO sale de aqui mientras el rival sea
                    // anonimo: es la direccion de su perfil publico, asi que
                    // mandarlo es decir su nombre con un paso de mas.
                    'fid' => MatchLineupService::fighterId(
                        $match,
                        (int) $ping->player_id,
                        $seVenLosNombres || $esMio
                    ),
                    'nombre' => ($seVenLosNombres || $esMio)
                        ? ($nombres[(int) $ping->player_id] ?? 'Alguien')
                        : 'Rival',
                    'mio' => $esMio,
                    'bando' => $bando,
                    'code' => (string) $ping->code,
                    'texto' => $ping->texto(),
                    'icono' => $ping->icono(),
                    'tono' => $ping->tono(),
                    'en' => $ping->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /** Borra los avisos de enfrentamientos ya cerrados. */
    public function limpiarCerrados(): int
    {
        if (!$this->disponible()) {
            return 0;
        }

        // Los avisos de un cruce que ya no existe -se borran al cancelarse sin
        // jugarse- tambien se van: si no, se quedarian ahi para siempre.
        $vivos = ArenaMatch::query()
            ->whereIn('status', self::ESTADOS_ABIERTOS)
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        $query = MatchPing::query();

        if ($vivos->isNotEmpty()) {
            $query->whereNotIn('match_id', $vivos->all());
        }

        return $query->delete();
    }

    /** Borra los avisos de un enfrentamiento concreto. */
    public function limpiarDe(ArenaMatch|string|int $match): int
    {
        if (!$this->disponible()) {
            return 0;
        }

        $id = $match instanceof ArenaMatch ? (string) $match->id : (string) $match;

        return MatchPing::query()->where('match_id', $id)->delete();
    }

    private function juegaEnElCruce(ArenaMatch $match, Player $player): bool
    {
        foreach ($match->getAllPlayers() as $fila) {
            if ((int) ($fila['player_id'] ?? 0) === (int) $player->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * El nombre de cada jugador del cruce, tal y como se guardo al crearlo.
     *
     * Se lee del propio enfrentamiento y no de la tabla de jugadores: si
     * alguien se cambia el nombre a mitad de combate, el aviso sigue diciendo
     * a quien vio el rival cuando empezo.
     *
     * @return array<int, string>
     */
    private function nombresDelCruce(ArenaMatch $match): array
    {
        $nombres = [];

        foreach ($match->getAllPlayers() as $fila) {
            $id = (int) ($fila['player_id'] ?? 0);

            if ($id !== 0) {
                $nombres[$id] = (string) ($fila['character_name'] ?? 'Alguien');
            }
        }

        return $nombres;
    }

    private function bandoDe(ArenaMatch $match, int $playerId): string
    {
        return in_array($playerId, $match->getTeamPlayerIds('team_a'), true) ? 'a' : 'b';
    }
}
