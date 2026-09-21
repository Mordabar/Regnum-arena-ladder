<?php

namespace App\Services;

use App\Models\Party;
use App\Models\Queue;
use App\Support\ArenaMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ArenaMaintenanceService
{
    /**
     * Cada cuanto, como mucho, corre el mantenimiento.
     *
     * Eran cincuenta segundos, casi el minuto del cron, porque el reparto
     * pasaba dentro de la peticion de quien entraba a la cola y esto solo era
     * la red de seguridad. Desde que las filas maduran unos segundos antes de
     * entrar al reparto, ya no: quien entra el primero NO se empareja en su
     * propia peticion, se empareja en la pasada siguiente. Con cincuenta
     * segundos esa pasada podia tardar casi un minuto y medio en llegar.
     *
     * Quince segundos deja la espera real en poco mas de la espera configurada,
     * y una pasada sobre una cola corta -que es lo normal- no cuesta nada.
     */
    private const TICK_THROTTLE_SECONDS = 15;
    private const TICK_THROTTLE_KEY = 'arena:maintenance:tick-window';

    public function __construct(
        private readonly ArenaMatchmakingService $matchmakingService,
        private readonly ArenaMatchResultService $resultService,
    ) {
    }

    public function runTick(bool $respectThrottle = true): array
    {
        if ($respectThrottle && !Cache::add(self::TICK_THROTTLE_KEY, now()->timestamp, now()->addSeconds(self::TICK_THROTTLE_SECONDS))) {
            return [
                'skipped' => true,
                'reason' => 'throttled',
                'stale_queues' => 0,
                'expired_matches' => 0,
                'created_matches' => 0,
                'expired_hunts' => 0,
                'expired_report_confirmations' => 0,
            ];
        }

        $orphanQueues = $this->cleanupOrphanQueues();
        $staleQueues = $this->cleanupStaleWaitingQueues();
        $expiredMatches = $this->matchmakingService->expirePendingAcceptanceMatches(false);
        // Despues de expirar matches: al cancelarlos se reencola a los jugadores
        // conservando su modalidad, y si esa modalidad quedo apagada hay que
        // sacarlos de ahi en el mismo tick.
        $disabledModeQueues = $this->releaseQueuesInDisabledModes();
        $sweep = $this->resultService->sweepPostMatchState();

        // Los avisos rapidos viven lo que vive el cruce. En cuanto se cierra no
        // le importan a nadie, y guardarlos solo haria crecer una tabla que no
        // se consulta jamas.
        $avisosBorrados = app(MatchPingService::class)->limpiarCerrados();
        $createdMatches = $this->matchmakingService->processRandomQueue(false);

        return [
            'skipped' => false,
            'reason' => null,
            'orphan_queues' => $orphanQueues,
            'stale_queues' => $staleQueues,
            'disabled_mode_queues' => $disabledModeQueues,
            'expired_matches' => $expiredMatches,
            'created_matches' => $createdMatches,
            'expired_hunts' => (int) ($sweep['expired_hunts'] ?? 0),
            'expired_report_confirmations' => (int) ($sweep['expired_report_confirmations'] ?? 0),
            'pings_deleted' => $avisosBorrados,
        ];
    }

    /**
     * Libera a quien haya quedado esperando en una modalidad apagada.
     *
     * El panel admin ya cancela estas colas al apagar la modalidad, pero esa
     * limpieza es de una sola pasada y hay caminos que crean colas despues:
     * cancelMatch() reencola a los jugadores no culpables conservando su
     * arena_mode, asi que un rechazo o un timeout posterior al apagado los
     * dejaria esperando un match que nunca va a llegar (y, peor, bloqueados
     * para entrar a la modalidad que si esta viva, porque solo se permite una
     * cola activa por usuario). Este barrido corre en cada tick y cierra ese
     * hueco venga de donde venga.
     */
    public function releaseQueuesInDisabledModes(): int
    {
        $enabledModes = ArenaMode::enabled();

        $stuckQueues = Queue::query()
            ->where('status', 'waiting')
            ->whereNull('match_id')
            ->when(
                $enabledModes !== [],
                fn ($query) => $query->whereNotIn('arena_mode', $enabledModes)
            )
            ->get(['id', 'arena_mode', 'team_id']);

        if ($stuckQueues->isEmpty()) {
            return 0;
        }

        Queue::query()
            ->whereIn('id', $stuckQueues->pluck('id'))
            ->update([
                'status' => 'cancelled',
                'team_id' => null,
                'match_id' => null,
                'matched_at' => null,
                'expires_at' => null,
            ]);

        // Una sola autoridad para devolver partys a su estado previo, en vez de
        // repetir aqui la regla: asi no puede divergir de la de mas abajo, y
        // ademas respeta a las que siguen dentro de un match vivo.
        $affectedModes = $stuckQueues->pluck('arena_mode')->unique()->filter()->all();
        $this->releaseQueuedPartiesWithoutQueues();

        Log::info('ArenaMaintenanceService libero colas de modalidades apagadas', [
            'queues' => $stuckQueues->count(),
            'modes' => $affectedModes,
        ]);

        return $stuckQueues->count();
    }

    /**
     * Cierra las colas que apuntan a un enfrentamiento que ya no existe.
     *
     * Son huerfanas: 'matched' o 'accepted' con un match_id que no esta en la
     * tabla, o sin match_id ninguno. Un estado del que el jugador no sale por
     * ningun lado: join() le bloquea la cuenta entera por tener una cola activa,
     * leave() solo busca 'waiting', y ni la limpieza de caducadas ni el
     * emparejador miran nada que no sea 'waiting'. O sea, fuera del ladder para
     * siempre, con todos sus personajes, hasta que un admin lo saque a mano.
     *
     * La carrera que las creaba -aceptar mientras el rival rechaza- ya esta
     * cerrada con un candado en ArenaMatchController::accept(). Esto es la red
     * de debajo: recoge las que quedaran de antes en produccion y cualquier otra
     * que aparezca por un camino que no hayamos visto.
     */
    public function cleanupOrphanQueues(): int
    {
        $huerfanas = Queue::query()
            ->whereIn('status', ['matched', 'accepted'])
            ->where(function ($query) {
                $query->whereNull('match_id')
                    ->orWhereNotExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('matches')
                            ->whereColumn('matches.id', 'queues.match_id');
                    });
            })
            ->pluck('id');

        if ($huerfanas->isEmpty()) {
            return 0;
        }

        Queue::query()
            ->whereIn('id', $huerfanas)
            ->update([
                'status' => 'cancelled',
                'matched_at' => null,
                'expires_at' => null,
                'team_id' => null,
                'match_id' => null,
            ]);

        $this->releaseQueuedPartiesWithoutQueues();

        Log::warning('ArenaMaintenanceService cerro colas huerfanas', [
            'queues' => $huerfanas->count(),
        ]);

        return $huerfanas->count();
    }

    public function cleanupStaleWaitingQueues(): int
    {
        $expiredQueues = Queue::query()
            ->where('status', 'waiting')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get(['id', 'player_id', 'queue_type', 'team_id']);

        if ($expiredQueues->isEmpty()) {
            return 0;
        }

        Queue::query()
            ->whereIn('id', $expiredQueues->pluck('id'))
            ->update([
                'status' => 'cancelled',
                'expires_at' => null,
            ]);

        $this->releaseQueuedPartiesWithoutQueues();

        return $expiredQueues->count();
    }

    /**
     * Devuelve a su estado previo las partys que figuran "buscando" pero ya no
     * tienen ninguna cola viva.
     *
     * Antes esto se resolvia exigiendo que el conjunto EXACTO de player_id de
     * la party coincidiera con el de las colas expiradas. Bastaba que faltara
     * una fila (por ejemplo si el admin sacaba de la cola a un solo miembro con
     * remove_from_queue) para que ninguna party coincidiera: quedaba en
     * 'queued' de forma permanente, sin colas, y sus integrantes veian
     * "buscando oponente" para siempre. Ni enqueueParty ni leave() les daban
     * salida. Mirar si queda alguna cola viva no depende de esas coincidencias
     * y cubre todos los caminos.
     */
    public function releaseQueuedPartiesWithoutQueues(): int
    {
        $parties = Party::query()
            ->with('members:id,party_id,player_id,is_accepted_invite')
            ->where('status', 'queued')
            ->get();

        $released = 0;

        foreach ($parties as $party) {
            $memberPlayerIds = $party->members->pluck('player_id')->filter();

            if ($memberPlayerIds->isEmpty()) {
                continue;
            }

            // 'matched' y 'accepted' cuentan como vivas: esa party esta dentro
            // de un match en curso y debe seguir reflejandolo.
            $hasLiveQueue = Queue::query()
                ->whereIn('player_id', $memberPlayerIds->all())
                ->whereIn('status', ['waiting', 'matched', 'accepted'])
                ->exists();

            if ($hasLiveQueue) {
                continue;
            }

            $acceptedCount = (int) $party->members->where('is_accepted_invite', true)->count();

            $party->update([
                'status' => $acceptedCount >= $party->teamSize() ? 'ready' : 'forming',
            ]);

            $released++;
        }

        return $released;
    }
}
