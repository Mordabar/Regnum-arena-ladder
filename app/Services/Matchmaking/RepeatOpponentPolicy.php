<?php

namespace App\Services\Matchmaking;

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Queue;
use App\Support\ArenaMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Que no te toque siempre el mismo rival: el descanso entre revanchas, los
 * rivales recientes y la penalizacion por repetir cruces o jugadores.
 *
 * Parte del emparejador (antes todo vivia en ArenaMatchmakingService).
 */
class RepeatOpponentPolicy
{
    private const REPEAT_PAIR_WINDOW_HOURS = 24;

    /** Donde se apuntan las parejas de los cruces que se borran sin jugarse. */
    private const RIVALES_ANOTADOS_KEY = 'arena:rivales-recientes';

    /** Tope de parejas anotadas, para que la clave no crezca sin freno. */
    private const RIVALES_ANOTADOS_TOPE = 5000;
    private const HIGH_OVERLAP_PAIRING_PENALTY = 900;
    private const LIGHT_OVERLAP_PAIRING_PENALTY = 180;

    /**
     * Las parejas anotadas que siguen dentro del descanso.
     *
     * @return array<string, int>
     */
    private function rivalesAnotados(int $desde): array
    {
        $lista = Cache::get(self::RIVALES_ANOTADOS_KEY, []);

        if (!is_array($lista)) {
            return [];
        }

        return array_filter($lista, fn ($cuando) => is_int($cuando) && $cuando >= $desde);
    }

    /**
     * Guarda en cache las parejas de un cruce que se va a borrar.
     *
     * Una sola clave con todas las parejas y su hora, podada al escribir. Con
     * claves sueltas no habria forma de recuperarlas -no se puede listar la
     * cache por prefijo-, y preguntar cruce a cruce serian miles de lecturas
     * por barrido.
     *
     * Si dos procesos escriben a la vez, uno puede pisar al otro y perderse una
     * anotacion. Se acepta: lo peor que pasa es que a alguien le vuelva a tocar
     * el mismo rival, que es exactamente lo que pasaba siempre hasta ahora.
     */
    public function anotarRivalesDeUnCruceBorrado(ArenaMatch $match): void
    {
        $minutos = $this->minutosDeDescanso();

        if ($minutos <= 0) {
            return;
        }

        $ladoA = array_values(array_filter(array_map('intval', $match->getTeamPlayerIds('team_a'))));
        $ladoB = array_values(array_filter(array_map('intval', $match->getTeamPlayerIds('team_b'))));

        if ($ladoA === [] || $ladoB === []) {
            return;
        }

        $ahora = now()->timestamp;
        $lista = $this->rivalesAnotados($ahora - $minutos * 60);

        foreach ($ladoA as $unoA) {
            foreach ($ladoB as $unoB) {
                $lista[$this->claveDeRivales($unoA, $unoB)] = $ahora;
            }
        }

        // Tope duro por si alguna vez la poda por tiempo no basta: se quedan las
        // mas recientes, que son las que importan.
        if (count($lista) > self::RIVALES_ANOTADOS_TOPE) {
            arsort($lista);
            $lista = array_slice($lista, 0, self::RIVALES_ANOTADOS_TOPE, true);
        }

        Cache::put(self::RIVALES_ANOTADOS_KEY, $lista, now()->addMinutes($minutos + 1));
    }

    /**
     * Minutos durante los que repetir rival sale caro.
     */
    private function minutosDeDescanso(): int
    {
        $porDefecto = (int) config('arena.rematch_rest_minutes', 2);
        $minutos = (int) AppSetting::getValue('rematch_rest_minutes', $porDefecto);

        return max(0, min(720, $minutos));
    }

    private function claveDeRivales(int $uno, int $otro): string
    {
        return $uno < $otro ? "$uno:$otro" : "$otro:$uno";
    }

