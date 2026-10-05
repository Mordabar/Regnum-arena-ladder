<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Services\Matchmaking\CompositionPolicy;
use App\Services\Matchmaking\MatchesSchema;
use App\Services\Matchmaking\PairingGenerator;
use App\Services\Matchmaking\RepeatOpponentPolicy;
use App\Services\Matchmaking\ZonePicker;
use App\Support\ArenaMode;
use App\Support\Competition;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /** Turno unico para emparejar, para que no barran varios a la vez. */
    private const PAIRING_LOCK = 'arena:emparejamiento';

    /**
     * Lo que dura el turno antes de caducar solo, en segundos.
     *
     * Es el tiempo que la cola se queda congelada si un proceso muere a media
     * faena -en un hosting compartido, lo normal es que lo mate el limite de
     * memoria o el de tiempo de la peticion-. Estaba en 120, y 120 segundos sin
     * emparejar a nadie es una cola que crece y vuelve a matar al siguiente.
     *
     * Treinta da de sobra: el barrido mas lento medido con la cola razonable
     * -240 esperando- son 1,4 segundos.
     */
    private const PAIRING_LOCK_TTL = 30;

    /**
     * Lo que espera quien llega y lo encuentra ocupado, en segundos.
     *
     * Muy corto a proposito. Esperar sale caro: cada peticion que espera es un
     * proceso del servidor parado sin hacer nada, y en un compartido hay pocos.
     * Con seis segundos de espera y ocho entradas a la vez, siete procesos se
     * quedaban 40 segundos en total para acabar sin emparejar a nadie.
     *
     * Con uno, el que llega segundo casi siempre pilla el turno igual -el
     * barrido normal dura decimas- y el que no, se va enseguida: su fila esta
     * guardada y la coge el barrido siguiente o el reloj del minuto.
     */
    private const PAIRING_LOCK_WAIT = 1;

    /**
     * A partir de cuantos esperando el barrido deja de hacerse en la peticion.
     *
     * Una cola de cientos tarda segundos en repartirse, y hacerlo mientras
     * alguien espera con la pagina en blanco es la forma de que el servidor
     * corte la peticion a medias -y, peor, de que la corte con el turno cogido-.
     * Pasado este tamaño, quien entra a la cola entra y ya: el reparto lo hace
     * el reloj del minuto, que corre por linea de comandos y no tiene prisa.
     */
    private const PAIRING_INLINE_LIMIT = 250;

    /** Donde se apunta la ultima vez que el reloj repartio la cola. */
    private const CONSOLE_SWEEP_KEY = 'arena:ultimo_barrido_del_reloj';

    /**
     * Cuanto se fia la web de que el reloj sigue vivo, en segundos.
     *
     * El cron corre cada minuto; con cinco de margen, un par de fallos
     * seguidos no hacen que las peticiones se pongan a repartir colas enormes,
     * y una caida de verdad si.
     */
    private const CONSOLE_SWEEP_GRACE = 300;

    /** Si esta instancia ya tiene el turno del barrido. */
    private bool $barriendo = false;

    public function __construct(
        private readonly DiscordBotService $discordBotService,
        private readonly ArenaZoneService $zoneService,
        private readonly PairingGenerator $pairingGenerator,
        private readonly CompositionPolicy $compositionPolicy,
        private readonly RepeatOpponentPolicy $repeatPolicy,
        private readonly ZonePicker $zonePicker,
        private readonly MatchesSchema $schema,
    ) {
    }

    public function isMatchesSchemaReady(): bool
    {
        $columns = $this->schema->getMatchesColumns();

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
            $enumOptions = $this->schema->extractEnumOptions((string) ($zoneColumn['type'] ?? ''));
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
     *
     * $ignorarEspera salta el tiempo de maduracion de las filas. Lo usan los dos
     * botones de "procesar la cola ahora" del panel, donde el admin esta mirando
     * a proposito lo que sale con lo que hay en cola en este instante.
     */
    public function processQueue(bool $expirePendingMatches = true, bool $ignorarEspera = false): int
    {
        // Las llamadas que salen del propio barrido -cancelar un cruce vencido
        // vuelve a emparejar- ya estan dentro del turno. Volver a pedirlo seria
        // esperarse a uno mismo.
        if ($this->barriendo) {
            return $this->barrerCola($expirePendingMatches, $ignorarEspera);
        }

        // Quien fuerza el reparto -los botones del panel- tampoco cede el turno
        // al reloj: es la unica palanca manual que le queda al admin, y es justo
        // cuando hay cola cuando la va a pulsar.
        if (!$ignorarEspera && $this->colaDemasiadoGrandeParaLaPeticion()) {
            Log::info('ArenaMatchmakingService: la cola es grande, se deja el reparto al reloj.', [
                'esperando' => $this->cuantosEsperan(),
            ]);

            return 0;
        }

        try {
            return Cache::lock(self::PAIRING_LOCK, self::PAIRING_LOCK_TTL)
                ->block(self::PAIRING_LOCK_WAIT, function () use ($expirePendingMatches, $ignorarEspera) {
                    $this->barriendo = true;

                    try {
                        return $this->barrerCola($expirePendingMatches, $ignorarEspera);
                    } finally {
                        $this->barriendo = false;
                    }
                });
        } catch (LockTimeoutException $e) {
            Log::info('ArenaMatchmakingService: otro barrido tenia el turno, se deja para el siguiente.');

            return 0;
        }
    }

    /**
     * Si conviene dejarle el reparto al reloj en vez de hacerlo aqui.
     *
     * Solo aplica dentro de una peticion web. Por linea de comandos -el cron
     * del minuto, o un comando a mano- se reparte siempre, sea del tamaño que
     * sea: ahi no hay nadie esperando delante de una pagina en blanco ni un
     * limite de tiempo de peticion que pueda cortar el proceso a medias.
     *
     * Y se le deja al reloj SOLO si el reloj esta vivo. Sin esa condicion, esto
     * era una trampa que se cerraba sola: si el cron no esta configurado -o se
     * cae- la cola crece, pasa de la raya, las peticiones dejan de repartir por
     * respeto a un reloj que no existe, y la cola no se vacia nunca. Mejor una
     * peticion lenta que un ladder muerto en silencio.
     */
    private function colaDemasiadoGrandeParaLaPeticion(): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        if (!$this->elRelojEstaVivo()) {
            return false;
        }

        return $this->cuantosEsperan() > self::PAIRING_INLINE_LIMIT;
    }

    /** Si el cron ha repartido hace poco. */
    private function elRelojEstaVivo(): bool
    {
        $ultimo = Cache::get(self::CONSOLE_SWEEP_KEY);

        return $ultimo !== null && (int) $ultimo >= now()->timestamp - self::CONSOLE_SWEEP_GRACE;
    }

    /** Deja constancia de que el reloj acaba de repartir. */
    private function anotarBarridoDeConsola(): void
    {
        if (!app()->runningInConsole()) {
            return;
        }

        Cache::put(self::CONSOLE_SWEEP_KEY, now()->timestamp, self::CONSOLE_SWEEP_GRACE * 2);
    }

    /** Cuanta gente hay esperando ahora mismo en alguna modalidad encendida. */
    private function cuantosEsperan(): int
    {
        $enabledModes = ArenaMode::enabled();

        if ($enabledModes === []) {
            return 0;
        }

        return Queue::query()
            ->whereIn('arena_mode', $enabledModes)
            ->where('status', 'waiting')
            ->whereNull('match_id')
            ->count();
    }

    private function barrerCola(bool $expirePendingMatches, bool $ignorarEspera = false): int
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

        // Nadie entra al reparto recien llegado: primero se le deja un momento
        // en cola para que se junte gente y el emparejador tenga entre quien
        // elegir. Sin esto, dos personas que coinciden por casualidad se cruzan
        // al instante aunque llegue un rival mucho mejor dos segundos despues.
        $maduros = $ignorarEspera ? now() : now()->subSeconds($this->segundosDeEspera());

        // Competitivo y amistoso se reparten por separado: cada tipo tiene su
        // propia cola de equipos y nunca se mezclan. El competitivo solo se
        // procesa mientras el ladder esta en juego; el amistoso, si esta
        // encendido en el panel.
        $creados = 0;

        foreach (Competition::open() as $kind) {
            $creados += $this->barrerTipo($kind === Competition::RANKED, $enabledModes, $maduros);
        }

        return $creados;
    }

    /**
     * Un barrido de la cola para un solo tipo de partida.
     *
     * @param  list<string>  $enabledModes
     */
    private function barrerTipo(bool $ranked, array $enabledModes, $maduros): int
    {
        $randomWaitingQueues = Queue::query()
            ->where('queue_type', 'random')
            ->where('is_ranked', $ranked)
            ->whereIn('arena_mode', $enabledModes)
            ->where('status', 'waiting')
            // La columna es NOT NULL, asi que hoy este OR no salva a nadie: es
            // un seguro barato por si algun dia se hace nullable o aparece una
            // fila de un esquema viejo. Una fila que no madura nunca seria una
            // persona en cola para siempre, y eso no puede depender de que nadie
            // toque la migracion. La red de verdad contra atascarse es
            // expires_at, pero esa CANCELA la cola en vez de emparejarla.
            ->where(function ($query) use ($maduros) {
                $query->whereNull('joined_at')
                    ->orWhere('joined_at', '<=', $maduros);
            })
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
            ->where('is_ranked', $ranked)
            ->whereIn('arena_mode', $enabledModes)
            ->where('status', 'waiting')
            // La columna es NOT NULL, asi que hoy este OR no salva a nadie: es
            // un seguro barato por si algun dia se hace nullable o aparece una
            // fila de un esquema viejo. Una fila que no madura nunca seria una
            // persona en cola para siempre, y eso no puede depender de que nadie
            // toque la migracion. La red de verdad contra atascarse es
            // expires_at, pero esa CANCELA la cola en vez de emparejarla.
            ->where(function ($query) use ($maduros) {
                $query->whereNull('joined_at')
                    ->orWhere('joined_at', '<=', $maduros);
            })
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
            $this->buildPremadeTeams($premadeWaitingQueues, $ranked)
        );

        $this->anotarBarridoDeConsola();

        $pairings = $this->pairingGenerator->buildMatchPairings($candidateTeams);
        $matchesCreated = 0;

        // Las zonas ocupadas se leen UNA vez por barrido y se van actualizando
        // en memoria conforme se crean partidas. Ver pickZone().
        $activeMatches = $this->zonePicker->cargarEnfrentamientosVivos();

        foreach ($pairings as $pairing) {
            try {
                $match = DB::transaction(function () use ($pairing, $activeMatches, $ranked) {
                    return $this->createArenaMatch($pairing['team_a'], $pairing['team_b'], $activeMatches, $ranked);
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

            // El aviso del navegador, ademas del DM de Discord: quien no
            // tenga los DM abiertos -o no los mire- se queda sin enterarse, y
            // un cruce caduca en dos minutos.
            app(\App\Services\WebPushService::class)->avisarAJugadores($match->getAllPlayers());

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

        // Antes de que desaparezca: se apunta quien se cruzo con quien.
        //
        // Sin esto, el descanso entre revanchas no servia para NADA en el unico
        // caso donde de verdad hace falta. El descanso se calcula leyendo
        // `matches`, y aqui la fila se esta borrando: rechazar, dejar pasar el
        // plazo de aceptacion o cancelar borran el rastro del cruce, asi que
        // quien cancelaba y volvia a entrar se reencontraba con el mismo rival
        // al instante. Que es, palabra por palabra, lo que se venia a arreglar.
        $this->repeatPolicy->anotarRivalesDeUnCruceBorrado($match);

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

        // Se apuntan antes: el cruce que nunca empezo se borra dentro de la
        // transaccion y despues ya no hay de donde sacar quien estaba.
        $jugadoresDelCruce = $match->getAllPlayers();

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
                                        // joined_at NO se toca: quien vuelve a
                                        // la cola porque otro tumbo el cruce ya
                                        // hizo su espera, y no tiene por que
                                        // hacerla otra vez por culpa ajena.
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
                                    // joined_at NO se toca: ya espero su turno una vez.
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

        // Sin esto, quien acepto y se fue al juego se enteraba de que el cruce
        // se cayo solo al volver a la web. A quien lo rechazo no: ya lo sabe.
        $excepto = $offendingPlayerId !== null ? [(int) $offendingPlayerId] : [];
        [$titulo, $cuerpo] = $reason === 'player_rejected'
            ? ['Cruce cancelado', 'Un jugador rechazo el combate. Si seguias en cola, vuelves a ella.']
            : ['Cruce caducado', 'No aceptaron todos a tiempo. Revisa tu cola en la arena.'];
        app(AvisosPendientesService::class)->registrarHecho($jugadoresDelCruce, 'cruce:' . $match->id, $titulo, $cuerpo, $excepto);
        app(\App\Services\WebPushService::class)->avisarAJugadores($jugadoresDelCruce, $excepto);

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
            $teamEntries = $this->compositionPolicy->findBestRealmTeam($available, $teamSize);

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
                'profile' => $this->compositionPolicy->buildQueueTeamProfile($teamEntries),
            ]);

            $available = $available
                ->reject(fn (Queue $queue) => $teamEntries->contains('id', $queue->id))
                ->values();
        }

        return $teams;
    }

    private function buildPremadeTeams(Collection $queues, bool $ranked = true): Collection
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

                $evaluation = $this->compositionPolicy->evaluateQueueTeam($teamEntries);
                if ($evaluation === null) {
                    return null;
                }

                $partySignature = $this->resolvePartySignature($teamEntries);
                if ($ranked && $this->countPartyMatchesToday($partySignature) >= $this->premadeDailyLimit()) {
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

    private function createArenaMatch(array $teamA, array $teamB, Collection $activeMatches, bool $ranked = true): ArenaMatch
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
        if ($ranked && ($teamA['queue_type'] ?? 'random') === 'premade' && $this->countPartyMatchesToday((string) ($teamA['party_signature'] ?? '')) >= $this->premadeDailyLimit()) {
            throw new \RuntimeException('Premade party A reached its daily limit.');
        }

        if ($ranked && ($teamB['queue_type'] ?? 'random') === 'premade' && $this->countPartyMatchesToday((string) ($teamB['party_signature'] ?? '')) >= $this->premadeDailyLimit()) {
            throw new \RuntimeException('Premade party B reached its daily limit.');
        }

        $attributes = [
            'match_code' => ArenaMatch::generateMatchCode(),
            'report_token' => ArenaMatch::generateReportToken(),
            'queue_mode' => ($teamA['queue_type'] ?? 'random') === 'premade' && ($teamB['queue_type'] ?? 'random') === 'premade'
                ? 'premade'
                : 'random',
            'arena_mode' => $arenaMode,
            'is_ranked' => $ranked,
            'team_a_realm' => $teamA['realm'],
            'team_b_realm' => $teamB['realm'],
            'team_a' => $teamAPayload,
            'team_b' => $teamBPayload,
            'zone' => $zone = $this->zonePicker->pickZone($teamA['realm'], $teamB['realm'], $activeMatches),
            'status' => 'pending_acceptance',
            'estimated_mmr_avg' => (int) round(($teamA['avg_mmr'] + $teamB['avg_mmr']) / 2),
            'expires_at' => $expiresAt,
        ];

        $match = ArenaMatch::create(array_merge(
            $attributes,
            $this->columnasDelPuntoDeEncuentro($zone),
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

    /**
     * Deja escrito en el cruce a que punto exacto van los dos bandos.
     *
     * Antes no se guardaba: cada navegador calculaba el punto al abrir el mapa,
     * leyendo un fichero de zonas que podia tener cacheado de dias atras. Dos
     * jugadores del mismo cruce acababan en sitios distintos, y el que llegaba
     * al sitio correcto se quedaba esperando a alguien que estaba a medio mapa.
     *
     * Congelandolo aqui, el cruce manda: mover una zona desde el panel solo
     * afecta a los cruces que se creen a partir de ese momento.
     *
     * Si la tabla es de un esquema viejo y no tiene las columnas, no se escribe
     * nada y el mapa vuelve a calcularlo como siempre. Peor que lo nuevo, igual
     * que lo de antes.
     *
     * @return array<string, mixed>
     */
    private function columnasDelPuntoDeEncuentro(?string $zone): array
    {
        $columnas = $this->schema->getMatchesColumns();

        if (!$columnas->has('meeting_point') || !$columnas->has('meeting_slot')) {
            return [];
        }

        $elegido = $this->zoneService->elegirPuntoDeEncuentro($zone);

        if ($elegido === null) {
            return [];
        }

        return [
            'meeting_slot' => $elegido['slot'],
            'meeting_point' => $elegido['punto'],
        ];
    }

    private function buildLegacyRealmColumns(
        string $teamARealm,
        array $teamAPayload,
        string $teamBRealm,
        array $teamBPayload
    ): array {
        $columns = $this->schema->getMatchesColumns();
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
        $columns = $this->schema->getMatchesColumns();
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

        $columns = $this->schema->getMatchesColumns();
        if (!$columns->has('team_a_party_signature') || !$columns->has('team_b_party_signature')) {
            return 0;
        }

        $query = ArenaMatch::query()
            ->whereDate('created_at', now()->toDateString())
            ->whereNotIn('status', ['cancelled', 'void'])
            // Los amistosos no gastan el cupo diario de la party: el limite
            // existe para que no se farmee ladder repitiendo la misma duo.
            ->where('is_ranked', true)
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
                // joined_at NO se toca: ya espero su turno una vez.
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
                    // joined_at NO se toca: ya espero su turno una vez.
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

    /**
     * Segundos que una fila de cola pasa madurando antes de entrar al reparto.
     *
     * El emparejador no puede elegir bien entre quien todavia no ha llegado. Si
     * reparte en el mismo instante en que alguien pulsa "entrar", el rival que
     * le toca es el unico que habia, no el mejor: dos segundos despues entra
     * uno con su mismo MMR y ya es tarde. Con una espera corta, la pasada ve a
     * los dos y elige.
     *
     * Corta de verdad: treinta segundos es lo que tarda alguien en mirar quien
     * hay conectado, no una sala de espera. Y no se acumula -el reloj corre
     * desde que entras, no desde la ultima pasada-, asi que el que lleva un
     * minuto en cola entra al reparto siguiente sin esperar nada mas.
     */
    private function segundosDeEspera(): int
    {
        $porDefecto = (int) config('arena.matchmaking_hold_seconds', 30);
        $segundos = (int) AppSetting::getValue('matchmaking_hold_seconds', $porDefecto);

        // Cinco minutos de tope: por encima de eso ya no es "que se junte
        // gente", es una cola parada, y un valor absurdo tecleado en el panel no
        // puede dejar la arena sin partidas.
        return max(0, min(300, $segundos));
    }
}
