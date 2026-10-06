<?php

namespace App\Http\Controllers;

use App\Models\ArenaMatch;
use App\Models\Party;
use App\Models\PartyMember;
use App\Models\Player;
use App\Models\Queue;
use App\Services\ArenaMatchmakingService;
use App\Services\MatchLineupService;
use App\Services\QueuePulseService;
use App\Support\ArenaMode;
use App\Support\Competition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * El lobby: la pantalla donde se elige guerrero, se entra en cola y se juega el
 * combate, y el fragmento que el sondeo repinta en su sitio.
 *
 * Salio de QueueHubController, que con mas de 2.000 lineas mezclaba todo esto.
 */
class LobbyController extends Controller
{
    public function index(Request $request)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        return view('arena.hub', $this->buildHubState($request));
    }

    /**
     * Solo el panel del lobby, en HTML suelto.
     *
     * El sondeo detectaba un cambio de estado y recargaba la pagina entera: se
     * perdia el scroll, parpadeaba todo y los escenarios 3D se volvian a
     * descargar. Ahora pide este trozo y lo cambia en su sitio.
     */
    public function consoleFragment(Request $request)
    {
        if (!Auth::check()) {
            abort(403);
        }

        $state = $this->buildHubState($request);

        // Sin guerreros no hay panel que repintar: el hub ensena otra cosa y el
        // navegador tiene que recargar para verla.
        if (!$state['hasRoster']) {
            return response()->json(['reload' => true]);
        }

        // Los modales del panel (invitar a premade, reglas de cola) se empujan a
        // una pila que en la pagina completa vuelca el layout. Aqui no hay
        // layout, asi que se recogen a mano y viajan aparte.
        //
        // El contador de renderizados se sube a mano porque al terminar el
        // ultimo render Blade vacia las pilas: sin esto los modales llegarian
        // siempre vacios.
        $factory = app('view');
        $factory->incrementRender();

        try {
            $head = view('arena._console_head', $state)->render();
            $html = view('arena._console', $state)->render();
            // La nube de invitaciones vive fuera del panel, pero cambia con el
            // mismo sondeo: sin esto una invitacion nueva sonaba y no se veia
            // hasta recargar la pagina.
            $invites = view('components.arena-party-invites', ['invites' => $state['pendingInvites']])->render();
            $modals = $factory->yieldPushContent('arena-modals');
        } finally {
            $factory->decrementRender();
            $factory->flushStateIfDoneRendering();
        }

        return response()->json([
            'head' => $head,
            'html' => $html,
            'invites' => $invites,
            'modals' => $modals,
            'title' => $state['pageTitle'] . ' — Regnum Arena Ladder',
            // La direccion que de verdad corresponde a lo que se pinta: si el
            // modo pedido ya no esta abierto, el panel enseno otro.
            'url' => route('lobby', array_filter([
                'mode' => $state['arenaMode'],
                'player' => $state['featured']?->id,
                'kind' => $state['kind'],
            ], fn ($v) => $v !== null)),
        ]);
    }

    /**
     * Todo lo que necesitan el hub y su panel, calculado una sola vez.
     */
    private function buildHubState(Request $request): array
    {
        $enabledModes = ArenaMode::enabled();

        // La modalidad pedida manda, salvo que este apagada: en ese caso se cae
        // a la que si este activa en vez de dejar al jugador en una pantalla
        // muerta. Si no hay ninguna encendida, la vista muestra el estado vacio.
        //
        // Lo que no se pide se recuerda: sin ?mode (el enlace "Lobby" del menu,
        // recargar, volver de otra pagina) se usa la ultima modalidad que
        // eligio este jugador, no la de por defecto. Antes cada visita sin
        // query lo devolvia a 2v2 y mas de uno entraba en la cola equivocada.
        $consultaModo = $request->query('mode');
        $requestedMode = ArenaMode::normalize(is_string($consultaModo) ? $consultaModo : null);
        $modoExplicito = $requestedMode !== null && ArenaMode::isEnabled($requestedMode);
        $modoRecordado = ArenaMode::normalize((string) $request->cookie('arena_modo'));

        $arenaMode = match (true) {
            $modoExplicito => $requestedMode,
            $modoRecordado !== null && ArenaMode::isEnabled($modoRecordado) => $modoRecordado,
            default => ArenaMode::default(),
        };
        $teamSize = ArenaMode::teamSize($arenaMode);

        $matchmakingService = app(ArenaMatchmakingService::class);

        // Competitivo o amistoso. Lo que se mira es lo pedido en la URL, salvo
        // que ese tipo este cerrado (el ladder en pausa): entonces el que si
        // se puede jugar. Con una cola o combate en marcha manda el suyo mas
        // abajo, porque cambiar de pestaña no cambia lo que ya esta jugando.
        $tipoExplicito = Competition::normalize($request->query('kind')) !== null;
        $kind = Competition::resolve($tipoExplicito ? $request->query('kind') : $request->cookie('arena_tipo'));
        $kindsOpen = Competition::open();
        $rankedPaused = !Competition::rankedOpen();

        $user = Auth::user();
        $players = $user->players()
            ->where('is_active', true)
            ->get();
        $premadeDailyLimit = $matchmakingService->getPremadeDailyLimit();

        $currentQueue = null;
        $currentMatch = null;
        $activeParty = null;
        $pendingInvites = collect();

        if ($players->isNotEmpty()) {
            $playerIds = $players->pluck('id');

            // Ojo: el estado propio del jugador NO se filtra por modalidad. Un
            // usuario solo puede tener una cola activa a la vez (lo garantiza
            // join()), asi que filtrar aqui le esconderia su propia cola al
            // cambiar de pestaña.
            $currentQueue = Queue::query()
                ->whereIn('status', ['waiting', 'matched', 'accepted'])
                ->whereIn('player_id', $playerIds)
                ->select('id', 'player_id', 'queue_type', 'arena_mode', 'is_ranked', 'joined_at', 'status', 'match_id', 'team_id', 'expires_at')
                ->latest('joined_at')
                ->first();

            if ($currentQueue) {
                $kind = Competition::kindOf($currentQueue->is_ranked !== false);

                // Con una cola o un combate en marcha, la pantalla es la de ESA
                // modalidad: es donde esta el jugador, y mostrarle otra era
                // justo lo que le hacia buscar partida en la equivocada.
                $modoDeLaCola = ArenaMode::normalize((string) $currentQueue->arena_mode);

                if (!$modoExplicito && $modoDeLaCola !== null && ArenaMode::isEnabled($modoDeLaCola)) {
                    $arenaMode = $modoDeLaCola;
                    $teamSize = ArenaMode::teamSize($arenaMode);
                }
            }

            if ($currentQueue?->match_id) {
                $currentMatch = ArenaMatch::find($currentQueue->match_id);

                // Una cola puede quedar colgada de un enfrentamiento ya
                // terminado (cancelado, resuelto o anulado). Sin esto la
                // pantalla anuncia "combate en curso" sobre algo que acabo
                // hace horas, con un reloj a cero y sin alineaciones.
                if ($currentMatch && !$currentMatch->isActive()) {
                    $currentMatch = null;
                    $currentQueue = null;
                }
            }

            $partyMember = PartyMember::query()
                ->whereIn('player_id', $playerIds)
                ->whereHas('party', function($q) {
                    $q->whereIn('status', Party::ACTIVE_STATUSES);
                })
                ->first();

            if ($partyMember) {
                if ($partyMember->is_accepted_invite) {
                    $activeParty = Party::with('members.player.user')->find($partyMember->party_id);
                }
            }

            $pendingInvites = PartyMember::query()
                ->with('party.leader.user', 'player')
                ->whereIn('player_id', $playerIds)
                ->where('is_accepted_invite', false)
                ->whereHas('party', function($q) {
                    $q->where('status', 'forming');
                })
                ->get();
        }

        // Cuanta gente espera ahora mismo, por reino. Se calcula desde el reino
        // del personaje que el jugador tiene en cola (o el primero que tenga),
        // para poder decirle que le falta en vez de un numero suelto.
        $pulseRealm = $players->firstWhere('id', $currentQueue?->player_id)?->realm
            ?? $players->first()?->realm;
        $queuePulse = app(QueuePulseService::class)->forMode($arenaMode, $pulseRealm, $kind === Competition::RANKED);

        // Alineaciones del enfrentamiento. Se calculan aqui, y no solo para el
        // cruce pendiente, porque el combate entero ocurre en esta pantalla: el
        // jugador acepta, pelea y reporta sin cambiar de pagina, y en los tres
        // momentos tiene que ver quien esta a su lado y quien enfrente.
        $matchLineup = null;
        $matchPlayer = null;
        $matchIsPendingAcceptance = false;

        if ($currentMatch && $currentMatch->isActive()) {
            $matchIsPendingAcceptance = $currentMatch->status === 'pending_acceptance'
                && !$currentMatch->isExpired();

            $matchLineup = app(MatchLineupService::class)->forViewer($currentMatch, $players->pluck('id')->all());
            $matchPlayer = $matchLineup
                ? $players->firstWhere('id', $matchLineup['viewer_player_id'])
                : null;
        }

        // El guerrero elegido viaja en la URL para que el rail funcione tambien
        // sin JavaScript: cada slot es un enlace de verdad, no solo un boton
        // que el script pinta.
        // Sin ?player en la URL, el ultimo que eligio (cookie que pone el
        // lobby al cambiar de guerrero). Si no es suyo, firstWhere no lo
        // encuentra y se cae al primero, como siempre.
        $requestedPlayerId = (int) ($request->query('player') ?: $request->cookie('arena_guerrero', 0));
        $requestedPlayer = $requestedPlayerId > 0
            ? $players->firstWhere('id', $requestedPlayerId)
            : null;

        // Se recuerda lo ultimo que eligio, o donde esta jugando, durante un
        // año. El guerrero va en la cookie que ya usaba el rail (sin cifrar:
        // la escribe tambien el JavaScript del lobby).
        if ($modoExplicito || $currentQueue) {
            Cookie::queue('arena_modo', $arenaMode, 60 * 24 * 365);
        }

        if ($tipoExplicito || $currentQueue) {
            Cookie::queue('arena_tipo', $kind, 60 * 24 * 365);
        }

        if ($currentQueue) {
            Cookie::queue('arena_guerrero', (string) $currentQueue->player_id, 60 * 24 * 365, null, null, null, false);
        } elseif ($request->query('player') && $players->firstWhere('id', (int) $request->query('player'))) {
            Cookie::queue('arena_guerrero', (string) (int) $request->query('player'), 60 * 24 * 365, null, null, null, false);
        }

        // Los enlaces solo llevan ?kind cuando no es el de por defecto: las
        // direcciones de siempre se quedan como estaban.
        $kindQuery = $kind === Competition::default() ? [] : ['kind' => $kind];

        return $this->deriveHubView(compact(
            'players',
            'premadeDailyLimit',
            'currentQueue',
            'currentMatch',
            'activeParty',
            'pendingInvites',
            'arenaMode',
            'kind',
            'kindsOpen',
            'rankedPaused',
            'kindQuery',
            'teamSize',
            'enabledModes',
            'queuePulse',
            'matchLineup',
            'matchPlayer',
            'matchIsPendingAcceptance',
            'requestedPlayer'
        ));
    }

    /**
     * Lo que antes se calculaba en un @php gigante al principio de la vista.
     *
     * Vive aqui porque ahora hay dos entradas -la pagina y el fragmento del
     * panel- y las dos tienen que ver exactamente lo mismo; con la logica
     * dentro del Blade la parcial se quedaba sin la mitad de las variables.
     */
    private function deriveHubView(array $data): array
    {
        /** @var \Illuminate\Support\Collection $players */
        $players = $data['players'];
        $currentQueue = $data['currentQueue'];
        $currentMatch = $data['currentMatch'];
        $activeParty = $data['activeParty'];
        $pendingInvites = $data['pendingInvites'];
        $matchLineup = $data['matchLineup'];
        $requestedPlayer = $data['requestedPlayer'];

        $hasRoster = $players->isNotEmpty();
        $hasActiveState = (bool) ($currentQueue || $currentMatch);
        // Sin ningun tipo abierto -ladder en pausa y amistosos apagados- no hay
        // nada que jugar, igual que con todas las modalidades apagadas.
        $modesAreOpen = !empty($data['enabledModes']) && !empty($data['kindsOpen']);

        // Ojo: $canJoinQueue NO depende de $modesAreOpen. El bloque de abajo
        // tambien tiene las invitaciones y el panel de party (con "Abandonar
        // Party"), y con las modalidades apagadas el jugador igual tiene que
        // poder salir.
        $canJoinQueue = $hasRoster && !$hasActiveState;
        $activePartyMode = $activeParty->arena_mode ?? null;
        $queueReportPendingConfirmation = (bool) ($currentMatch?->report
            && $currentMatch->report->status === 'pending_confirmation');

        // El guerrero del escenario: el que esta jugando manda sobre el resto.
        $activePlayerId = $currentQueue?->player_id ?? $matchLineup['viewer_player_id'] ?? null;

        // Mismo criterio que en el creador: cada raza por el rasgo que la
        // distingue, no por un adorno cualquiera.
        $raceIcons = [
            'nordo' => 'human', 'esquelio' => 'human', 'alturian' => 'human',
            'utghar' => 'horns', 'dwarf' => 'beard', 'molok' => 'hulk',
            'dark_elf' => 'ears', 'wood_elf' => 'ears', 'half_elf' => 'ears-short',
            'lamai' => 'ears-big',
        ];

        return array_merge($data, [
            'hasRoster' => $hasRoster,
            'hasActiveState' => $hasActiveState,
            'modesAreOpen' => $modesAreOpen,
            'canJoinQueue' => $canJoinQueue,
            'activePartyMode' => $activePartyMode,
            'activePartyModeIsOpen' => $activeParty ? ArenaMode::isEnabled($activePartyMode) : false,
            'queueTypeLabel' => $currentQueue
                ? trim((Queue::QUEUE_TYPES[$currentQueue->queue_type] ?? ucfirst($currentQueue->queue_type)) . ' ' . ArenaMode::label($currentQueue->arena_mode))
                : null,
            // El duelo no tiene companeros, asi que no tiene huecos. Ojo con el
            // range() a secas: range(2, 1) no devuelve una lista vacia, devuelve
            // [2, 1] contando hacia atras, y la ventana de invitar habria pintado
            // dos huecos de companero en una modalidad de un jugador.
            'premadeSupported' => ArenaMode::supportsPremade($data['arenaMode']),
            'premadeSlots' => $data['teamSize'] > 1 ? range(2, $data['teamSize']) : [],
            'queueReportPendingConfirmation' => $queueReportPendingConfirmation,
            'shouldPoll' => $hasRoster,
            'shouldAutoRefresh' => (bool) ($hasActiveState || $activeParty || $pendingInvites->isNotEmpty()),
            'stepperCurrent' => match (true) {
                !$hasRoster => 1,
                $canJoinQueue => 2,
                (bool) ($currentMatch && $currentMatch->status === 'pending_acceptance') => 3,
                (bool) $currentQueue && !$currentMatch => 3,
                (bool) $currentMatch && $currentMatch->status === 'in_progress' && !$currentMatch->report => 4,
                $queueReportPendingConfirmation => 4,
                default => 2,
            },
            'activePlayerId' => $activePlayerId,
            // Con un enfrentamiento en marcha las figuras que importan son las
            // del combate, no el escaparate: dos escenarios 3D compitiendo por
            // la atencion en la misma columna es ruido, y en movil es scroll
            // muerto.
            'showStage' => !$hasActiveState,
            // Con cola o combate activo manda el personaje que esta jugando; si
            // no, el que pida la URL; si tampoco, el primero.
            'featured' => $players->firstWhere('id', $activePlayerId)
                ?? $requestedPlayer
                ?? $players->first(),
            'lockedToPlayer' => $hasActiveState,
            'raceIcons' => $raceIcons,
            'championData' => $players->mapWithKeys(fn (Player $p) => [$p->id => [
                'name' => $p->cleanName(),
                // El buscador de companeros descarta a los del mismo usuario.
                'userId' => $p->user_id,
                'realm' => $p->realm,
                'realmName' => Player::REALMS[$p->realm] ?? ucfirst($p->realm),
                'subclass' => $p->subclass,
                'subclassName' => Player::SUBCLASSES[$p->subclass] ?? ucfirst($p->subclass),
                'race' => $p->race,
                'raceName' => $p->raceName(),
                'gender' => $p->gender ?: 'male',
                'pl' => number_format((float) $p->pl_points, 1),
                'mmr' => $p->mmr,
                'wins' => $p->wins,
                'losses' => $p->losses,
                'status' => $p->statusLabel(),
                'active' => (bool) $p->is_active,
                'locked' => $p->isQueueLocked(),
            ]]),
            'pageTitle' => $currentMatch
                ? ($currentMatch->status === 'pending_acceptance' ? 'Combate encontrado' : 'Combate activo')
                : ($currentQueue ? 'Buscando combate…' : 'Lobby'),
        ]);
    }
}