    /**
     * Quien se ha enfrentado a quien hace nada, persona a persona.
     *
     * Distinto de buildRecentPairHistory(), que mira EQUIPOS completos y solo
     * partidas TERMINADAS de las ultimas 24 h. Ese historico no sirve para lo
     * que pide el jugador: en 1v1 el equipo es una persona, pero sobre todo, en
     * el rato en que se juega una partida esa partida no esta completed, asi
     * que quien cancela y vuelve a entrar se reencuentra con el mismo rival al
     * instante. Aqui se miran las partidas por FECHA DE CREACION y en CUALQUIER
     * estado, que es justo el caso que molesta.
     *
     * Vale igual para 2v2 y 3v3: ahi cuenta cuantas personas del bando de
     * enfrente ya te tocaron, asi que repetir a uno pesa menos que repetir al
     * equipo entero.
     *
     * @return array<string, true>  claves "menor:mayor" de ids de jugador
     */
    public function buildRecentOpponents(): array
    {
        $minutos = $this->minutosDeDescanso();

        if ($minutos <= 0) {
            return [];
        }

        // Los cruces que nunca llegaron a jugarse ya no estan en la tabla: se
        // borran al cancelarlos. Sus parejas quedaron anotadas aparte.
        $rivales = array_map(fn () => true, $this->rivalesAnotados(now()->timestamp - $minutos * 60));

        ArenaMatch::query()
            // Solo las tres columnas que se miran. Esto corre dentro de la
            // peticion de quien entra a la cola, y el admin puede subir el
            // descanso a doce horas: traerse los enfrentamientos enteros de
            // doce horas seria pagar la memoria de media jornada por una lista
            // de parejas de numeros.
            ->select(['id', 'team_a', 'team_b'])
            // El filtro, el orden y el indice, los tres por created_at: pedir
            // el orden por id empuja al motor a recorrer la clave primaria
            // hacia atras hasta juntar el tope, y con dos minutos de ventana
            // casi nunca hay tantas, asi que se recorreria la tabla entera.
            ->where('created_at', '>=', now()->subMinutes($minutos))
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get()
            ->each(function (ArenaMatch $match) use (&$rivales) {
                $ladoA = array_values(array_filter(array_map('intval', $match->getTeamPlayerIds('team_a'))));
                $ladoB = array_values(array_filter(array_map('intval', $match->getTeamPlayerIds('team_b'))));

                foreach ($ladoA as $unoA) {
                    foreach ($ladoB as $unoB) {
                        $rivales[$this->claveDeRivales($unoA, $unoB)] = true;
                    }
                }
            });

        return $rivales;
    }

    /**
     * Cuantas parejas de rivales recientes se repetirian en este cruce.
     *
     * Se multiplica por RECENT_OPPONENT_PENALTY, que es deliberadamente enorme:
     * la regla es "que varie el rival", no "que no juegue". Con un recargo asi,
     * cualquier alternativa legal gana, y cuando de verdad no hay nadie mas el
     * cruce repetido sigue siendo el unico candidato y se hace igual. Nadie se
     * queda en cola por esto.
     */
    public function contarRivalesRepetidos(array $teamA, array $teamB, array $rivalesRecientes): int
    {
        if ($rivalesRecientes === []) {
            return 0;
        }

        $idsA = $this->idsDeEquipo($teamA);
        $idsB = $this->idsDeEquipo($teamB);
        $repetidos = 0;

        foreach ($idsA as $unoA) {
            foreach ($idsB as $unoB) {
                if (isset($rivalesRecientes[$this->claveDeRivales($unoA, $unoB)])) {
                    $repetidos++;
                }
            }
        }

        return $repetidos;
    }

    /**
     * @return array<int, int>
     */
    private function idsDeEquipo(array $team): array
    {
        return $team['entries']
            ->map(fn (Queue $queue) => (int) $queue->player->id)
            ->filter()
            ->values()
            ->all();
    }

