<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Support\ArenaMode;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ArenaMatchmakingService
{
    /**
     * Estados desde los que un match todavia se puede cancelar. Una vez que
     * pasa a in_progress la partida ya se esta jugando y solo el admin puede
     * deshacerla (markVoid), que si controla los puntos ya otorgados.
     */
    public const CANCELLABLE_STATUSES = ['pending_acceptance', 'accepted'];

    private const PREMADE_DAILY_LIMIT = 3;
    private const REPEAT_PAIR_WINDOW_HOURS = 24;
    private const EXACT_REPEAT_PAIRING_PENALTY = 10000;
    private const HIGH_OVERLAP_PAIRING_PENALTY = 900;
    private const LIGHT_OVERLAP_PAIRING_PENALTY = 180;
    private const TEAM_SEARCH_WINDOW = 10;
    private const TEAM_DUPLICATE_SUBCLASS_PENALTY = 14;
    private const TEAM_TRIPLE_SUBCLASS_PENALTY = 28;
    private const TEAM_EXTRA_CONJURER_PENALTY = 8;
    private const TEAM_ARCHETYPE_PENALTY = 6;
    private const PAIR_CONJURER_MISMATCH_PENALTY = 12;
    private const PAIR_SUPPORT_MISMATCH_PENALTY = 16;
    private const PAIR_SUBCLASS_MISMATCH_WEIGHT = 5;

    /**
     * Cuantos vecinos de MMR mira cada equipo al buscar rival.
     *
     * Puntuar la cola entera contra la cola entera es lo que no escala, y no
     * hace falta: ordenados por MMR, un equipo nunca se empareja con otro que
     * tenga ochenta puestos por medio salvo que no le quede nadie mas. Ochenta
     * es holgado de sobra -con tres reinos repartidos, ahi dentro caben mas de
     * cincuenta rivales de reino contrario-, y si aun asi alguien se queda
     * suelto, emparejarRezagados() le busca pareja sin ventana ninguna.
     */
    private const PAIRING_MMR_WINDOW = 80;

    /**
     * Tope de pasadas de mejora por intercambio.
     *
     * En la practica converge en dos o tres. El tope existe para que la mejora
     * no pueda convertirse en el problema de rendimiento que venia a arreglar.
     */
    private const PAIRING_IMPROVEMENT_PASSES = 6;

    /**
     * Cuantos cruces vecinos mira cada cruce al buscar un intercambio.
     *
     * Mismo motivo que la ventana de MMR del barrido: dos cruces solo se
     * mejoran intercambiando rivales si los cuatro andan por el mismo nivel.
     * Veinte vecinos cubren de sobra los intercambios que de verdad salen, y
     * es la diferencia entre que la cola llena tarde dos segundos o un minuto.
     */
    private const PAIRING_SWAP_WINDOW = 20;

    /**
     * Vueltas de rescate de sueltos.
     *
     * Cada vuelta coloca a todos los que encuentran donante cerca, asi que con
     * dos o tres ya no queda nadie. El tope existe para que esto no pueda
     * quedarse dando vueltas si algun dia el grafo deja de ser el que es.
     */
    private const PAIRING_AUGMENT_ROUNDS = 8;

    /** Vueltas de pulido alternando los dos tipos de mejora. */
    private const PAIRING_POLISH_ROUNDS = 3;

    /** Turno unico para emparejar, para que no barran varios a la vez. */
    private const PAIRING_LOCK = 'arena:emparejamiento';

    /** Lo que dura el turno antes de caducar solo, en segundos. */
    private const PAIRING_LOCK_TTL = 120;

    /** Lo que espera quien llega y lo encuentra ocupado, en segundos. */
    private const PAIRING_LOCK_WAIT = 6;

    /**
     * Cuanto MMR tiene que encajar mejor un rival de otro estilo para ganarle
     * al espejo.
     *
     * El duelo prefiere el espejo -cazador contra cazador-, pero el MMR manda.
     * La forma es deliberadamente aditiva y continua: la cifra se suma a la
     * diferencia de MMR del cruce, asi que un rival de otra clase sale elegido
     * en cuanto encaje mejor A PARTIR de estos puntos -los empates exactos se
     * los lleva el MMR por el desempate de buildMatchPairings-. Leido al reves,
     * que es lo que importa: la preferencia NUNCA puede imponer un rival que
     * encaje peor por 20 puntos de MMR o mas. El techo real, medido barriendo
     * distancias, es 19 al cruzar clase y 7 al cambiar de subclase.
     *
     * De donde sale el 20: una partida mueve el MMR unos 16 puntos. O sea que
     * el techo del capricho es, como mucho, lo que se gana o se pierde en un
     * combate. Por debajo de eso los dos rivales son el mismo rival a efectos
     * practicos y se elige el que hace la pelea mas limpia de leer.
     *
     * Dos formas descartadas, y por que:
     *
     * - 70 y 25, los primeros valores. Demasiado: un espejo a 69 de MMR le
     *   ganaba a un rival de otra clase con el MMR clavado.
     * - Agrupar la diferencia de MMR en escalones de 50 y dejar el estilo como
     *   calderilla. Parecia mas limpio y era peor: el techo seguia siendo 49,
     *   pero ademas saltaba de golpe en cada borde de escalon -un punto de MMR
     *   invertia la decision- y, sobre todo, este ladder arranca con todo el
     *   mundo en 1000, asi que durante las primeras semanas la gente entera
     *   cabia en el primer escalon y el estilo decidia TODOS los duelos.
     */
    private const DUEL_ARCHETYPE_MISMATCH_PENALTY = 20;
    private const DUEL_SUBCLASS_MISMATCH_PENALTY = 8;

    private ?Collection $matchesColumnsCache = null;

    /** Si esta instancia ya tiene el turno del barrido. */
    private bool $barriendo = false;

    public function __construct(
        private readonly DiscordBotService $discordBotService
    ) {
    }

    public function isMatchesSchemaReady(): bool
    {
        $columns = $this->getMatchesColumns();

        if ($columns->isEmpty()) {
            return false;
        }

        $requiredColumns = [
            'match_code',
            'report_token',
            'queue_mode',
            'arena_mode',
            'team_a_realm',
            'team_b_realm',
            'team_a',
            'team_b',
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
            'created_at',
            'updated_at',
        ];

        foreach ($requiredColumns as $column) {
            if (!$columns->has($column)) {
                return false;
            }
        }

        $zoneColumn = $columns->get('zone');
        if (is_array($zoneColumn)) {
            $enumOptions = $this->extractEnumOptions((string) ($zoneColumn['type'] ?? ''));
            if (
                $enumOptions !== []
                && collect($enumOptions)->map(fn (string $zone) => ArenaMatch::normalizeZoneKey($zone))->filter()->isEmpty()
            ) {
                return false;
            }
        }

        return true;
    }

    public function processRandomQueue(bool $expirePendingMatches = true): int
    {
        return $this->processQueue($expirePendingMatches);
    }

    /**
     * Empareja la cola, pero de uno en uno en todo el servidor.
     *
     * Esto corre dentro de la peticion HTTP de quien entra a la cola. Si entran
     * cinco personas a la vez, antes se lanzaban cinco barridos en paralelo
     * sobre las mismas filas: cinco veces el mismo trabajo, cinco tandas de
     * candados sobre `queues` peleandose entre ellas, y en un hosting
     * compartido eso es la pagina cayendose, no yendo lenta.
     *
     * Con el candado, el primero barre y los demas esperan su turno un momento
     * -no mucho- y barren despues, ya con las filas nuevas dentro. Quien no
     * consiga el turno a tiempo no pierde nada: su fila esta guardada y la coge
     * el barrido siguiente o el reloj del minuto. Lo unico que se pierde es la
     * respuesta inmediata "ya tienes rival", y es preferible a tumbar el sitio.
     *
     * El candado caduca solo, para que un proceso muerto a media faena no deje
     * la cola congelada.
     */
    public function processQueue(bool $expirePendingMatches = true): int
    {
        // Las llamadas que salen del propio barrido -cancelar un cruce vencido
        // vuelve a emparejar- ya estan dentro del turno. Volver a pedirlo seria
        // esperarse a uno mismo.
        if ($this->barriendo) {
            return $this->barrerCola($expirePendingMatches);
        }

        try {
            return Cache::lock(self::PAIRING_LOCK, self::PAIRING_LOCK_TTL)
                ->block(self::PAIRING_LOCK_WAIT, function () use ($expirePendingMatches) {
                    $this->barriendo = true;

                    try {
                        return $this->barrerCola($expirePendingMatches);
                    } finally {
                        $this->barriendo = false;
                    }
                });
        } catch (LockTimeoutException $e) {
            Log::info('ArenaMatchmakingService: otro barrido tenia el turno, se deja para el siguiente.');

            return 0;
        }
    }

    private function barrerCola(bool $expirePendingMatches): int
    {
        if (!$this->isMatchesSchemaReady()) {
            Log::warning('ArenaMatchmakingService skipped: matches schema is not ready.');
            return 0;
        }

        if ($expirePendingMatches) {
            $this->expirePendingAcceptanceMatches(false);
        }

        // Solo se procesan las modalidades encendidas. Si el admin apago todas,
        // enabled() viene vacio y no se arma ningun equipo.
        $enabledModes = ArenaMode::enabled();

        if ($enabledModes === []) {
            Log::warning('ArenaMatchmakingService: no hay modalidades activas, no se procesa la cola.');

            return 0;
        }

        $randomWaitingQueues = Queue::query()
            ->where('queue_type', 'random')
            ->whereIn('arena_mode', $enabledModes)
            ->where('status', 'waiting')
            ->whereNull('match_id')
            ->whereHas('player', function ($query) {
                // Un jugador sancionado no vuelve a emparejarse aunque su fila
                // de cola siga viva: cancelMatch reencola sin revalidar, y
                // enqueueParty puede lanzar una party creada horas antes.
                $query->where('is_active', true)
                    ->where(function ($lockQuery) {
                        $lockQuery->whereNull('queue_locked_until')
                            ->orWhere('queue_locked_until', '<=', now());
                    });
            })
            ->with(['player.user'])
            ->orderBy('joined_at')
            ->get();

        $premadeWaitingQueues = Queue::query()
            ->where('queue_type', 'premade')
            ->whereIn('arena_mode', $enabledModes)
            ->where('status', 'waiting')
            ->whereNull('match_id')
            ->whereNotNull('team_id')
            ->whereHas('player', function ($query) {
                // Un jugador sancionado no vuelve a emparejarse aunque su fila
                // de cola siga viva: cancelMatch reencola sin revalidar, y
                // enqueueParty puede lanzar una party creada horas antes.
                $query->where('is_active', true)
                    ->where(function ($lockQuery) {
                        $lockQuery->whereNull('queue_locked_until')
                            ->orWhere('queue_locked_until', '<=', now());
                    });
            })
            ->with(['player.user'])
            ->orderBy('joined_at')
            ->get();

        $candidateTeams = collect();

        // Se agrupa primero por modalidad y despues por reino: un equipo nunca
        // puede mezclar jugadores de colas 2v2 y 3v3.
        foreach ($randomWaitingQueues->groupBy('arena_mode') as $arenaMode => $modeQueues) {
            foreach ($modeQueues->groupBy(fn (Queue $queue) => $queue->player->realm) as $realm => $queues) {
                $candidateTeams = $candidateTeams->merge(
                    // resolve() canoniza: la clave del groupBy es el valor crudo
                    // de la BD y los equipos premade se etiquetan normalizados.
                    // Si no coincidieran, random y premade no se emparejarian.
                    $this->buildRealmTeams(ArenaMode::resolve((string) $arenaMode), (string) $realm, $queues)
                );
            }
        }

        $candidateTeams = $candidateTeams->merge(
            $this->buildPremadeTeams($premadeWaitingQueues)
        );

        $pairings = $this->buildMatchPairings($candidateTeams);
        $matchesCreated = 0;

        // Las zonas ocupadas se leen UNA vez por barrido y se van actualizando
        // en memoria conforme se crean partidas. Ver pickZone().
        $activeMatches = $this->cargarEnfrentamientosVivos();

        foreach ($pairings as $pairing) {
            try {
                $match = DB::transaction(function () use ($pairing, $activeMatches) {
                    return $this->createArenaMatch($pairing['team_a'], $pairing['team_b'], $activeMatches);
                });
            } catch (\Throwable $e) {
                Log::warning('ArenaMatchmakingService skipped stale pairing', [
                'team_a_realm' => $pairing['team_a']['realm'],
                'team_b_realm' => $pairing['team_b']['realm'],
                'team_a_type' => $pairing['team_a']['queue_type'] ?? 'random',
                'team_b_type' => $pairing['team_b']['queue_type'] ?? 'random',
                'message' => $e->getMessage(),
            ]);

                continue;
            }

            if (!$match instanceof ArenaMatch) {
                continue;
            }

            // La zona que acaba de ocuparse cuenta para la siguiente partida
            // del mismo barrido, igual que contaria si se releyera la base.
            $activeMatches->push($match);

            try {
                $this->discordBotService->notifyMatchFound($match);
            } catch (\Throwable $e) {
                Log::error('ArenaMatchmakingService notifyMatchFound failed', [
                    'match_id' => $match->id,
                    'match_code' => $match->match_code,
                    'message' => $e->getMessage(),
                ]);
            }

            $matchesCreated++;
        }

        return $matchesCreated;
    }

    public function expirePendingAcceptanceMatches(bool $rerunMatchmaking = true): int
    {
        if (!$this->isMatchesSchemaReady()) {
            return 0;
        }

        $expiredMatches = ArenaMatch::query()
            ->where('status', 'pending_acceptance')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredMatches as $match) {
            $this->cancelMatch($match, 'timeout', null, false);
        }

        if ($rerunMatchmaking && $expiredMatches->isNotEmpty()) {
            $this->processRandomQueue(false);
        }

        return $expiredMatches->count();
    }

    public function countPartyMatchesTodayForPlayers(iterable $playerIds, ?string $arenaMode = null): int
    {
        $arenaMode = ArenaMode::resolve($arenaMode);

        $players = Player::query()
            ->whereIn('id', collect($playerIds)->map(fn ($id) => (int) $id)->all())
            ->get();

        if ($players->count() !== ArenaMode::teamSize($arenaMode)) {
            return 0;
        }

        $signature = $this->buildPartySignatureFromUserIds(
            $players->pluck('user_id')->all()
        );

        return $this->countPartyMatchesToday($signature);
    }

    public function getPremadeDailyLimit(): int
    {
        return $this->premadeDailyLimit();
    }

    /**
     * Borra el cruce que nunca llego a ser partida.
     *
     * Si nadie acepto, no hubo combate: no es historial de nadie y no tiene por
     * que ocupar una fila. Antes se quedaban como 'cancelled' y ensuciaban el
     * historial de los jugadores y el listado del panel con enfrentamientos que
     * no existieron.
     *
     * Lo que si sobrevive es la cuenta del jugador: los strikes, la confianza y
     * el bloqueo de cola viven en `players`, asi que borrar el cruce no borra
     * la sancion de quien lo tumbo.
     */
    private function descartarCruceSinPartida(ArenaMatch $match): void
    {
        $matchId = (string) $match->id;

        // Ninguna cola puede quedar apuntando a una fila que ya no existe.
        Queue::query()->where('match_id', $matchId)->update(['match_id' => null]);

        // Salvaguarda: si por lo que sea hubiera reporte, resultados o avisos de
        // abandono, esto no era un cruce sin partida y no se toca. Los avisos
        // cuentan tanto como lo demas: si alguien denuncio un abandono es que
        // el combate se estaba jugando.
        if ($match->results()->exists()
            || $match->report()->exists()
            || $match->abandonmentReports()->exists()) {
            $match->update(['status' => 'cancelled']);

            return;
        }

        $match->delete();
    }

    public function cancelMatch(
        ArenaMatch $match,
        string $reason = 'timeout',
        ?int $offendingPlayerId = null,
        bool $rerunMatchmaking = true
    ): void {
        if (!$this->isMatchesSchemaReady()) {
            return;
        }

        // Lista blanca en vez de lista negra: un match solo se puede cancelar
        // mientras nadie haya empezado a jugarlo. Con la lista negra anterior,
        // 'in_progress' y 'disputed' NO estaban cubiertos, asi que un jugador
        // que iba perdiendo podia cancelar el match por la ruta de rechazo y
        // escaparse de la derrota, o borrar una disputa antes de que el admin
        // la resolviera. Para deshacer un match ya empezado esta markVoid(),
        // que ademas verifica que no haya resultados puntuados.
        if (!in_array($match->status, self::CANCELLABLE_STATUSES, true)) {
            return;
        }

        DB::transaction(function () use ($match, $reason, $offendingPlayerId) {
            $matchId = (string) $match->id;
            $queues = Queue::query()
                ->where('match_id', $matchId)
                ->whereIn('status', ['matched', 'accepted'])
                ->get();

            // Aqui solo se llega con un cruce que nunca empezo: la lista blanca
            // de arriba deja fuera in_progress y disputed. Y un cruce que nadie
            // acepto no es una partida, es un emparejamiento que no cuajo, asi
            // que no deja rastro: ni historial, ni fila. Se borra al final,
            // despues de devolver a la cola a quien toque.
            $match->update([
                'expires_at' => null,
                'notes' => trim(($match->notes ?? '') . "\nCancel reason: {$reason}"),
            ]);

            if ($queues->isEmpty()) {
                $this->descartarCruceSinPartida($match);

                return;
            }

            $groupedQueues = $queues->groupBy(fn (Queue $queue) => $queue->team_id ?: 'solo-' . $queue->id);

            foreach ($groupedQueues as $queueGroup) {
                $isPremade = $queueGroup->contains(fn (Queue $queue) => $queue->queue_type === 'premade');
                $containsOffender = $offendingPlayerId !== null
                    && $queueGroup->contains(fn (Queue $queue) => (int) $queue->player_id === $offendingPlayerId);
                $fullyAccepted = $queueGroup->every(fn (Queue $queue) => $queue->status === 'accepted');

                if ($reason === 'player_rejected') {
                    if ($containsOffender) {
                        if ($isPremade) {
                            $this->cancelQueueGroup($queueGroup, true);
                        } else {
                            $offenderQueue = $queueGroup->where('player_id', $offendingPlayerId);
                            $this->cancelQueueGroup($offenderQueue, false);

                            $others = $queueGroup->where('player_id', '!=', $offendingPlayerId);
                            if ($others->isNotEmpty()) {
                                Queue::query()
                                    ->whereIn('id', $others->pluck('id'))
                                    ->update([
                                        'status' => 'waiting',
                                        'matched_at' => null,
                                        'expires_at' => now()->addMinutes(30),
                                        'team_id' => null,
                                        'match_id' => null,
                                        'joined_at' => now(),
                                    ]);
                            }
                        }
                    } else {
                        if ($isPremade) {
                            $this->requeuePremadeGroup($queueGroup);
                        } else {
                            Queue::query()
                                ->whereIn('id', $queueGroup->pluck('id'))
                                ->update([
                                    'status' => 'waiting',
                                    'matched_at' => null,
                                    'expires_at' => now()->addMinutes(30),
                                    'team_id' => null,
                                    'match_id' => null,
                                    'joined_at' => now(),
                                ]);
                        }
                    }
                    continue;
                }

                if ($reason === 'timeout' && !$fullyAccepted) {
                    if ($isPremade) {
                        $this->cancelQueueGroup($queueGroup, true);
                    } else {
                        $this->resetRandomQueueGroup($queueGroup);
                    }

                    continue;
                }

                if ($isPremade) {
                    $this->requeuePremadeGroup($queueGroup);
                } else {
                    $this->resetRandomQueueGroup($queueGroup);
                }
            }

            $this->descartarCruceSinPartida($match);
        });

        $this->discordBotService->notifyMatchCancelled($match, $reason);

        if ($rerunMatchmaking) {
            $this->processRandomQueue(false);
        }
    }

    private function buildRealmTeams(string $arenaMode, string $realm, Collection $queues): Collection
    {
        $teamSize = ArenaMode::teamSize($arenaMode);

        $available = $queues
            ->sortBy(fn (Queue $queue) => $queue->estimated_mmr ?? $queue->player->mmr ?? 800)
            ->values();

        $teams = collect();

        while ($available->count() >= $teamSize) {
            $teamEntries = $this->findBestRealmTeam($available, $teamSize);

            if ($teamEntries->count() !== $teamSize) {
                break;
            }

            $teams->push([
                'team_id' => (string) Str::uuid(),
                'arena_mode' => $arenaMode,
                'realm' => $realm,
                'queue_type' => 'random',
                'party_signature' => null,
                'avg_mmr' => (int) round($teamEntries->avg(function (Queue $queue) {
                    return $queue->estimated_mmr ?? $queue->player->mmr ?? 800;
                })),
                'entries' => $teamEntries->values(),
                'profile' => $this->buildQueueTeamProfile($teamEntries),
            ]);

            $available = $available
                ->reject(fn (Queue $queue) => $teamEntries->contains('id', $queue->id))
                ->values();
        }

        return $teams;
    }

    private function buildPremadeTeams(Collection $queues): Collection
    {
        return $queues
            // La clave incluye la modalidad para que un mismo team_id no pueda
            // arrastrar entradas de 2v2 y 3v3 al mismo equipo.
            ->groupBy(fn (Queue $queue) => $queue->arena_mode . '|' . $queue->team_id)
            ->map(function (Collection $teamEntries, string $groupKey) {
                $arenaMode = ArenaMode::resolve($teamEntries->first()->arena_mode);
                $teamSize = ArenaMode::teamSize($arenaMode);
                $teamId = str_contains($groupKey, '|') ? explode('|', $groupKey, 2)[1] : $groupKey;

                if ($teamEntries->count() !== $teamSize) {
                    return null;
                }

                $realms = $teamEntries->map(fn (Queue $queue) => (string) $queue->player->realm)->unique()->values();
                if ($realms->count() !== 1) {
                    return null;
                }

                if ($teamEntries->map(fn (Queue $queue) => (int) $queue->player->user_id)->unique()->count() !== $teamSize) {
                    return null;
                }

                $evaluation = $this->evaluateQueueTeam($teamEntries);
                if ($evaluation === null) {
                    return null;
                }

                $partySignature = $this->resolvePartySignature($teamEntries);
                if ($this->countPartyMatchesToday($partySignature) >= $this->premadeDailyLimit()) {
                    return null;
                }

                return [
                    'team_id' => $teamId,
                    'arena_mode' => $arenaMode,
                    'realm' => (string) $realms->first(),
                    'queue_type' => 'premade',
                    'party_signature' => $partySignature,
                    'avg_mmr' => (int) round($teamEntries->avg(function (Queue $queue) {
                        return $queue->estimated_mmr ?? $queue->player->mmr ?? 800;
                    })),
                    'entries' => $teamEntries->values(),
                    'profile' => $evaluation['profile'],
                ];
            })
            ->filter()
            ->values();
    }

    private function findBestRealmTeam(Collection $available, int $teamSize): Collection
    {
        $count = $available->count();

        if ($count < $teamSize) {
            return collect();
        }

        $windowSize = min($count, self::TEAM_SEARCH_WINDOW);
        $bestTeam = null;
        $bestSpread = null;
        $bestScore = null;

        // Se evalua cada combinacion posible dentro de la ventana de busqueda.
        // Con TEAM_SEARCH_WINDOW = 10 son 45 combinaciones en 2v2 y 120 en 3v3.
        foreach ($this->combinationIndexes($windowSize, $teamSize) as $indexes) {
            $team = collect($indexes)->map(fn (int $index) => $available[$index]);

            $evaluation = $this->evaluateQueueTeam($team);
            if ($evaluation === null) {
                continue;
            }

            $mmrs = $team->map(fn (Queue $queue) => $queue->estimated_mmr ?? $queue->player->mmr ?? 800);
            $spread = $mmrs->max() - $mmrs->min();
            $score = $spread + $evaluation['composition_penalty'];

            if (
                $bestTeam === null
                || $score < $bestScore
                || ($score === $bestScore && $spread < $bestSpread)
            ) {
                $bestTeam = $team;
                $bestSpread = $spread;
                $bestScore = $score;
            }
        }

        return $bestTeam ?? collect();
    }

    /**
     * Combinaciones de $pickCount indices distintos tomados de [0, $itemCount),
     * en orden ascendente. Generaliza los bucles anidados que antes asumian
     * equipos de 2.
     *
     * @return \Generator<int, list<int>>
     */
    private function combinationIndexes(int $itemCount, int $pickCount, int $start = 0, array $prefix = []): \Generator
    {
        if ($pickCount <= 0) {
            yield $prefix;

            return;
        }

        for ($index = $start; $index <= $itemCount - $pickCount; $index++) {
            yield from $this->combinationIndexes($itemCount, $pickCount - 1, $index + 1, [...$prefix, $index]);
        }
    }

    /**
     * Reparte a todos los equipos en cola en los mejores cruces posibles.
     *
     * Antes esto era avido y cubico: buscaba el mejor cruce de TODA la cola,
     * lo sacaba, y volvia a mirarlo todo desde cero. Dos problemas, y los dos
     * se notaban.
     *
     * El primero, el tiempo. Reevaluar la cola entera en cada vuelta sale a
     * unos n^3/12 pares, y esto corre dentro de la peticion HTTP de quien entra
     * a la cola: con 240 en cola eran 47 segundos, o sea un timeout en un
     * hosting compartido, no una espera.
     *
     * El segundo, la calidad. Ser avido no reparte bien: con seis duelistas a
     * 1000/1300/1600 contra 1200/1500/1800 se llevaba primero los dos cruces
     * mas ajustados y dejaba al de 1000 contra el de 1800 -800 puntos de MMR,
     * unas cincuenta partidas de diferencia- porque ya se habia gastado a sus
     * rivales buenos.
     *
     * Ahora son tres pasos:
     *
     *   1. Puntuar cada cruce legal UNA vez (~n^2/2 en vez de n^3/12).
     *   2. Barrerlos en orden, quedandose con los que tengan los dos lados
     *      libres. Es el mismo criterio avido de antes pero sin repetir trabajo.
     *   3. Mejorar el reparto intercambiando rivales entre cruces ya hechos
     *      mientras el resultado mejore. Esto es lo que arregla el caso de los
     *      seis: el paso 2 deja 100+100+800 y los intercambios lo bajan a
     *      200+200+200.
     *
     * Y si tras el barrido queda alguien suelto teniendo rival legal, se le
     * empareja igual en un repaso final. La regla no negociable es que nadie se
     * quede en cola habiendo con quien jugar.
     */
    private function buildMatchPairings(Collection $candidateTeams): array
    {
        $teams = $candidateTeams->values()->all();
        $total = count($teams);

        if ($total < 2) {
            return [];
        }

        $recentPairHistory = $this->buildRecentPairHistory();
        $recentMatchSnapshots = $this->buildRecentMatchSnapshots();

        // Memoria de puntuaciones: un cruce se puntua una vez y ya. La usan
        // tanto el barrido como los intercambios, que preguntan por cruces que
        // el barrido nunca llego a mirar.
        $cache = [];
        $puntuar = function (int $i, int $j) use (&$cache, $teams, $recentPairHistory, $recentMatchSnapshots): ?array {
            $clave = $i < $j ? "$i:$j" : "$j:$i";

            if (array_key_exists($clave, $cache)) {
                return $cache[$clave];
            }

            return $cache[$clave] = $this->puntuarCruce(
                $teams[$i], $teams[$j], $recentPairHistory, $recentMatchSnapshots
            );
        };

        $orden = $this->ordenPorMmr($teams);
        $candidatos = [];

        // Solo se puntuan cruces entre equipos cercanos en MMR. Mirar la cola
        // entera contra la cola entera es lo que no escala, y un equipo no se
        // empareja jamas con otro que tenga ochenta puestos de MMR por medio
        // salvo que no le quede nadie mas: para eso esta el repaso final.
        foreach ($orden as $pos => $i) {
            $hasta = min($total - 1, $pos + self::PAIRING_MMR_WINDOW);

            for ($siguiente = $pos + 1; $siguiente <= $hasta; $siguiente++) {
                $j = $orden[$siguiente];
                $puntos = $puntuar($i, $j);

                if ($puntos !== null) {
                    $candidatos[] = [$puntos['score'], $puntos['diff'], $i, $j];
                }
            }
        }

        // Mejor puntuacion primero y, a igualdad, menor diferencia de MMR: el
        // mismo desempate que aplicaba el bucle avido.
        usort($candidatos, static function (array $a, array $b): int {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });

        $pareja = array_fill(0, $total, null);

        foreach ($candidatos as [, , $i, $j]) {
            if ($pareja[$i] === null && $pareja[$j] === null) {
                $pareja[$i] = $j;
                $pareja[$j] = $i;
            }
        }

        $this->emparejarRezagados($pareja, $total, $puntuar);
        $this->aumentarCardinalidad($pareja, $teams, $puntuar);

        // Los dos pasos de pulido se alternan: cambiar a un emparejado por un
        // suelto abre intercambios nuevos entre cruces, y al reves. Con dos
        // vueltas ya no se mueve nada en ningun tamaño de cola probado.
        for ($pulido = 0; $pulido < self::PAIRING_POLISH_ROUNDS; $pulido++) {
            $cambioConSueltos = $this->mejorarConSueltos($pareja, $teams, $puntuar);
            $cambioEntreCruces = $this->mejorarPorIntercambios($pareja, $teams, $puntuar);

            if (!$cambioConSueltos && !$cambioEntreCruces) {
                break;
            }
        }

        $pairings = [];

        foreach ($pareja as $i => $j) {
            if ($j !== null && $i < $j) {
                $pairings[] = [
                    'team_a' => $teams[$i],
                    'team_b' => $teams[$j],
                ];
            }
        }

        return $pairings;
    }

    /**
     * Puntua un cruce, o devuelve null si no se puede jugar.
     *
     * Cuanto mas bajo, mejor. La base es la diferencia de MMR y encima se
     * suman los recargos: repetir un cruce reciente, solaparse con una partida
     * de hace poco, y lo que separa a los dos equipos en composicion.
     *
     * @return array{score: int, diff: int}|null
     */
    private function puntuarCruce(
        array $teamA,
        array $teamB,
        array $recentPairHistory,
        Collection $recentMatchSnapshots
    ): ?array {
        // Nunca se enfrenta un equipo de 2v2 contra uno de 3v3.
        if ($teamA['arena_mode'] !== $teamB['arena_mode']) {
            return null;
        }

        if ($teamA['realm'] === $teamB['realm']) {
            return null;
        }

        // Ni una cuenta contra si misma. evaluateQueueTeam ya lo impide DENTRO
        // de un equipo, pero entre los dos bandos no lo miraba nadie: una cuenta
        // con un personaje en cada reino -se permiten cinco- podia acabar
        // peleando contra ella misma y regalarse victorias, PL y MMR.
        //
        // Por la pantalla no se llega: join() bloquea todos los personajes de la
        // cuenta y rechaza la segunda cola. Pero el emparejador no puede
        // depender de que el controlador se acuerde, y el laboratorio de bots si
        // encola por personaje.
        if ($this->compartenCuenta($teamA, $teamB)) {
            return null;
        }

        $diff = abs($teamA['avg_mmr'] - $teamB['avg_mmr']);

        $score = $diff
            + $this->getRepeatPairCount($teamA, $teamB, $recentPairHistory) * self::EXACT_REPEAT_PAIRING_PENALTY
            + $this->calculateRepeatOverlapPenalty($teamA, $teamB, $recentMatchSnapshots)
            + $this->calculatePairCompositionPenalty($teamA, $teamB);

        return ['score' => $score, 'diff' => $diff];
    }

    /**
     * Indices de los equipos ordenados por su MMR medio.
     *
     * @param  array<int, array<string, mixed>>  $teams
     * @return list<int>
     */
    private function ordenPorMmr(array $teams): array
    {
        $orden = array_keys($teams);

        usort($orden, static function (int $a, int $b) use ($teams): int {
            return [(int) $teams[$a]['avg_mmr'], $a] <=> [(int) $teams[$b]['avg_mmr'], $b];
        });

        return $orden;
    }

    /**
     * Empareja a quien quedo suelto tras el barrido.
     *
     * La ventana de MMR del barrido deja fuera cruces muy separados, y en una
     * cola pequeña o con los reinos descompensados eso puede dejar a alguien
     * sin pareja teniendo rival legal. Aqui se miran todos contra todos, sin
     * ventana: son pocos y la regla es que nadie espere habiendo con quien
     * jugar.
     *
     * @param  array<int, int|null>  $pareja
     */
    private function emparejarRezagados(array &$pareja, int $total, callable $puntuar): void
    {
        $sueltos = [];

        for ($i = 0; $i < $total; $i++) {
            if ($pareja[$i] === null) {
                $sueltos[] = $i;
            }
        }

        if (count($sueltos) < 2) {
            return;
        }

        $extra = [];

        foreach ($sueltos as $posA => $i) {
            foreach (array_slice($sueltos, $posA + 1) as $j) {
                $puntos = $puntuar($i, $j);

                if ($puntos !== null) {
                    $extra[] = [$puntos['score'], $puntos['diff'], $i, $j];
                }
            }
        }

        usort($extra, static function (array $a, array $b): int {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });

        foreach ($extra as [, , $i, $j]) {
            if ($pareja[$i] === null && $pareja[$j] === null) {
                $pareja[$i] = $j;
                $pareja[$j] = $i;
            }
        }
    }

    /**
     * Saca mas partidas deshaciendo cruces ya hechos.
     *
     * Quedarse corto de partidas teniendo gente con rival legal es lo unico que
     * este emparejador no puede hacer, y el barrido por puntuacion lo hace solo.
     * El caso tipico, medido con 900 en cola y 300 por reino: el barrido se
     * lleva 355 cruces y deja 190 sueltos, y los 190 son TODOS del mismo reino,
     * asi que entre ellos no pueden jugar. Cabian 450 partidas.
     *
     * Ejemplo pequeño del mismo fallo, con cuatro en cola: Alsius 1070, Alsius
     * 1151, Ignis 893 y Syrtis 778. El cruce mas ajustado es Syrtis contra
     * Ignis -115 puntos-, y en cuanto se lo lleva, los dos Alsius se quedan
     * mirandose. Una partida donde caben dos.
     *
     * La salida es deshacer ese cruce y rehacerlo con los sueltos: Ignis 893
     * contra Alsius 1070, y Syrtis 778 contra Alsius 1151. Dos partidas. Cuesta
     * mas MMR en total, y da igual: mas vale un cruce regular que quedarse en
     * cola mirando.
     *
     * Por que basta con esto: el grafo de cruces posibles es "todos contra
     * todos menos los de tu reino". Si quedan dos sueltos de reinos distintos,
     * emparejarRezagados ya los caso. Si todos los sueltos son del mismo reino y
     * aun cabe otra partida, forzosamente existe un cruce hecho con sus DOS
     * lados fuera de ese reino, y deshacerlo da sitio a dos sueltos. Cuando no
     * existe ese cruce es que ya no caben mas partidas. -La excepcion teorica es
     * el veto de "una cuenta no juega contra si misma", que quita alguna arista
     * suelta; en la practica no se llega porque entrar a la cola bloquea la
     * cuenta entera.-
     *
     * El reparto se hace por cercania de MMR y no probandolo todo: emparejar a
     * cada pareja de sueltos con el donante mas proximo en nivel coloca a los
     * 190 de una pasada. Buscar el mejor donante para cada pareja mirandolos
     * todos costaba cuarenta segundos y solo colocaba a doce.
     *
     * @param  array<int, int|null>  $pareja
     */
    private function aumentarCardinalidad(array &$pareja, array $teams, callable $puntuar): void
    {
        $total = count($teams);

        for ($vuelta = 0; $vuelta < self::PAIRING_AUGMENT_ROUNDS; $vuelta++) {
            $sueltos = [];
            $cruces = [];

            for ($i = 0; $i < $total; $i++) {
                if ($pareja[$i] === null) {
                    $sueltos[] = $i;
                } elseif ($i < $pareja[$i]) {
                    $cruces[] = [$i, $pareja[$i]];
                }
            }

            // Con menos de dos sueltos no hay ninguna partida que ganar: hace
            // falta uno para cada lado del cruce que se deshace.
            if (count($sueltos) < 2 || $cruces === []) {
                return;
            }

            usort($sueltos, static function (int $a, int $b) use ($teams): int {
                return [(int) $teams[$a]['avg_mmr'], $a] <=> [(int) $teams[$b]['avg_mmr'], $b];
            });

            usort($cruces, function (array $a, array $b) use ($teams): int {
                return $this->mmrDelCruce($teams, $a) <=> $this->mmrDelCruce($teams, $b);
            });

            $nivelDelCruce = [];

            foreach ($cruces as $posicion => $cruce) {
                $nivelDelCruce[$posicion] = $this->mmrDelCruce($teams, $cruce);
            }

            $gastados = [];
            $colocados = 0;

            // Los sueltos van de dos en dos y por nivel, asi que cada pareja
            // que entra a un cruce deshecho son los dos mas parecidos que
            // quedaban.
            for ($t = 0; $t + 1 < count($sueltos); $t += 2) {
                $u = $sueltos[$t];
                $u2 = $sueltos[$t + 1];
                $nivel = (int) (((int) $teams[$u]['avg_mmr'] + (int) $teams[$u2]['avg_mmr']) / 2);

                $mejor = $this->donanteMasCercano(
                    $cruces, $nivelDelCruce, $gastados, $nivel, $u, $u2, $puntuar
                );

                if ($mejor === null) {
                    continue;
                }

                $gastados[$mejor['posicion']] = true;

                foreach ($mejor['reparto'] as [$a, $b]) {
                    $pareja[$a] = $b;
                    $pareja[$b] = $a;
                }

                $colocados++;
            }

            if ($colocados === 0) {
                return;
            }
        }
    }

    /**
     * El cruce hecho mas cercano en nivel que admite a estos dos sueltos.
     *
     * Se busca por biseccion la posicion del nivel pedido y se mira una ventana
     * a cada lado, no la lista entera: el donante que sirve siempre esta cerca,
     * y recorrerlos todos por cada pareja de sueltos es lo que hacia que esto
     * tardase cuarenta segundos.
     *
     * @return array{posicion: int, reparto: list<array{0: int, 1: int}>}|null
     */
    private function donanteMasCercano(
        array $cruces,
        array $nivelDelCruce,
        array $gastados,
        int $nivel,
        int $u,
        int $u2,
        callable $puntuar
    ): ?array {
        $numero = count($cruces);

        if ($numero === 0) {
            return null;
        }

        $bajo = 0;
        $alto = $numero - 1;

        while ($bajo < $alto) {
            $medio = intdiv($bajo + $alto, 2);

            if ($nivelDelCruce[$medio] < $nivel) {
                $bajo = $medio + 1;
            } else {
                $alto = $medio;
            }
        }

        $desde = max(0, $bajo - self::PAIRING_SWAP_WINDOW);
        $hasta = min($numero - 1, $bajo + self::PAIRING_SWAP_WINDOW);

        $mejor = null;

        for ($posicion = $desde; $posicion <= $hasta; $posicion++) {
            if (isset($gastados[$posicion])) {
                continue;
            }

            [$v, $w] = $cruces[$posicion];
            $costeActual = $puntuar($v, $w)['score'];

            foreach ([[$u, $v, $u2, $w], [$u, $w, $u2, $v]] as [$p1, $p2, $p3, $p4]) {
                $uno = $puntuar($p1, $p2);
                $otro = $puntuar($p3, $p4);

                if ($uno === null || $otro === null) {
                    continue;
                }

                $sobrecoste = $uno['score'] + $otro['score'] - $costeActual;

                if ($mejor === null || $sobrecoste < $mejor['sobrecoste']) {
                    $mejor = [
                        'sobrecoste' => $sobrecoste,
                        'posicion' => $posicion,
                        'reparto' => [[$p1, $p2], [$p3, $p4]],
                    ];
                }
            }
        }

        return $mejor;
    }

    /**
     * Cambia a un emparejado por alguien que se quedo suelto, si mejora.
     *
     * Cuando no caben partidas para todos -tres reinos descompensados, por
     * ejemplo- siempre sobra gente, y quien sobra no tiene por que ser el que
     * peor encajaba. Este paso mira, para cada suelto, si entrando el en algun
     * cruce cercano ese cruce queda mejor, y en ese caso se cambian los papeles:
     * el suelto juega y el otro pasa al banquillo. El numero de partidas no
     * cambia; cambia quien las juega y lo bien que encajan.
     *
     * Hace falta porque el intercambio entre dos cruces no llega aqui. Caso
     * real: cinco Alsius, dos Ignis y un Syrtis, donde solo caben tres
     * partidas. El reparto quedaba en 338 puntos de MMR y el mejor posible era
     * 242; ninguna permuta entre los tres cruces lo arreglaba, porque la mejora
     * pasaba por sacar a un Alsius de 857 y meter al de 1171, que estaba
     * sentado. Con este paso y el de intercambios despues, sale el 242 exacto.
     *
     * @param  array<int, int|null>  $pareja
     */
    private function mejorarConSueltos(array &$pareja, array $teams, callable $puntuar): bool
    {
        $total = count($teams);
        $sueltos = [];
        $cruces = [];

        for ($i = 0; $i < $total; $i++) {
            if ($pareja[$i] === null) {
                $sueltos[] = $i;
            } elseif ($i < $pareja[$i]) {
                $cruces[] = [$i, $pareja[$i]];
            }
        }

        if ($sueltos === [] || $cruces === []) {
            return false;
        }

        usort($cruces, function (array $a, array $b) use ($teams): int {
            return $this->mmrDelCruce($teams, $a) <=> $this->mmrDelCruce($teams, $b);
        });

        $nivelDelCruce = [];

        foreach ($cruces as $posicion => $cruce) {
            $nivelDelCruce[$posicion] = $this->mmrDelCruce($teams, $cruce);
        }

        $numero = count($cruces);
        $hubo = false;

        foreach ($sueltos as $u) {
            $nivel = (int) $teams[$u]['avg_mmr'];

            $bajo = 0;
            $alto = $numero - 1;

            while ($bajo < $alto) {
                $medio = intdiv($bajo + $alto, 2);

                if ($nivelDelCruce[$medio] < $nivel) {
                    $bajo = $medio + 1;
                } else {
                    $alto = $medio;
                }
            }

            $desde = max(0, $bajo - self::PAIRING_SWAP_WINDOW);
            $hasta = min($numero - 1, $bajo + self::PAIRING_SWAP_WINDOW);
            $mejor = null;

            for ($posicion = $desde; $posicion <= $hasta; $posicion++) {
                [$a, $b] = $cruces[$posicion];

                // El cruce pudo cambiar en una vuelta anterior de este mismo
                // bucle; si ya no es el que era, se deja para la siguiente.
                if ($pareja[$a] !== $b) {
                    continue;
                }

                $actual = $puntuar($a, $b)['score'];

                foreach ([[$a, $b], [$b, $a]] as [$sale, $queda]) {
                    $nuevo = $puntuar($u, $queda);

                    if ($nuevo === null || $nuevo['score'] >= $actual) {
                        continue;
                    }

                    if ($mejor === null || $nuevo['score'] - $actual < $mejor['ganancia']) {
                        $mejor = [
                            'ganancia' => $nuevo['score'] - $actual,
                            'posicion' => $posicion,
                            'sale' => $sale,
                            'queda' => $queda,
                        ];
                    }
                }
            }

            if ($mejor === null) {
                continue;
            }

            $pareja[$mejor['sale']] = null;
            $pareja[$u] = $mejor['queda'];
            $pareja[$mejor['queda']] = $u;
            $cruces[$mejor['posicion']] = $u < $mejor['queda'] ? [$u, $mejor['queda']] : [$mejor['queda'], $u];
            $hubo = true;
        }

        return $hubo;
    }

    /** El MMR medio de los dos lados de un cruce. */
    private function mmrDelCruce(array $teams, array $cruce): int
    {
        return (int) (((int) $teams[$cruce[0]]['avg_mmr'] + (int) $teams[$cruce[1]]['avg_mmr']) / 2);
    }

    /**
     * Los cruces que vale la pena deshacer para colocar a los sueltos.
     *
     * Deshacer un cruce del otro extremo de la tabla para meter ahi a un suelto
     * sale carisimo y nunca se elige, asi que ni se mira: solo los cruces cuyo
     * nivel anda cerca del de algun suelto. Con la cola vacia esto no cambia
     * nada -son cuatro cruces- y con la cola llena es lo que evita repasar
     * cientos por cada persona suelta.
     *
     * @param  list<array{0: int, 1: int}>  $cruces  ordenados por MMR del cruce
     * @param  list<int>  $sueltos
     * @return list<array{0: int, 1: int}>
     */
    private function crucesCercanos(array $cruces, array $sueltos, array $teams): array
    {
        $numero = count($cruces);

        if ($numero <= self::PAIRING_SWAP_WINDOW) {
            return $cruces;
        }

        $elegidos = [];

        foreach ($sueltos as $suelto) {
            $mmr = (int) $teams[$suelto]['avg_mmr'];

            // Busqueda binaria del cruce mas cercano en nivel, y de ahi una
            // ventana a cada lado.
            $bajo = 0;
            $alto = $numero - 1;

            while ($bajo < $alto) {
                $medio = intdiv($bajo + $alto, 2);

                if ($this->mmrDelCruce($teams, $cruces[$medio]) < $mmr) {
                    $bajo = $medio + 1;
                } else {
                    $alto = $medio;
                }
            }

            $desde = max(0, $bajo - self::PAIRING_SWAP_WINDOW);
            $hasta = min($numero - 1, $bajo + self::PAIRING_SWAP_WINDOW);

            for ($i = $desde; $i <= $hasta; $i++) {
                $elegidos[$i] = $cruces[$i];
            }
        }

        return array_values($elegidos);
    }

    /**
     * Mejora el reparto intercambiando rivales entre dos cruces ya hechos.
     *
     * Con los cruces (a-b) y (c-d) sobre la mesa, se prueban las otras dos
     * formas de repartir a esos cuatro -(a-c, b-d) y (a-d, b-c)- y se acepta la
     * que deje el conjunto mejor. "Mejor" son dos cosas en este orden: que baje
     * la suma de las puntuaciones, y a igualdad, que baje el peor cruce de los
     * dos. Lo segundo importa porque la suma sola tolera un cruce horrible si
     * el otro compensa, y el cruce horrible se lo come una persona.
     *
     * Se repite mientras algo mejore, con un tope de pasadas para que esto no
     * pueda convertirse en el problema de rendimiento que venia a arreglar.
     *
     * @param  array<int, int|null>  $pareja
     */
    private function mejorarPorIntercambios(array &$pareja, array $teams, callable $puntuar): bool
    {
        $total = count($teams);
        $cruces = [];

        for ($i = 0; $i < $total; $i++) {
            if ($pareja[$i] !== null && $i < $pareja[$i]) {
                $cruces[] = [$i, $pareja[$i]];
            }
        }

        $numero = count($cruces);

        if ($numero < 2) {
            return false;
        }

        // Ordenados por el MMR medio del cruce. Intercambiar rivales entre dos
        // cruces solo puede mejorar algo si los cuatro andan por el mismo nivel:
        // cambiar al de 1900 por el de 800 no arregla nada. Ordenar permite
        // mirar solo los vecinos, que es lo que hace que esto siga siendo barato
        // con la cola llena -mirarlos todos contra todos eran mas de sesenta mil
        // comprobaciones con 360 cruces, y ahi se iban los segundos-.
        usort($cruces, function (array $a, array $b) use ($teams): int {
            return $this->mmrDelCruce($teams, $a) <=> $this->mmrDelCruce($teams, $b);
        });

        $puntos = static function (?array $p): ?int {
            return $p === null ? null : $p['score'];
        };

        for ($pasada = 0; $pasada < self::PAIRING_IMPROVEMENT_PASSES; $pasada++) {
            $cambio = false;

            for ($x = 0; $x < $numero - 1; $x++) {
                $hasta = min($numero - 1, $x + self::PAIRING_SWAP_WINDOW);

                for ($y = $x + 1; $y <= $hasta; $y++) {
                    [$a, $b] = $cruces[$x];
                    [$c, $d] = $cruces[$y];

                    $actualA = $puntos($puntuar($a, $b));
                    $actualB = $puntos($puntuar($c, $d));

                    // Los dos cruces existen, asi que sus puntuaciones tambien.
                    $actual = [$actualA + $actualB, max($actualA, $actualB)];

                    $mejor = null;

                    foreach ([[[$a, $c], [$b, $d]], [[$a, $d], [$b, $c]]] as $opcion) {
                        $uno = $puntos($puntuar($opcion[0][0], $opcion[0][1]));
                        $otro = $puntos($puntuar($opcion[1][0], $opcion[1][1]));

                        // Una de las dos reparticiones puede ser ilegal -mismo
                        // reino, misma cuenta, otra modalidad-. Se descarta.
                        if ($uno === null || $otro === null) {
                            continue;
                        }

                        $valor = [$uno + $otro, max($uno, $otro)];

                        if ($valor < $actual && ($mejor === null || $valor < $mejor['valor'])) {
                            $mejor = ['valor' => $valor, 'opcion' => $opcion];
                        }
                    }

                    if ($mejor === null) {
                        continue;
                    }

                    [[$n1, $n2], [$n3, $n4]] = $mejor['opcion'];

                    $pareja[$n1] = $n2;
                    $pareja[$n2] = $n1;
                    $pareja[$n3] = $n4;
                    $pareja[$n4] = $n3;

                    $cruces[$x] = $n1 < $n2 ? [$n1, $n2] : [$n2, $n1];
                    $cruces[$y] = $n3 < $n4 ? [$n3, $n4] : [$n4, $n3];

                    $cambio = true;
                }
            }

            if (!$cambio) {
                return $pasada > 0;
            }
        }

        return true;
    }

    private function createArenaMatch(array $teamA, array $teamB, Collection $activeMatches): ArenaMatch
    {
        $expiresAt = now()->addMinutes((int) AppSetting::getValue('accept_window_minutes', 5));

        $teamAPayload = $this->buildTeamPayload($teamA['entries']);
        $teamBPayload = $this->buildTeamPayload($teamB['entries']);
        $queueIds = $teamA['entries']
            ->pluck('id')
            ->merge($teamB['entries']->pluck('id'))
            ->values();

        $lockedQueues = Queue::query()
            ->whereIn('id', $queueIds)
            ->lockForUpdate()
            ->get(['id', 'status', 'match_id']);

        if (
            $lockedQueues->count() !== $queueIds->count()
            || $lockedQueues->contains(fn (Queue $queue) => $queue->status !== 'waiting' || $queue->match_id !== null)
        ) {
            throw new \RuntimeException('One or more queue entries are no longer available for matchmaking.');
        }

        $arenaMode = ArenaMode::resolve($teamA['arena_mode'] ?? null);

        // El limite diario no necesita filtrarse por modalidad: la firma de
        // party es la lista de user_ids, asi que una dupla ("7-12") y un trio
        // ("7-12-19") nunca comparten firma.
        if (($teamA['queue_type'] ?? 'random') === 'premade' && $this->countPartyMatchesToday((string) ($teamA['party_signature'] ?? '')) >= $this->premadeDailyLimit()) {
            throw new \RuntimeException('Premade party A reached its daily limit.');
        }

        if (($teamB['queue_type'] ?? 'random') === 'premade' && $this->countPartyMatchesToday((string) ($teamB['party_signature'] ?? '')) >= $this->premadeDailyLimit()) {
            throw new \RuntimeException('Premade party B reached its daily limit.');
        }

        $attributes = [
            'match_code' => ArenaMatch::generateMatchCode(),
            'report_token' => ArenaMatch::generateReportToken(),
            'queue_mode' => ($teamA['queue_type'] ?? 'random') === 'premade' && ($teamB['queue_type'] ?? 'random') === 'premade'
                ? 'premade'
                : 'random',
            'arena_mode' => $arenaMode,
            'team_a_realm' => $teamA['realm'],
            'team_b_realm' => $teamB['realm'],
            'team_a' => $teamAPayload,
            'team_b' => $teamBPayload,
            'zone' => $this->pickZone($teamA['realm'], $teamB['realm'], $activeMatches),
            'status' => 'pending_acceptance',
            'estimated_mmr_avg' => (int) round(($teamA['avg_mmr'] + $teamB['avg_mmr']) / 2),
            'expires_at' => $expiresAt,
        ];

        $match = ArenaMatch::create(array_merge(
            $attributes,
            $this->buildMatchModeAttributes($teamA, $teamB),
            $this->buildLegacyRealmColumns($teamA['realm'], $teamAPayload, $teamB['realm'], $teamBPayload)
        ));

        Queue::query()
            ->whereIn('id', $teamA['entries']->pluck('id'))
            ->update([
                'status' => 'matched',
                'matched_at' => now(),
                'expires_at' => $expiresAt,
                'team_id' => $teamA['team_id'],
                'match_id' => (string) $match->id,
            ]);

        Queue::query()
            ->whereIn('id', $teamB['entries']->pluck('id'))
            ->update([
                'status' => 'matched',
                'matched_at' => now(),
                'expires_at' => $expiresAt,
                'team_id' => $teamB['team_id'],
                'match_id' => (string) $match->id,
            ]);

        return $match;
    }

    private function buildTeamPayload(Collection $entries): array
    {
        return $entries->map(function (Queue $queue) {
            return [
                'player_id' => $queue->player->id,
                'character_name' => $queue->player->character_name,
                'subclass' => $queue->player->subclass,
                'realm' => $queue->player->realm,
                'discord_id' => (string) ($queue->player->user->discord_id ?? ''),
                'conjurer_role' => $queue->conjurer_role,
            ];
        })->values()->all();
    }

    /** Los enfrentamientos que ocupan zona ahora mismo. */
    private function cargarEnfrentamientosVivos(): Collection
    {
        return ArenaMatch::query()
            ->whereIn('status', ['pending_acceptance', 'accepted', 'in_progress'])
            ->get(['zone', 'team_a_realm', 'team_b_realm']);
    }

    /**
     * En que zona se juega este cruce.
     *
     * $activeMatches son los enfrentamientos vivos, y llega YA cargado desde
     * fuera. Antes lo consultaba aqui dentro, o sea una consulta a la base y un
     * repaso del mapa entero por cada partida creada: con la cola llena, crear
     * 450 partidas eran 450 consultas sobre una lista que crecia con cada una, y
     * ahi se iban tres cuartas partes del tiempo del emparejamiento -45 de los
     * 49 segundos que tardaba una cola de 900-. Se carga una vez por barrido y
     * cada partida nueva se le añade en memoria, que es lo mismo que leerla de
     * la base pero sin ir.
     */
    private function pickZone(string $teamARealm, string $teamBRealm, ?Collection $activeMatches = null): string
    {
        // Sin lista, se consulta: asi quien llame a esto suelto -los tests de
        // zonas, por ejemplo- sigue viendo el mismo comportamiento de siempre.
        $activeMatches ??= $this->cargarEnfrentamientosVivos();

        $activeZones = $activeMatches
            ->pluck('zone')
            ->filter()
            ->map(function ($zone) {
                return ArenaMatch::normalizeZoneKey((string) $zone) ?? (string) $zone;
            })
            ->unique()
            ->all();

        $allZones = $this->getCompatibleZonePool();
        $availableZones = collect($allZones)
            ->reject(function (string $zone) use ($activeZones) {
                $zoneKey = ArenaMatch::normalizeZoneKey($zone) ?? $zone;

                return in_array($zoneKey, $activeZones, true);
            })
            ->values()
            ->all();

        // El sorteo se hace entre las zonas de la frontera de estos dos reinos,
        // no entre las catorce. Antes salia cualquiera y mandaba a la gente a
        // cruzar el mapa entero para encontrarse; ahora un Syrtis contra Ignis
        // cae en su frontera mientras quede alguna libre, pero sigue siendo
        // sorteo: repartir los combates entre las zonas del cruce evita que
        // todo el mundo acabe en la misma.
        $preferredZones = ArenaMatch::preferredZonesFor($teamARealm, $teamBRealm);

        // Se compara por clave canonica, como hace el filtro de ocupadas. Con
        // una columna "zone" antigua y corta el catalogo llega en alias, y
        // comparar las cadenas a pelo dejaba la recomendacion en nada sin que
        // se notara: volvia a salir cualquier zona del mapa.
        $preferredAvailable = array_values(array_filter($availableZones, function (string $zone) use ($preferredZones) {
            $zoneKey = ArenaMatch::normalizeZoneKey($zone) ?? $zone;

            return in_array($zoneKey, $preferredZones, true);
        }));

        if ($preferredAvailable !== []) {
            return $preferredAvailable[array_rand($preferredAvailable)];
        }

        // Ninguna recomendada libre. Antes que hacer esperar a nadie se juega
        // en cualquier otra: la recomendacion ordena, no bloquea.
        if ($availableZones !== []) {
            return $availableZones[array_rand($availableZones)];
        }

        $incomingRealms = [$teamARealm, $teamBRealm];
        sort($incomingRealms);

        $zoneScores = collect($allZones)->mapWithKeys(function (string $zone) use ($activeMatches, $incomingRealms, $preferredZones) {
            $zoneKey = ArenaMatch::normalizeZoneKey($zone) ?? $zone;

            $score = $activeMatches->reduce(function (int $carry, ArenaMatch $activeMatch) use ($zoneKey, $incomingRealms) {
                $activeZoneKey = ArenaMatch::normalizeZoneKey((string) $activeMatch->zone) ?? (string) $activeMatch->zone;
                if ($activeZoneKey !== $zoneKey) {
                    return $carry;
                }

                $activeRealms = [(string) $activeMatch->team_a_realm, (string) $activeMatch->team_b_realm];
                sort($activeRealms);

                if ($activeRealms === $incomingRealms) {
                    return $carry + 100;
                }

                $sharedRealms = count(array_intersect($incomingRealms, $activeRealms));

                if ($sharedRealms === 1) {
                    return $carry + 1;
                }

                return $carry + 10;
            }, 0);

            // Con todo el mapa ocupado la recomendacion sigue pesando, pero ya
            // no manda: un cruce del mismo par de reinos suma 100, asi que una
            // zona lejana solo gana cuando todas las de la frontera arrastran
            // cinco combates o mas de este mismo cruce.
            if ($preferredZones !== [] && !in_array($zoneKey, $preferredZones, true)) {
                $score += 500;
            }

            return [$zone => $score];
        });

        $bestScore = (int) $zoneScores->min();
        $pool = $zoneScores
            ->filter(fn (int $score) => $score === $bestScore)
            ->keys()
            ->values()
            ->all();

        return $pool[array_rand($pool)];
    }

    private function getMatchesColumns(): Collection
    {
        if ($this->matchesColumnsCache !== null) {
            return $this->matchesColumnsCache;
        }

        if (!Schema::hasTable('matches')) {
            return $this->matchesColumnsCache = collect();
        }

        return $this->matchesColumnsCache = collect(Schema::getColumns('matches'))->keyBy('name');
    }

    private function buildLegacyRealmColumns(
        string $teamARealm,
        array $teamAPayload,
        string $teamBRealm,
        array $teamBPayload
    ): array {
        $columns = $this->getMatchesColumns();
        if ($columns->isEmpty()) {
            return [];
        }

        $legacyPayloads = [
            'ignis' => [],
            'syrtis' => [],
            'alsius' => [],
        ];

        $legacyPayloads[$teamARealm] = $teamAPayload;
        $legacyPayloads[$teamBRealm] = $teamBPayload;

        $attributes = [];
        foreach ($legacyPayloads as $realm => $payload) {
            $column = 'team_' . $realm;
            if ($columns->has($column)) {
                $attributes[$column] = $payload;
            }
        }

        return $attributes;
    }

    private function buildMatchModeAttributes(array $teamA, array $teamB): array
    {
        $columns = $this->getMatchesColumns();
        if ($columns->isEmpty()) {
            return [];
        }

        $attributes = [];

        if ($columns->has('team_a_queue_type')) {
            $attributes['team_a_queue_type'] = $teamA['queue_type'] ?? 'random';
        }

        if ($columns->has('team_b_queue_type')) {
            $attributes['team_b_queue_type'] = $teamB['queue_type'] ?? 'random';
        }

        if ($columns->has('team_a_party_signature')) {
            $attributes['team_a_party_signature'] = $teamA['party_signature'] ?? null;
        }

        if ($columns->has('team_b_party_signature')) {
            $attributes['team_b_party_signature'] = $teamB['party_signature'] ?? null;
        }

        return $attributes;
    }

    private function resolvePartySignature(Collection $teamEntries): string
    {
        return $this->buildPartySignatureFromUserIds(
            $teamEntries
                ->map(fn (Queue $queue) => (int) $queue->player->user_id)
                ->all()
        );
    }

    private function buildPartySignatureFromUserIds(array $userIds): string
    {
        $userIds = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return implode('-', $userIds);
    }

    private function countPartyMatchesToday(string $partySignature): int
    {
        if ($partySignature === '') {
            return 0;
        }

        $columns = $this->getMatchesColumns();
        if (!$columns->has('team_a_party_signature') || !$columns->has('team_b_party_signature')) {
            return 0;
        }

        $query = ArenaMatch::query()
            ->whereDate('created_at', now()->toDateString())
            ->whereNotIn('status', ['cancelled', 'void'])
            ->where(function ($builder) use ($partySignature) {
                $builder->where('team_a_party_signature', $partySignature)
                    ->orWhere('team_b_party_signature', $partySignature);
            });

        return $query->count();
    }

    private function premadeDailyLimit(): int
    {
        return max(1, (int) AppSetting::getValue('premade_daily_limit', self::PREMADE_DAILY_LIMIT));
    }

    private function requeuePremadeGroup(Collection $queueGroup): void
    {
        Queue::query()
            ->whereIn('id', $queueGroup->pluck('id'))
            ->update([
                'status' => 'waiting',
                'matched_at' => null,
                'expires_at' => now()->addMinutes(30),
                'match_id' => null,
                'joined_at' => now(),
            ]);
    }

    private function resetRandomQueueGroup(Collection $queueGroup): void
    {
        $acceptedIds = $queueGroup
            ->where('status', 'accepted')
            ->pluck('id');

        if ($acceptedIds->isNotEmpty()) {
            Queue::query()
                ->whereIn('id', $acceptedIds)
                ->update([
                    'status' => 'waiting',
                    'matched_at' => null,
                    'expires_at' => now()->addMinutes(30),
                    'team_id' => null,
                    'match_id' => null,
                    'joined_at' => now(),
                ]);
        }

        $matchedIds = $queueGroup
            ->where('status', 'matched')
            ->pluck('id');

        if ($matchedIds->isNotEmpty()) {
            Queue::query()
                ->whereIn('id', $matchedIds)
                ->update([
                    'status' => 'cancelled',
                    'matched_at' => null,
                    'expires_at' => null,
                    'team_id' => null,
                    'match_id' => null,
                ]);
        }
    }

    private function cancelQueueGroup(Collection $queueGroup, bool $isPremade): void
    {
        if ($queueGroup->isEmpty()) {
            return;
        }

        Queue::query()
            ->whereIn('id', $queueGroup->pluck('id'))
            ->update([
                'status' => 'cancelled',
                'matched_at' => null,
                'expires_at' => null,
                'team_id' => null,
                'match_id' => null,
            ]);

        if ($isPremade) {
            $playerIds = $queueGroup->pluck('player_id')->unique()->toArray();
            if (!empty($playerIds)) {
                $partyIds = \App\Models\PartyMember::whereIn('player_id', $playerIds)
                    ->pluck('party_id')
                    ->unique()
                    ->toArray();
                
                if (!empty($partyIds)) {
                    \App\Models\Party::whereIn('id', $partyIds)
                        ->where('status', 'queued')
                        ->update(['status' => 'ready']);
                }
            }
        }
    }

    private function getCompatibleZonePool(): array
    {
        $canonicalZones = ArenaMatch::zoneKeys();
        $zoneColumn = $this->getMatchesColumns()->get('zone');

        if (!is_array($zoneColumn)) {
            return $canonicalZones;
        }

        $enumOptions = $this->extractEnumOptions((string) ($zoneColumn['type'] ?? ''));
        if ($enumOptions !== []) {
            $normalizedEnumOptions = collect($enumOptions)
                ->map(function (string $zone) {
                    return ArenaMatch::normalizeZoneKey($zone);
                })
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($normalizedEnumOptions !== []) {
                return $normalizedEnumOptions;
            }
        }

        $maxLength = $this->extractColumnLength((string) ($zoneColumn['type'] ?? ''));
        if ($maxLength === null) {
            return $canonicalZones;
        }

        $compatibleCanonicalZones = array_values(array_filter($canonicalZones, function (string $zone) use ($maxLength) {
            return strlen($zone) <= $maxLength;
        }));

        if ($compatibleCanonicalZones !== []) {
            return $compatibleCanonicalZones;
        }

        $fallbackZones = collect($canonicalZones)
            ->flatMap(fn (string $zone) => $this->getZoneStorageCandidates($zone))
            ->filter(function (string $zone) use ($maxLength) {
                return strlen($zone) <= $maxLength;
            })
            ->unique()
            ->values()
            ->all();

        return $fallbackZones !== [] ? $fallbackZones : $canonicalZones;
    }

    private function extractEnumOptions(string $columnType): array
    {
        if ($columnType === '' || !str_starts_with(strtolower($columnType), 'enum(')) {
            return [];
        }

        preg_match_all("/'([^']+)'/", $columnType, $matches);

        return $matches[1] ?? [];
    }

    private function extractColumnLength(string $columnType): ?int
    {
        if (preg_match('/\((\d+)\)/', $columnType, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function getZoneStorageCandidates(string $canonicalZone): array
    {
        $spaced = str_replace('_', ' ', $canonicalZone);
        $hyphenated = str_replace('_', '-', $canonicalZone);

        return match ($canonicalZone) {
            'central_ruins' => [$canonicalZone, $spaced, $hyphenated, 'centralruins', 'ruins'],
            'emerald_pass' => [$canonicalZone, $spaced, $hyphenated, 'emeraldpass', 'pass'],
            'crimson_canyon' => [$canonicalZone, $spaced, $hyphenated, 'crimsoncanyon', 'canyon'],
            'frozen_bridge' => [$canonicalZone, $spaced, $hyphenated, 'frozenbridge', 'bridge'],
            'merchant_coast' => [$canonicalZone, $spaced, $hyphenated, 'merchantcoast', 'coast'],
            'obsidian_watch' => [$canonicalZone, $spaced, $hyphenated, 'obsidianwatch', 'watch'],
            default => [$canonicalZone, $spaced, $hyphenated],
        };
    }

    private function buildRecentPairHistory(): array
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

    private function buildRecentMatchSnapshots(): Collection
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

    private function getRepeatPairCount(array $teamA, array $teamB, array $recentPairHistory): int
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

    private function evaluateQueueTeam(Collection $team): ?array
    {
        // Todas las entradas de un equipo comparten modalidad (se agrupa por
        // arena_mode antes de llegar aqui), asi que la primera define el tamaño.
        $teamSize = ArenaMode::teamSize($team->first()?->arena_mode);

        if ($team->count() !== $teamSize) {
            return null;
        }

        // Un mismo usuario no puede ocupar dos puestos del equipo con dos de
        // sus personajes: fisicamente solo puede jugar uno. buildPremadeTeams
        // ya lo validaba por su cuenta, pero el camino random no lo hacia y es
        // alcanzable (varios personajes por cuenta, reencolados tras cancelar).
        // Al vivir aqui, la regla cubre las dos ramas.
        $userIds = $team->map(fn (Queue $queue) => (int) ($queue->player->user_id ?? 0))->filter();

        if ($userIds->count() !== $teamSize || $userIds->unique()->count() !== $teamSize) {
            return null;
        }

        $profile = $this->buildQueueTeamProfile($team);

        // El rol del conjurador es una regla de plantilla -"un soporte por
        // equipo"- y solo se exige donde hay plantilla. En el duelo no se
        // pregunta, se fija ofensivo al encolar, y una fila antigua o un dato
        // raro con el rol en blanco no puede dejar a alguien esperando para
        // siempre en una cola donde la regla ni existe.
        if ($teamSize >= 2 && ($profile['support_conjurers'] > 1 || $profile['invalid_conjurer_roles'] > 0)) {
            return null;
        }

        $compositionPenalty = 0;
        foreach ($profile['subclasses'] as $count) {
            if ($count > 1) {
                $compositionPenalty += ($count - 1) * self::TEAM_DUPLICATE_SUBCLASS_PENALTY;
            }

            // Penalizacion extra solo tiene sentido a partir de 3: en 2v2 un
            // equipo entero de la misma subclase ya lo cubre la regla anterior.
            if ($count === $teamSize && $teamSize >= 3) {
                $compositionPenalty += self::TEAM_TRIPLE_SUBCLASS_PENALTY;
            }
        }

        $compositionPenalty += max(0, $profile['conjurer_count'] - 1) * self::TEAM_EXTRA_CONJURER_PENALTY;

        // "Mezcla al menos dos roles" es una regla de plantilla. Con un jugador
        // por equipo no hay plantilla que mezclar: cobrarsela dejaba a todo el
        // mundo con el mismo recargo fijo, que no ordena nada y solo ensuciaba
        // la cifra.
        if ($teamSize >= 2) {
            $compositionPenalty += max(0, 2 - $profile['archetype_count']) * self::TEAM_ARCHETYPE_PENALTY;
        }

        return [
            'profile' => $profile,
            'composition_penalty' => $compositionPenalty,
        ];
    }

    private function buildQueueTeamProfile(Collection $team): array
    {
        $subclasses = [];
        $archetypes = [];
        $supportConjurers = 0;
        $conjurerCount = 0;
        $invalidConjurerRoles = 0;

        foreach ($team as $queue) {
            $subclass = (string) $queue->player->subclass;
            $subclasses[$subclass] = ($subclasses[$subclass] ?? 0) + 1;

            $archetype = $this->resolveSubclassArchetype($subclass);
            $archetypes[$archetype] = ($archetypes[$archetype] ?? 0) + 1;

            if ($subclass !== 'conjurer') {
                continue;
            }

            $conjurerCount++;
            if (!in_array($queue->conjurer_role, ['support', 'offensive'], true)) {
                $invalidConjurerRoles++;
                continue;
            }

            if ($queue->conjurer_role === 'support') {
                $supportConjurers++;
            }
        }

        return [
            'subclasses' => $subclasses,
            'unique_subclasses' => count($subclasses),
            'archetype_count' => count($archetypes),
            'conjurer_count' => $conjurerCount,
            'support_conjurers' => $supportConjurers,
            'invalid_conjurer_roles' => $invalidConjurerRoles,
        ];
    }

    private function resolveSubclassArchetype(string $subclass): string
    {
        return match ($subclass) {
            'knight', 'barbarian' => 'frontline',
            'hunter', 'marksman', 'warlock' => 'damage',
            'conjurer' => 'utility',
            default => 'flex',
        };
    }

    private function calculatePairCompositionPenalty(array $teamA, array $teamB): int
    {
        $profileA = $teamA['profile'] ?? $this->buildQueueTeamProfile($teamA['entries']);
        $profileB = $teamB['profile'] ?? $this->buildQueueTeamProfile($teamB['entries']);

        // El duelo tiene su propia escala. La de equipos suma tres castigos
        // pensados para comparar plantillas -cuantos conjuradores, cuantos
        // soportes, cuantas subclases repetidas- y con un jugador por lado esas
        // tres cuentas miden lo mismo tres veces: un brujo contra un caballero
        // pagaba 10 por las subclases y un conjurador contra cualquiera pagaba
        // 12 mas solo por ser conjurador, asi que el conjurador era el peor
        // rival posible para todos y el espejo conjurador contra conjurador no
        // valia mas que un brujo contra un barbaro.
        if (ArenaMode::teamSize($teamA['arena_mode'] ?? null) === 1) {
            return $this->calculateDuelStylePenalty($teamA, $teamB);
        }

        $subclassKeys = collect(array_keys($profileA['subclasses']))
            ->merge(array_keys($profileB['subclasses']))
            ->unique();

        $subclassPenalty = $subclassKeys->reduce(function (int $carry, string $subclass) use ($profileA, $profileB) {
            return $carry + (abs(($profileA['subclasses'][$subclass] ?? 0) - ($profileB['subclasses'][$subclass] ?? 0)) * self::PAIR_SUBCLASS_MISMATCH_WEIGHT);
        }, 0);

        $conjurerPenalty = abs($profileA['conjurer_count'] - $profileB['conjurer_count']) * self::PAIR_CONJURER_MISMATCH_PENALTY;
        $supportPenalty = abs($profileA['support_conjurers'] - $profileB['support_conjurers']) * self::PAIR_SUPPORT_MISMATCH_PENALTY;

        return $subclassPenalty + $conjurerPenalty + $supportPenalty;
    }

    /** Si los dos bandos comparten alguna cuenta de usuario. */
    private function compartenCuenta(array $teamA, array $teamB): bool
    {
        $cuentas = fn (array $team) => collect($team['entries'] ?? [])
            ->map(fn (Queue $queue) => (int) ($queue->player->user_id ?? 0))
            ->filter()
            ->all();

        return array_intersect($cuentas($teamA), $cuentas($teamB)) !== [];
    }

    /**
     * Lo lejos que queda un duelo de ser un espejo.
     *
     * Tres escalones: misma subclase no paga nada, misma clase con otra
     * subclase paga 8, y clase distinta paga 20. La cifra esta en puntos de MMR
     * y se suma a la diferencia de MMR del cruce, asi que dice exactamente
     * cuanto tiene que encajar mejor un rival de otro estilo para ganarle al
     * espejo -y, al reves, cuanto puede como mucho torcer la preferencia una
     * eleccion que el MMR habria hecho de otra forma.
     *
     * Los demas recargos -repetir cruce, solaparse con una partida reciente-
     * viven en la misma escala y conservan su significado: 900 sigue queriendo
     * decir "vale 900 de MMR evitar esta repeticion".
     *
     * Las clases son las del juego -guerrero, arquero, mago-, no los roles de
     * combate que usa resolveSubclassArchetype() para equilibrar equipos: un
     * jugador reconoce "mago contra mago", no "utilidad contra dano".
     */
    private function calculateDuelStylePenalty(array $teamA, array $teamB): int
    {
        $subclassA = $this->duelSubclass($teamA);
        $subclassB = $this->duelSubclass($teamB);

        if ($subclassA === null || $subclassB === null) {
            return 0;
        }

        if ($subclassA === $subclassB) {
            return 0;
        }

        $claseA = Player::SUBCLASS_ARCHETYPES[$subclassA] ?? null;
        $claseB = Player::SUBCLASS_ARCHETYPES[$subclassB] ?? null;

        // Una subclase desconocida -un dato viejo o un personaje raro- no puede
        // salir premiada con 0: se trata como el cruce mas lejano.
        if ($claseA === null || $claseB === null || $claseA !== $claseB) {
            return self::DUEL_ARCHETYPE_MISMATCH_PENALTY;
        }

        return self::DUEL_SUBCLASS_MISMATCH_PENALTY;
    }

    /** La subclase del unico jugador de un lado del duelo. */
    private function duelSubclass(array $team): ?string
    {
        $entries = $team['entries'] ?? null;

        if (!$entries instanceof Collection || $entries->count() !== 1) {
            return null;
        }

        $subclass = (string) ($entries->first()->player->subclass ?? '');

        return $subclass === '' ? null : $subclass;
    }

    private function calculateRepeatOverlapPenalty(array $teamA, array $teamB, Collection $recentMatchSnapshots): int
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
