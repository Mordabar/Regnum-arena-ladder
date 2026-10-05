<?php

namespace App\Http\Controllers;

use App\Models\ArenaMatch;
use App\Models\Party;
use App\Models\PartyMember;
use App\Models\Player;
use App\Models\Queue;
use App\Services\ArenaMatchmakingService;
use App\Services\ArenaMatchResultService;
use App\Services\MatchPingService;
use App\Services\TestingLabService;
use App\Support\ArenaMode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * El laboratorio de pruebas del panel: bots que entran en cola, aceptan,
 * reportan y confirman, para ensayar el flujo completo sin cuatro cuentas.
 *
 * Salio de QueueHubController, que con mas de 2.000 lineas mezclaba todo esto.
 */
class TestingLabController extends Controller
{
    public function sandbox(TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $matchmakingService = app(ArenaMatchmakingService::class);

        $sandbox = $this->buildSandboxData(collect(), $testingLabService, $matchmakingService);

        return view('admin.sandbox', compact('sandbox'));
    }

    public function sandboxSeed(Request $request, TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $validated = $request->validate([
            'ignis_count' => 'required|integer|min:0|max:60',
            'syrtis_count' => 'required|integer|min:0|max:60',
            'alsius_count' => 'required|integer|min:0|max:60',
            'replace_existing' => 'nullable|boolean',
        ]);

        $totalRequested = (int) $validated['ignis_count']
            + (int) $validated['syrtis_count']
            + (int) $validated['alsius_count'];

        if ($totalRequested === 0) {
            return back()->withErrors(['error' => 'Debes crear al menos un bot de prueba.']);
        }

        // Regenerar reemplazando ya no se bloquea por tener enfrentamientos
        // mixtos: seedRoster limpia el rastro entero antes de crear, y esa
        // limpieza devuelve a los jugadores reales los puntos de las pruebas.
        $createdPlayers = $testingLabService->seedRoster([
            'ignis' => (int) $validated['ignis_count'],
            'syrtis' => (int) $validated['syrtis_count'],
            'alsius' => (int) $validated['alsius_count'],
            // Por defecto NO se borra. Estaba al reves, y como la casilla sin
            // marcar no viaja en el formulario, ese defecto se aplicaba siempre:
            // marcar o desmarcar daba lo mismo y los bots viejos desaparecian
            // igual. Ahora el formulario manda un 0 explicito y el defecto
            // prudente es conservar.
        ], $request->boolean('replace_existing'));

        return redirect()->route('admin.testing')
            ->with('success', 'Sandbox de bots regenerado con ' . $createdPlayers . ' jugadores.');
    }