    public function buildRecentPairHistory(): array
    {
        return ArenaMatch::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subHours(self::REPEAT_PAIR_WINDOW_HOURS))
            ->get()
            ->reduce(function (array $history, ArenaMatch $match) {
                $teamASignature = $this->teamSignatureFromPlayerIds($match->getTeamPlayerIds('team_a'));
                $teamBSignature = $this->teamSignatureFromPlayerIds($match->getTeamPlayerIds('team_b'));

                if ($teamASignature === '' || $teamBSignature === '') {
                    return $history;
                }

                $key = $this->pairingHistoryKeyFromSignatures($teamASignature, $teamBSignature);
                $history[$key] = ($history[$key] ?? 0) + 1;

                return $history;
            }, []);
    }

    public function buildRecentMatchSnapshots(): Collection
    {
        return ArenaMatch::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subHours(self::REPEAT_PAIR_WINDOW_HOURS))
            ->get()
            ->map(function (ArenaMatch $match) {
                return [
                    'arena_mode' => ArenaMode::resolve($match->arena_mode),
                    'team_a_ids' => $match->getTeamPlayerIds('team_a'),
                    'team_b_ids' => $match->getTeamPlayerIds('team_b'),
                ];
            });
    }

    public function getRepeatPairCount(array $teamA, array $teamB, array $recentPairHistory): int
    {
        $key = $this->pairingHistoryKey($teamA, $teamB);

        return (int) ($recentPairHistory[$key] ?? 0);
    }

    private function pairingHistoryKey(array $teamA, array $teamB): string
    {
        $teamASignature = $this->teamSignatureFromEntries($teamA['entries']);
        $teamBSignature = $this->teamSignatureFromEntries($teamB['entries']);

        return $this->pairingHistoryKeyFromSignatures($teamASignature, $teamBSignature);
    }

    private function pairingHistoryKeyFromSignatures(string $teamASignature, string $teamBSignature): string
    {
        $signatures = [$teamASignature, $teamBSignature];
        sort($signatures);

        return implode('|', $signatures);
    }

    private function teamSignatureFromEntries(Collection $entries): string
    {
        $playerIds = $entries
            ->map(fn (Queue $queue) => (int) $queue->player->id)
            ->all();

        return $this->teamSignatureFromPlayerIds($playerIds);
    }

    private function teamSignatureFromPlayerIds(array $playerIds): string
    {
        $playerIds = array_values(array_filter(array_map('intval', $playerIds)));
        sort($playerIds);

        return implode('-', $playerIds);
    }

    public function calculateRepeatOverlapPenalty(array $teamA, array $teamB, Collection $recentMatchSnapshots): int
    {
        $arenaMode = ArenaMode::resolve($teamA['arena_mode'] ?? null);
        $teamSize = ArenaMode::teamSize($arenaMode);
        $teamAIds = $teamA['entries']->map(fn (Queue $queue) => (int) $queue->player->id)->all();
        $teamBIds = $teamB['entries']->map(fn (Queue $queue) => (int) $queue->player->id)->all();

        return $recentMatchSnapshots->reduce(function (int $carry, array $snapshot) use ($arenaMode, $teamSize, $teamAIds, $teamBIds) {
            // Solo se compara contra partidas de la misma modalidad: el
            // solapamiento de un 3v3 no es equiparable al de un 2v2, porque
            // "cuantos jugadores se repiten" significa cosas distintas.
            if (($snapshot['arena_mode'] ?? ArenaMode::FALLBACK) !== $arenaMode) {
                return $carry;
            }

            $forwardPenalty = $this->calculateOverlapPenalty(
                $this->countPlayerOverlap($teamAIds, $snapshot['team_a_ids']),
                $this->countPlayerOverlap($teamBIds, $snapshot['team_b_ids']),
                $teamSize
            );

            $reversePenalty = $this->calculateOverlapPenalty(
                $this->countPlayerOverlap($teamAIds, $snapshot['team_b_ids']),
                $this->countPlayerOverlap($teamBIds, $snapshot['team_a_ids']),
                $teamSize
            );

            return max($carry, $forwardPenalty, $reversePenalty);
        }, 0);
    }

    /**
     * El umbral alto es "se repite el equipo completo", asi que depende del
     * tamaño: 2 en 2v2 (identico al comportamiento anterior) y 3 en 3v3. Con un
     * 2 fijo, en 3v3 un solapamiento parcial de 2 de 3 se penalizaba al maximo.
     */
    private function calculateOverlapPenalty(int $teamAOverlap, int $teamBOverlap, int $teamSize = 2): int
    {
        if ($teamAOverlap >= $teamSize && $teamBOverlap >= $teamSize) {
            return self::HIGH_OVERLAP_PAIRING_PENALTY;
        }

        if ($teamAOverlap >= 1 && $teamBOverlap >= 1) {
            return self::LIGHT_OVERLAP_PAIRING_PENALTY;
        }

        return 0;
    }

    private function countPlayerOverlap(array $currentPlayers, array $previousPlayers): int
    {
        return count(array_intersect(
            array_map('intval', $currentPlayers),
            array_map('intval', $previousPlayers)
        ));
    }
}