    public function sandboxToggleBot(Request $request, TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $validated = $request->validate([
            'player_id' => 'required|exists:players,id',
        ]);

        $player = Player::findOrFail($validated['player_id']);
        if (!$this->isSandboxPlayer($player, $testingLabService)) {
            abort(404);
        }

        $activeQueue = Queue::query()
            ->where('player_id', $player->id)
            ->whereIn('status', ['waiting', 'matched', 'accepted'])
            ->latest('joined_at')
            ->first();

        if ($activeQueue && $activeQueue->status === 'waiting' && $activeQueue->match_id === null) {
            $activeQueue->update([
                'status' => 'cancelled',
                'team_id' => null,
                'match_id' => null,
                'matched_at' => null,
                'expires_at' => null,
            ]);

            return back()->with('success', $player->character_name . ' salio de la cola sandbox.');
        }

        if ($activeQueue) {
            return back()->withErrors([
                'error' => $player->character_name . ' ya participa en una cola o match activo.',
            ]);
        }

        if ($player->isQueueLocked()) {
            $reason = $player->queue_lock_reason_name ? ' (' . $player->queue_lock_reason_name . ')' : '';
            return back()->withErrors([
                'error' => $player->character_name . ' sigue bloqueado' . $reason . ' hasta ' . $player->queue_locked_until?->format('Y-m-d H:i') . '.',
            ]);
        }

        $sandboxMode = ArenaMode::resolve($request->input('arena_mode'));

        if (!ArenaMode::isEnabled($sandboxMode)) {
            return back()->withErrors(['error' => 'La modalidad ' . $sandboxMode . ' no esta activa: la cola no se procesaria.']);
        }

        Queue::create([
            'player_id' => $player->id,
            'queue_type' => 'random',
            'arena_mode' => $sandboxMode,
            'is_ranked' => \App\Support\Competition::rankedOpen(),
            'status' => 'waiting',
            'conjurer_role' => $this->assignSandboxConjurerRole($player, $sandboxMode),
            'estimated_mmr' => $player->mmr ?? 800,
            'joined_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        return back()->with('success', $player->character_name . ' entro a la cola sandbox.');
    }

    public function sandboxEnqueueRealm(Request $request, TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $validated = $request->validate([
            'realm' => 'required|in:ignis,syrtis,alsius',
            'count' => 'required|integer|min:1|max:60',
            'arena_mode' => 'nullable|in:' . implode(',', ArenaMode::all()),
        ]);

        $arenaMode = ArenaMode::resolve($validated['arena_mode'] ?? null);

        if (!ArenaMode::isEnabled($arenaMode)) {
            return back()->withErrors(['error' => 'La modalidad ' . $arenaMode . ' no esta activa: los bots quedarian esperando sin emparejar.']);
        }

        $players = $testingLabService->testPlayersQuery()
            ->where('realm', $validated['realm'])
            ->where('is_active', true)
            ->orderByDesc('mmr')
            ->orderBy('character_name')
            ->get()
            ->filter(function (Player $player) {
                if ($player->isQueueLocked()) {
                    return false;
                }

                return !Queue::query()
                    ->where('player_id', $player->id)
                    ->whereIn('status', ['waiting', 'matched', 'accepted'])
                    ->exists();
            })
            ->take((int) $validated['count']);

        if ($players->isEmpty()) {
            return back()->withErrors(['error' => 'No hay bots libres en ese reino.']);
        }

        foreach ($players as $player) {
            Queue::create([
                'player_id' => $player->id,
                'queue_type' => 'random',
                'arena_mode' => $arenaMode,
                'is_ranked' => \App\Support\Competition::rankedOpen(),
                'status' => 'waiting',
                'conjurer_role' => $this->assignSandboxConjurerRole($player, $arenaMode),
                'estimated_mmr' => $player->mmr ?? 800,
                'joined_at' => now(),
                'expires_at' => now()->addMinutes(30),
            ]);
        }

        // Y NO se reparte aqui: los bots se quedan en cola a proposito, que es
        // lo que permite meter unos cuantos y mirar como los reparte de una
        // pasada. Barrer aqui ademas mentiria con la espera en cero.

        return back()->with('success', 'Se encolaron ' . $players->count() . ' bots de ' . ucfirst($validated['realm']) . ' en ' . $arenaMode . '. Se quedan esperando: pulsa "Procesar cola" cuando tengas dentro a todos los que quieras ver repartidos.');
    }

    public function sandboxProcess(ArenaMatchmakingService $matchmakingService)
    {
        $this->ensureSandboxAccess();

        if (!$matchmakingService->isMatchesSchemaReady()) {
            return back()->withErrors([
                'error' => 'La tabla matches aun no tiene un esquema compatible con el MVP. Corre las migraciones de compatibilidad antes de usar el sandbox integrado.',
            ]);
        }

        try {
            // Igual que el boton del panel: aqui se esta probando el reparto a
            // mano, asi que no se espera a que maduren las filas.
            $created = $matchmakingService->processQueue(true, true);
        } catch (\Throwable $e) {
            Log::error('Queue sandbox process failed', [
                'user_id' => Auth::id(),
                'message' => $e->getMessage(),
            ]);

            $message = 'No se pudo procesar la cola real desde el sandbox.';
            if (config('app.debug') || Auth::user()?->isAdmin()) {
                $message .= ' Detalle: ' . $e->getMessage();
            }

            return back()->withErrors(['error' => $message]);
        }

        return back()->with('success', 'Matchmaking ejecutado. Se crearon ' . $created . ' matches reales.');
    }

    public function sandboxAccept(Request $request, TestingLabService $testingLabService, ArenaMatchResultService $resultService)
    {
        $this->ensureSandboxAccess();

        $validated = $request->validate([
            'match_id' => 'nullable|exists:matches,id',
        ]);

        $botPlayerIds = $testingLabService->testPlayerIds();
        if ($botPlayerIds->isEmpty()) {
            return back()->withErrors(['error' => 'No hay bots creados en el sandbox.']);
        }

        $matches = isset($validated['match_id'])
            ? collect([ArenaMatch::findOrFail($validated['match_id'])])
            : $testingLabService->collectMatchesInvolvingPlayers($botPlayerIds, 40)
                ->where('status', 'pending_acceptance')
                ->values();

        $acceptedBots = 0;
        $promotedMatches = 0;

        // Antes se exigia que el match fuera SOLO de bots, y eso rompia el uso
        // documentado del laboratorio: encolar tu personaje por el flujo normal
        // y completar el cruce con bots. Ese match tiene una persona dentro, asi
        // que abortaba con un 404 sin explicacion.
        //
        // La restriccion no hacia falta aqui: aceptar solo cambia las filas de
        // cola de los bots (acceptBotParticipants filtra por sus ids) y el match
        // no arranca hasta que TODOS han aceptado, la persona incluida. No se
        // reparte ni un punto. Donde si hace falta es al resolver, y ahi sigue.
        foreach ($matches as $match) {
            ['accepted_bots' => $acceptedCount, 'promoted' => $promoted] = $this->acceptBotParticipants($match, $botPlayerIds, $resultService);

            $acceptedBots += $acceptedCount;
            $promotedMatches += $promoted ? 1 : 0;
        }

        if ($acceptedBots === 0) {
            return back()->withErrors(['error' => 'Ningun bot tenia una aceptacion pendiente en esos enfrentamientos. Si esperas a que acepte tu personaje, hazlo desde la cola normal.']);
        }

        return back()->with('success', 'Se aceptaron ' . $acceptedBots . ' jugadores bot. ' . $promotedMatches . ' matches pasaron a in_progress.');
    }

    public function sandboxAcceptParties(TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $botPlayerIds = $testingLabService->testPlayerIds();
        if ($botPlayerIds->isEmpty()) {
            return back()->withErrors(['error' => 'No hay bots creados en el sandbox.']);
        }

        $pendingMembers = PartyMember::query()
            ->whereIn('player_id', $botPlayerIds)
            ->where('is_accepted_invite', false)
            ->with('party')
            ->get();

        $accepted = 0;

        foreach ($pendingMembers as $member) {
            if ($member->party && $member->party->status === 'forming') {
                $member->update(['is_accepted_invite' => true]);
                $accepted++;

                if ($member->party->isFull()) {
                    $member->party->update(['status' => 'ready']);
                }
            }
        }

        if ($accepted === 0) {
            return back()->withErrors(['error' => 'No habia bots invitados a ninguna party.']);
        }

        return back()->with('success', "Se aceptaron $accepted invitaciones de party por parte de bots.");
    }

    public function sandboxInviteMe(Request $request, TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $botPlayerIds = $testingLabService->testPlayerIds();
        if ($botPlayerIds->isEmpty()) {
            return back()->withErrors(['error' => 'No hay bots suficientes en el sandbox. Genera bots primero.']);
        }

        $userPlayer = Auth::user()->players()->where('is_active', true)->first();
        if (!$userPlayer) {
            return back()->withErrors(['error' => 'No tienes ningun personaje activo real para ser invitado.']);
        }

        $existingParty = PartyMember::query()
            ->where('player_id', $userPlayer->id)
            ->whereHas('party', function($q) {
                $q->whereIn('status', Party::ACTIVE_STATUSES);
            })
            ->first();

        if ($existingParty) {
            return back()->withErrors(['error' => 'Tu personaje ya pertenece a una Party (o tiene invitacion pendiente). Abandonala primero.']);
        }

        // La party se completa con el personaje real mas los bots que falten
        // segun la modalidad: 1 bot en 2v2, 2 bots en 3v3.
        $arenaMode = ArenaMode::resolve($request->input('arena_mode'));

        if (!ArenaMode::isEnabled($arenaMode)) {
            return back()->withErrors(['error' => 'La modalidad ' . $arenaMode . ' no esta activa.']);
        }

        // En el duelo harian falta cero bots, y con cero bots esto seguia
        // adelante: la party se creaba con `$bots->first()` -null- de lider y
        // reventaba al leerle el id. Un duelo se prueba entrando a la cola
        // normal, que es exactamente lo que hace un jugador.
        if (!ArenaMode::supportsPremade($arenaMode)) {
            return back()->withErrors([
                'error' => 'El duelo ' . ArenaMode::label($arenaMode) . ' no arma party: prueba la cola normal con dos personajes de reinos distintos.',
            ]);
        }

        $requiredBots = ArenaMode::teamSize($arenaMode) - 1;

        $bots = Player::query()
            ->whereIn('id', $botPlayerIds)
            ->where('realm', $userPlayer->realm)
            ->whereNotIn('id', function ($builder) {
                $builder->select('player_id')
                    ->from('party_members')
                    ->whereIn('party_id', Party::query()
                        ->select('id')
                        ->whereIn('status', Party::ACTIVE_STATUSES)
                    );
            })
            ->take($requiredBots)
            ->get();
        if ($bots->count() < $requiredBots) {
            return back()->withErrors(['error' => 'No hay suficientes bots disponibles del mismo reino que tu personaje real ('.ucfirst($userPlayer->realm).'). Se necesitan '.$requiredBots.'.']);
        }

        $botLeader = $bots->first();

        $party = Party::create([
            'leader_player_id' => $botLeader->id,
            'status' => 'forming',
            'realm' => $botLeader->realm,
            'arena_mode' => $arenaMode,
        ]);

        // Con 2 bots (3v3) los roles se sortean por separado y podrian salir dos
        // conjuradores soporte, algo que la party real no permite. El primer
        // soporte se acepta y el resto pasa a ofensivo.
        $supportTaken = false;

        foreach ($bots as $index => $bot) {
            $role = $this->assignSandboxConjurerRole($bot);

            if ($role === 'support') {
                if ($supportTaken) {
                    $role = 'offensive';
                } else {
                    $supportTaken = true;
                }
            }

            PartyMember::create([
                'party_id' => $party->id,
                'player_id' => $bot->id,
                'is_accepted_invite' => true,
                'is_leader' => $index === 0,
                'conjurer_role' => $role,
            ]);
        }

        PartyMember::create([
            'party_id' => $party->id,
            'player_id' => $userPlayer->id,
            'is_accepted_invite' => false,
            'is_leader' => false,
            'conjurer_role' => 'offensive',
        ]);


        return back()->with('success', "¡El bot {$botLeader->character_name} te ha enviado una invitación a Party!");
    }

    public function sandboxResolve(Request $request, ArenaMatch $match, ArenaMatchResultService $resultService, TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $validated = $request->validate([
            'winner_team' => 'required|in:team_a,team_b,draw',
        ]);

        $botPlayerIds = $testingLabService->testPlayerIds();

        // Resolver SI reparte PL y MMR de verdad, asi que un match con personas
        // dentro no se cierra desde el laboratorio. Antes esto era un abort(404)
        // que dejaba al moderador mirando una pagina de error sin saber por que.
        if (!$testingLabService->matchUsesOnlyPlayerPool($match, $botPlayerIds)) {
            return back()->withErrors([
                'error' => 'El enfrentamiento ' . $match->match_code . ' tiene jugadores reales, asi que cerrarlo de golpe repartiria puntos saltandose la confirmacion del rival. Usa "Que un bot reporte": el bot sube su reporte y tu lo confirmas o lo rechazas desde el enfrentamiento, que es el flujo de verdad.',
            ]);
        }

        $this->resolveSandboxMatchInternal($match, $validated['winner_team'], $resultService, $botPlayerIds);

        return back()->with('success', 'El match ' . $match->match_code . ' fue resuelto para ' . $validated['winner_team'] . '.');
    }

    /**
     * Hace que un bot suba el reporte del enfrentamiento.
     *
     * Es la pieza que faltaba para ensayar el flujo entero. Cerrar un match
     * mixto de golpe se sigue negando, porque repartiria puntos sin que nadie
     * confirmara; lo que si se puede es empujar la mitad que le toca al bot y
     * dejar que la persona confirme o rechace desde su propia pantalla, que es
     * exactamente lo que hara un jugador de verdad.
     */
    public function sandboxBotReport(Request $request, ArenaMatch $match, ArenaMatchResultService $resultService, TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $validated = $request->validate([
            'winner_team' => 'nullable|in:team_a,team_b,draw',
        ]);

        if ($match->status !== 'in_progress') {
            return back()->withErrors(['error' => 'Solo se puede reportar un enfrentamiento en juego.']);
        }

        if ($match->report) {
            return back()->withErrors(['error' => 'Este enfrentamiento ya tiene un reporte esperando respuesta.']);
        }

        $botPlayerIds = $testingLabService->testPlayerIds();

        // El reporte lo firma un bot del equipo CONTRARIO a la persona. Solo el
        // rival puede confirmar un reporte, asi que un bot del propio equipo
        // dejaria a quien prueba mirando un "esperando al rival" sin nada que
        // pulsar, que es justo lo que se queria ensayar.
        $entries = collect($match->getAllPlayers());

        $humanEntry = $entries->first(fn ($player) => !$botPlayerIds->contains((int) ($player['player_id'] ?? 0)));
        $humanSide = $humanEntry
            ? $match->getTeamSideForPlayer((int) $humanEntry['player_id'], $humanEntry['discord_id'] ?? null)
            : null;

        $reporterEntry = $entries
            ->filter(fn ($player) => $botPlayerIds->contains((int) ($player['player_id'] ?? 0)))
            ->sortByDesc(function ($player) use ($match, $humanSide) {
                if (!$humanSide) {
                    return 0;
                }

                $side = $match->getTeamSideForPlayer((int) $player['player_id'], $player['discord_id'] ?? null);

                return $side !== null && $side !== $humanSide ? 1 : 0;
            })
            ->first();

        if (!$reporterEntry) {
            return back()->withErrors(['error' => 'Este enfrentamiento no tiene ningun bot que pueda reportar.']);
        }

        $reporter = Player::find((int) $reporterEntry['player_id']);

        if (!$reporter) {
            return back()->withErrors(['error' => 'El bot que iba a reportar ya no existe.']);
        }

        // Por defecto gana el equipo del bot que reporta: es lo que haria
        // cualquiera, y deja a la persona en el lado interesante, el de decidir
        // si confirma o rechaza.
        $winnerTeam = ($validated['winner_team'] ?? null)
            ?: ($match->getTeamSideForPlayer($reporter->id, (string) $reporter->user?->discord_id) ?? 'team_a');

        try {
            $resultService->submitSyntheticReport(
                $match,
                $reporter,
                $winnerTeam,
                'Reporte de prueba generado desde el laboratorio'
            );
        } catch (\Throwable $exception) {
            return back()->withErrors(['error' => 'No se pudo generar el reporte: ' . $exception->getMessage()]);
        }

        return back()->with(
            'success',
            'El bot ' . $reporter->character_name . ' reporto el enfrentamiento ' . $match->match_code
            . '. Ahora te toca a ti: entra al enfrentamiento y confirmalo o rechazalo.'
        );
    }

    /**
     * Hace que un bot conteste al reporte que subio la persona.
     *
     * Es la otra mitad del ensayo. Se podia hacer que un bot reportara para
     * confirmarlo tu, pero al reves no: subias tu reporte y no habia forma de
     * que el rival contestara, asi que la prueba se quedaba a medias esperando
     * a que venciera el plazo.
     */
    /**
     * Un bot manda un aviso del chat de combate.
     *
     * Sin esto el chat no se puede probar: hacen falta dos personas, una en
     * cada bando, y el laboratorio existe justamente para no necesitarlas. El
     * aviso lo firma un bot del equipo CONTRARIO, que es el unico caso que
     * interesa ensayar -el sonido, el bocadillo sobre la figura del rival y el
     * anonimato del nombre en 2v2 y 3v3-.
     */
    public function sandboxBotPing(Request $request, ArenaMatch $match, MatchPingService $avisos, TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $validated = $request->validate([
            'code' => 'required|string|max:40',
        ]);

        if (!$avisos->abierto($match)) {
            return back()->withErrors(['error' => 'Este enfrentamiento ya esta cerrado: no admite avisos.']);
        }

        $bots = $testingLabService->testPlayerIds();
        $entradas = collect($match->getAllPlayers());

        $persona = $entradas->first(fn ($fila) => !$bots->contains((int) ($fila['player_id'] ?? 0)));
        $bandoPersona = $persona
            ? $match->getTeamSideForPlayer((int) $persona['player_id'], $persona['discord_id'] ?? null)
            : null;

        $elegido = $entradas
            ->filter(fn ($fila) => $bots->contains((int) ($fila['player_id'] ?? 0)))
            ->sortByDesc(function ($fila) use ($match, $bandoPersona) {
                if (!$bandoPersona) {
                    return 0;
                }

                $bando = $match->getTeamSideForPlayer((int) $fila['player_id'], $fila['discord_id'] ?? null);

                return $bando !== null && $bando !== $bandoPersona ? 1 : 0;
            })
            ->first();

        if (!$elegido) {
            return back()->withErrors(['error' => 'Este enfrentamiento no tiene ningun bot que pueda avisar.']);
        }

        $bot = Player::find((int) $elegido['player_id']);

        if (!$bot) {
            return back()->withErrors(['error' => 'No se encuentra el bot que tenia que avisar.']);
        }

        $resultado = $avisos->enviar($match, $bot, $validated['code']);

        if (!$resultado['ok']) {
            return back()->withErrors(['error' => $resultado['motivo'] ?? 'No se pudo mandar el aviso.']);
        }

        return back()->with('success', $bot->character_name . ' ha mandado un aviso.');
    }

    public function sandboxBotConfirm(Request $request, ArenaMatch $match, ArenaMatchResultService $resultService, TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $validated = $request->validate([
            'decision' => 'nullable|in:confirm,reject',
        ]);

        $match->loadMissing('report');
        $report = $match->report;

        if (!$report || $report->status !== 'pending_confirmation') {
            return back()->withErrors(['error' => 'Este enfrentamiento no tiene un reporte esperando respuesta.']);
        }

        $botPlayerIds = $testingLabService->testPlayerIds();

        // Contesta un bot del equipo que NO reporto: es el unico lado que puede.
        $rivalSide = $report->reporting_team === 'team_a' ? 'team_b' : 'team_a';

        $responderEntry = collect($match->getTeamBySide($rivalSide))
            ->first(fn ($player) => $botPlayerIds->contains((int) ($player['player_id'] ?? 0)));

        if (!$responderEntry) {
            return back()->withErrors([
                'error' => 'El equipo que tiene que contestar no tiene ningun bot. Contesta tu desde el lobby.',
            ]);
        }

        $responder = Player::find((int) $responderEntry['player_id']);

        if (!$responder) {
            return back()->withErrors(['error' => 'El bot que iba a contestar ya no existe.']);
        }

        try {
            if (($validated['decision'] ?? 'confirm') === 'reject') {
                $resultService->rejectReport($report, $responder, 'Rechazo de prueba generado desde el laboratorio');
                $message = 'El bot ' . $responder->character_name . ' rechazo el reporte: el enfrentamiento pasa a disputa.';
            } else {
                $resultService->confirmReport($report, $responder);
                $message = 'El bot ' . $responder->character_name . ' confirmo el reporte y el ladder ya repartio los puntos.';
            }
        } catch (\Throwable $exception) {
            return back()->withErrors(['error' => 'No se pudo contestar: ' . $exception->getMessage()]);
        }

        return back()->with('success', $message);
    }

    public function sandboxResolveAll(ArenaMatchResultService $resultService, TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $botPlayerIds = $testingLabService->testPlayerIds();
        $matches = $testingLabService->collectMatchesInvolvingPlayers($botPlayerIds, 40)
            ->filter(function (ArenaMatch $match) use ($testingLabService, $botPlayerIds) {
                // SOLO bots: con interseccion bastaba un bot para que el
                // sandbox resolviera un match con jugadores reales dentro,
                // otorgandoles PL y MMR reales sin que el rival confirmara.
                return $match->status === 'in_progress'
                    && $testingLabService->matchUsesOnlyPlayerPool($match, $botPlayerIds);
            })
            ->values();

        $resolved = 0;

        foreach ($matches as $match) {
            $winnerTeam = random_int(0, 1) === 0 ? 'team_a' : 'team_b';
            $this->resolveSandboxMatchInternal($match, $winnerTeam, $resultService, $botPlayerIds);
            $resolved++;
        }

        if ($resolved === 0) {
            return back()->withErrors(['error' => 'No hay matches en progreso con bots para resolver.']);
        }

        return back()->with('success', 'Se resolvieron ' . $resolved . ' matches del sandbox integrado.');
    }

    /**
     * Deja los bots a cero sin borrarlos, y quita todo lo que jugaron.
     *
     * Ya no se niega cuando hay enfrentamientos mixtos: probar el flujo de
     * verdad obliga a jugar con tu propio personaje, asi que siempre habia
     * alguno y el boton no servia nunca. Lo que se hace ahora es deshacer los
     * puntos que esas pruebas repartieron.
     */
    public function sandboxReset(TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $result = $testingLabService->purgeTrace(false);

        return back()->with('success', $this->describePurge('Laboratorio reiniciado.', $result));
    }

    /** Lo mismo, pero ademas se lleva por delante a los bots y sus cuentas. */
    public function sandboxDestroy(TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $result = $testingLabService->purgeTrace(true);

        return back()->with('success', $this->describePurge('Rastro de pruebas eliminado.', $result));
    }

    /** Un resumen honesto de lo que se ha borrado y de lo que se ha devuelto. */
    private function describePurge(string $headline, array $result): string
    {
        $parts = [];

        if ($result['matches_deleted'] > 0) {
            $parts[] = $result['matches_deleted'] . ' enfrentamientos';
        }
        if ($result['queues_deleted'] > 0) {
            $parts[] = $result['queues_deleted'] . ' colas';
        }
        if ($result['players_deleted'] > 0) {
            $parts[] = $result['players_deleted'] . ' bots';
        }
        if ($result['evidence_deleted'] > 0) {
            $parts[] = $result['evidence_deleted'] . ' capturas';
        }

        $message = $headline;

        if ($parts !== []) {
            $message .= ' Se borraron ' . implode(', ', $parts) . '.';
        }

        if ($result['real_players_restored'] > 0) {
            $message .= ' A ' . $result['real_players_restored'] . ' personaje(s) real(es) se les devolvieron '
                . number_format(abs($result['pl_reverted']), 1) . ' PL y '
                . abs($result['mmr_reverted']) . ' MMR de las pruebas.';
        }

        return $message;
    }

    private function buildSandboxData(
        Collection $userPlayers,
        TestingLabService $testingLabService,
        ArenaMatchmakingService $matchmakingService
    ): array {
        $botPlayers = $testingLabService->testPlayersQuery()
            ->with('user')
            ->orderBy('realm')
            ->orderByDesc('pl_points')
            ->orderByDesc('mmr')
            ->orderBy('character_name')
            ->get();

        $botPlayerIds = $botPlayers->pluck('id');
        $activeQueues = $botPlayerIds->isEmpty()
            ? collect()
            : Queue::query()
                ->with('player')
                ->whereIn('player_id', $botPlayerIds)
                ->whereIn('status', ['waiting', 'matched', 'accepted'])
                ->orderByDesc('joined_at')
                ->get();

        $trackedPlayerIds = $botPlayerIds->merge($userPlayers->pluck('id'))->unique();
        $relatedMatches = $trackedPlayerIds->isEmpty()
            ? collect()
            : $testingLabService->collectMatchesInvolvingPlayers($trackedPlayerIds, 40)
                ->filter(fn (ArenaMatch $match) => $testingLabService->matchIntersectsPlayerPool($match, $botPlayerIds))
                ->values();

        $summary = [
            'players' => $botPlayers->count(),
            'idle_players' => $botPlayers->filter(function (Player $player) use ($activeQueues) {
                return !$player->isQueueLocked() && !$activeQueues->contains('player_id', $player->id);
            })->count(),
            'waiting' => $activeQueues->where('status', 'waiting')->count(),
            'matched' => $activeQueues->where('status', 'matched')->count(),
            'accepted' => $activeQueues->where('status', 'accepted')->count(),
            'pending_matches' => $relatedMatches->where('status', 'pending_acceptance')->count(),
            'in_progress_matches' => $relatedMatches->where('status', 'in_progress')->count(),
            'completed_matches' => $relatedMatches->where('status', 'completed')->count(),
        ];

        return [
            'summary' => $summary,
            'matchesSchemaReady' => $matchmakingService->isMatchesSchemaReady(),
            'playersByRealm' => $botPlayers->groupBy('realm'),
            'activeQueueByPlayer' => $activeQueues->keyBy('player_id'),
            'pendingMatches' => $relatedMatches->where('status', 'pending_acceptance')->values(),
            'inProgressMatches' => $relatedMatches->where('status', 'in_progress')->values(),
            // Que enfrentamientos son solo de bots. Los mixtos no se pueden
            // cerrar de golpe (repartirian puntos reales sin confirmacion), asi
            // que la vista tiene que ofrecerles otro boton, no el mismo.
            'botOnlyMatchIds' => $relatedMatches
                ->filter(fn (ArenaMatch $match) => $testingLabService->matchUsesOnlyPlayerPool($match, $botPlayerIds))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            'reportedMatchIds' => $relatedMatches
                ->filter(fn (ArenaMatch $match) => $match->report !== null)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            // Enfrentamientos donde el reporte espera respuesta y quien tiene
            // que contestar es un bot: son los que se pueden empujar desde aqui.
            'botCanAnswerMatchIds' => $relatedMatches
                ->filter(function (ArenaMatch $match) use ($botPlayerIds) {
                    $report = $match->report;

                    if (!$report || $report->status !== 'pending_confirmation') {
                        return false;
                    }

                    $rivalSide = $report->reporting_team === 'team_a' ? 'team_b' : 'team_a';

                    return collect($match->getTeamBySide($rivalSide))
                        ->contains(fn ($player) => $botPlayerIds->contains((int) ($player['player_id'] ?? 0)));
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            'recentMatches' => $relatedMatches->take(12)->values(),
        ];
    }

    private function acceptBotParticipants(ArenaMatch $match, Collection $botPlayerIds, ArenaMatchResultService $resultService): array
    {
        $acceptedQueueIds = Queue::query()
            ->where('match_id', (string) $match->id)
            ->where('status', 'matched')
            ->whereIn('player_id', $botPlayerIds)
            ->pluck('id');

        if ($acceptedQueueIds->isEmpty()) {
            return [
                'accepted_bots' => 0,
                'promoted'      => false,
            ];
        }

        Queue::query()
            ->whereIn('id', $acceptedQueueIds)
            ->update(['status' => 'accepted']);

        return [
            'accepted_bots' => $acceptedQueueIds->count(),
            'promoted'      => $resultService->promoteMatchToInProgressIfReady($match->fresh()),
        ];
    }

    private function resolveSandboxMatchInternal(
        ArenaMatch $match,
        string $winnerTeam,
        ArenaMatchResultService $resultService,
        ?Collection $botPlayerIds = null
    ): void {
        if ($match->status !== 'in_progress') {
            throw new \RuntimeException('El match ' . $match->match_code . ' no esta en progreso.');
        }

        if ($match->results()->exists()) {
            return;
        }

        if (!$match->report) {
            $reporterId = $match->getTeamPlayerIds($winnerTeam)[0] ?? null;
            $reporter = $reporterId ? Player::findOrFail($reporterId) : null;

            if (!$reporter) {
                throw new \RuntimeException('No se encontro reporter sintetico para ' . $match->match_code . '.');
            }

            $resultService->submitSyntheticReport($match, $reporter, $winnerTeam, 'Queue sandbox synthetic report');
            $match->refresh()->load('report');
        }

        if ($match->report?->status === 'pending_confirmation') {
            $reportingTeam = $match->report->reporting_team;
            $confirmerSide = $reportingTeam === 'team_a' ? 'team_b' : 'team_a';
            $confirmerId = $this->pickSandboxConfirmerId($match, $confirmerSide, $botPlayerIds);
            $confirmer = $confirmerId ? Player::findOrFail($confirmerId) : null;

            if (!$confirmer) {
                throw new \RuntimeException('No se encontro confirmador sintetico para ' . $match->match_code . '.');
            }

            $resultService->confirmReport($match->report->fresh(), $confirmer);
        }
    }

    private function pickSandboxConfirmerId(
        ArenaMatch $match,
        string $side,
        ?Collection $botPlayerIds = null
    ): ?int {
        $playerIds = collect($match->getTeamPlayerIds($side))->map(fn ($id) => (int) $id);

        if ($playerIds->isEmpty()) {
            return null;
        }

        if ($botPlayerIds && $botPlayerIds->isNotEmpty()) {
            $sandboxPlayerId = $playerIds
                ->first(fn (int $playerId) => $botPlayerIds->contains($playerId));

            if ($sandboxPlayerId) {
                return $sandboxPlayerId;
            }
        }

        return $playerIds->first();
    }

    private function isSandboxPlayer(Player $player, TestingLabService $testingLabService): bool
    {
        return $testingLabService->testPlayersQuery()
            ->whereKey($player->id)
            ->exists();
    }

    private function assignSandboxConjurerRole(Player $player, ?string $arenaMode = null): ?string
    {
        if ($player->subclass !== 'conjurer') {
            return null;
        }

        // Los bots juegan con las mismas reglas que la gente: en el duelo, el
        // conjurador entra ofensivo siempre.
        if (!ArenaMode::supportsPremade($arenaMode ?? ArenaMode::FALLBACK)) {
            return 'offensive';
        }

        return random_int(1, 100) <= 30 ? 'support' : 'offensive';
    }

    private function canUseSandbox(): bool
    {
        return session('arena_admin.authenticated') === true
            || (config('app.debug') && Auth::check())
            || (Auth::check() && Auth::user()?->isAdmin());
    }

    private function ensureSandboxAccess(): void
    {
        abort_unless($this->canUseSandbox(), 404);
    }
}
