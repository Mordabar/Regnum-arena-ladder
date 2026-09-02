<?php

namespace App\Http\Controllers;

use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Services\ArenaMatchResultService;
use App\Services\ArenaMatchmakingService;
use App\Services\MatchLineupService;
use App\Services\QueuePulseService;
use App\Services\TestingLabService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Party;
use App\Models\PartyMember;
use App\Support\ArenaMode;

class QueueHubController extends Controller
{
    public function index(Request $request)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $enabledModes = ArenaMode::enabled();

        // La modalidad pedida manda, salvo que este apagada: en ese caso se cae
        // a la que si este activa en vez de dejar al jugador en una pantalla
        // muerta. Si no hay ninguna encendida, la vista muestra el estado vacio.
        $requestedMode = ArenaMode::normalize($request->query('mode'));
        $arenaMode = ($requestedMode !== null && ArenaMode::isEnabled($requestedMode))
            ? $requestedMode
            : ArenaMode::default();
        $teamSize = ArenaMode::teamSize($arenaMode);

        $matchmakingService = app(ArenaMatchmakingService::class);

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
                ->select('id', 'player_id', 'queue_type', 'arena_mode', 'joined_at', 'status', 'match_id', 'team_id', 'expires_at')
                ->latest('joined_at')
                ->first();

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
        $queuePulse = app(QueuePulseService::class)->forMode($arenaMode, $pulseRealm);

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

        return view('arena.hub', compact(
            'players',
            'premadeDailyLimit',
            'currentQueue',
            'currentMatch',
            'activeParty',
            'pendingInvites',
            'arenaMode',
            'teamSize',
            'enabledModes',
            'queuePulse',
            'matchLineup',
            'matchPlayer',
            'matchIsPendingAcceptance'
        ));
    }

    public function premadeCandidates(Request $request)
    {
        if (!Auth::check()) {
            abort(403);
        }

        $validated = $request->validate([
            'leader_player_id' => 'required|exists:players,id',
            'query' => 'nullable|string|max:80',
            'selected_player_ids' => 'nullable|array',
            'selected_player_ids.*' => 'integer|exists:players,id',
        ]);

        $leader = Auth::user()->players()
            ->where('is_active', true)
            ->findOrFail((int) $validated['leader_player_id']);

        $selectedIds = collect($validated['selected_player_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->push($leader->id)
            ->unique()
            ->values();

        $selectedUserIds = Player::query()
            ->whereIn('id', $selectedIds)
            ->pluck('user_id');

        $search = trim((string) ($validated['query'] ?? ''));

        $query = Player::query()
            ->with('user:id,discord_username')
            ->where('is_active', true)
            ->where('realm', $leader->realm)
            ->whereNotIn('id', $selectedIds)
            ->whereNotIn('user_id', $selectedUserIds)
            ->where(function ($builder) {
                $builder->whereNull('queue_locked_until')
                    ->orWhere('queue_locked_until', '<=', now());
            })
            // Se descarta al candidato si CUALQUIER personaje de su cuenta esta
            // en cola: seleccionarlo daria un error recien al pulsar "Buscar".
            ->whereDoesntHave('user.players.queues', function ($builder) {
                $builder->whereIn('status', ['waiting', 'matched', 'accepted']);
            })
            ->whereNotIn('id', function ($builder) {
                $builder->select('player_id')
                    ->from('party_members')
                    ->whereIn('party_id', Party::query()
                        ->select('id')
                        ->whereIn('status', Party::ACTIVE_STATUSES)
                    );
            });

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('character_name', 'like', '%' . $search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('discord_username', 'like', '%' . $search . '%');
                    });
            });
        }

        $players = $query
            ->orderByDesc('mmr')
            ->orderBy('character_name')
            ->take(10)
            ->get()
            ->map(function (Player $player) {
                return [
                    'id' => $player->id,
                    'character_name' => $player->character_name,
                    'realm' => $player->realm,
                    'realm_label' => Player::REALMS[$player->realm] ?? ucfirst($player->realm),
                    'subclass' => $player->subclass,
                    'subclass_label' => Player::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass),
                    'user_id' => $player->user_id,
                    'owner_label' => $player->user?->discord_username ?? 'Sin usuario',
                    'mmr' => $player->mmr,
                    'pl_points' => round((float) $player->pl_points, 1),
                    'is_conjurer' => $player->subclass === 'conjurer',
                ];
            })
            ->values();

        return response()->json([
            'leader_realm' => $leader->realm,
            'leader_realm_label' => Player::REALMS[$leader->realm] ?? ucfirst($leader->realm),
            'results' => $players,
        ]);
    }

    public function sandbox(TestingLabService $testingLabService)
    {
        $this->ensureSandboxAccess();

        $matchmakingService = app(ArenaMatchmakingService::class);

        $sandbox = $this->buildSandboxData(collect(), $testingLabService, $matchmakingService);

        return view('admin.sandbox', compact('sandbox'));
    }

    public function join(Request $request)
    {
        try {
            $matchmakingService = app(ArenaMatchmakingService::class);

            $arenaMode = ArenaMode::resolve($request->input('arena_mode'));
            $teamSize = ArenaMode::teamSize($arenaMode);

            $request->validate([
                'player_id' => 'required|integer|exists:players,id',
                'arena_mode' => 'nullable|in:' . implode(',', ArenaMode::all()),
                'queue_type' => 'required|in:random,premade',
                'conjurer_role' => 'nullable|in:support,offensive',
                'party_player_ids' => 'nullable|array|size:' . $teamSize,
                'party_player_ids.*' => 'nullable|exists:players,id',
                'party_conjurer_roles' => 'nullable|array|size:' . $teamSize,
                'party_conjurer_roles.*' => 'nullable|in:support,offensive',
            ]);

            if (!ArenaMode::isEnabled($arenaMode)) {
                return back()->withErrors(['error' => 'La modalidad ' . $arenaMode . ' no esta activa en este momento.']);
            }

            if (!$matchmakingService->isMatchesSchemaReady()) {
                return back()->withErrors([
                    'error' => 'La tabla matches en produccion no tiene aun el esquema MVP v1. Ejecuta la migracion de compatibilidad antes de usar la cola real.',
                ]);
            }

            if ($request->queue_type === 'premade') {
                return back()->withErrors(['error' => 'El emparejamiento premade ahora se maneja mediante Partys. Refresca la página.']);
            }

            $player = Player::findOrFail((int) $request->player_id);
            $this->ensurePlayerCanQueueRandom($player);

            $conjurerRole = $this->resolveConjurerRoleForPlayer($player, $request->conjurer_role);

            // Comprobar y crear dentro de una transaccion con los personajes de
            // la cuenta bloqueados: sin esto, dos peticiones simultaneas (doble
            // clic, dos pestañas) leian "sin cola" a la vez y ambas insertaban,
            // dejando al usuario en dos colas y potencialmente dos matches.
            $created = DB::transaction(function () use ($player, $arenaMode, $conjurerRole) {
                $lockedPlayerIds = Player::query()
                    ->where('user_id', $player->user_id)
                    ->lockForUpdate()
                    ->pluck('id');

                $existingQueue = Queue::query()
                    ->whereIn('player_id', $lockedPlayerIds)
                    ->whereIn('status', ['waiting', 'matched', 'accepted'])
                    ->exists();

                if ($existingQueue) {
                    return false;
                }

                Queue::create([
                    'player_id' => $player->id,
                    'queue_type' => 'random',
                    'arena_mode' => $arenaMode,
                    'status' => 'waiting',
                    'conjurer_role' => $conjurerRole,
                    'estimated_mmr' => $player->mmr ?? 800,
                    'joined_at' => now(),
                    'expires_at' => now()->addMinutes(30),
                ]);

                return true;
            });

            if (!$created) {
                return back()->withErrors(['error' => 'El usuario ya tiene una cola o match activo.']);
            }

            $matchmakingService->processQueue();

            $playerQueue = Queue::query()
                ->where('player_id', $player->id)
                ->whereIn('status', ['waiting', 'matched', 'accepted'])
                ->latest('id')
                ->first();

            if ($playerQueue?->match_id) {
                return redirect()->route('lobby', ['mode' => $arenaMode])
                    ->with('success', $player->character_name . ' entro a cola y ya tiene un match real.');
            }

            return back()->with('success', $player->character_name . ' se unio a la cola.');
        } catch (\Throwable $e) {
            Log::error('Arena queue join failed', [
                'user_id' => Auth::id(),
                'player_id' => $request->input('player_id'),
                'queue_type' => $request->input('queue_type'),
                'message' => $e->getMessage(),
            ]);

            $message = 'No se pudo crear o procesar la cola real.';
            if (config('app.debug') || session('arena_admin.authenticated') === true || Auth::user()?->isAdmin()) {
                $message .= ' Detalle: ' . $e->getMessage();
            }

            return back()->withErrors(['error' => $message]);
        }
    }

    public function leave(Request $request)
    {
        $request->validate([
            'player_id' => 'required|exists:players,id',
        ]);

        $player = Player::findOrFail($request->player_id);

        if ($player->user_id !== Auth::id()) {
            return back()->withErrors(['error' => 'No tienes permiso.']);
        }

        $queue = Queue::query()
            ->where('player_id', $player->id)
            ->where('status', 'waiting')
            ->whereNull('match_id')
            ->latest('joined_at')
            ->first();

        if (!$queue) {
            return back()->withErrors(['error' => 'El personaje no esta en cola.']);
        }

        if ($queue->queue_type === 'premade') {
            $partyMember = PartyMember::where('player_id', $player->id)
                ->whereHas('party', function($q) {
                    $q->where('status', 'queued');
                })
                ->first();

            if ($partyMember) {
                $party = Party::find($partyMember->party_id);
                Queue::query()
                    ->whereIn('player_id', $party->members()->pluck('player_id'))
                    ->where('queue_type', 'premade')
                    ->where('status', 'waiting')
                    ->whereNull('match_id')
                    ->update([
                        'status' => 'cancelled',
                        'team_id' => null,
                        'match_id' => null,
                        'matched_at' => null,
                        'expires_at' => null,
                    ]);
                
                $party->update(['status' => 'ready']);
                return back()->with('success', 'La busqueda de la party ha sido cancelada. Ya pueden reencolar.');
            }
        }

        $queue->update([
            'status' => 'cancelled',
            'team_id' => null,
            'match_id' => null,
            'matched_at' => null,
            'expires_at' => null,
        ]);

        return back()->with('success', $player->character_name . ' salio de la cola random.');
    }

    public function createParty(Request $request, ArenaMatchmakingService $matchmakingService)
    {
        try {
            $arenaMode = ArenaMode::resolve($request->input('arena_mode'));
            $teamSize = ArenaMode::teamSize($arenaMode);

            $validated = $request->validate([
                'arena_mode' => 'nullable|in:' . implode(',', ArenaMode::all()),
                'party_player_ids' => 'required|array|size:' . $teamSize,
                'party_player_ids.*' => 'required|integer|distinct|exists:players,id',
                'party_conjurer_roles' => 'nullable|array|size:' . $teamSize,
                'party_conjurer_roles.*' => 'nullable|in:support,offensive',
            ]);

            if (!ArenaMode::isEnabled($arenaMode)) {
                return back()->withErrors(['error' => 'La modalidad ' . $arenaMode . ' no esta activa en este momento.']);
            }

            $selectedIds = collect($validated['party_player_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->values();

            if ($selectedIds->count() !== $teamSize || $selectedIds->unique()->count() !== $teamSize) {
                return back()->withErrors(['error' => 'La party debe tener exactamente ' . $teamSize . ' personajes distintos.']);
            }

            $players = Player::query()
                ->with('user')
                ->whereIn('id', $selectedIds)
                ->get()
                ->sortBy(fn (Player $player) => array_search($player->id, $selectedIds->all(), true))
                ->values();

            if ($players->count() !== $teamSize) {
                return back()->withErrors(['error' => 'No se pudieron cargar los personajes.']);
            }

            $leader = $players->first();
            if (!$leader || $leader->user_id !== Auth::id()) {
                return back()->withErrors(['error' => 'Debes liderar la party con uno de tus personajes.']);
            }

            if ($players->pluck('user_id')->unique()->count() !== $teamSize) {
                return back()->withErrors(['error' => 'La party debe tener ' . $teamSize . ' usuarios distintos.']);
            }

            $realms = $players->pluck('realm')->unique();
            if ($realms->count() !== 1) {
                return back()->withErrors(['error' => 'Todos deben ser del mismo reino.']);
            }

            $conflictingQueue = $this->findQueueConflictForPlayers($selectedIds);
            if ($conflictingQueue) {
                return back()->withErrors([
                    'error' => ($conflictingQueue->player?->character_name ?? 'Uno de los personajes')
                        . ' ya tiene una cola o match activo.',
                ]);
            }

            $conflictingPartyMember = $this->findPartyConflictForPlayers($selectedIds);
            if ($conflictingPartyMember) {
                return back()->withErrors(['error' => $this->describePartyConflict($conflictingPartyMember)]);
            }

            // Checks (Queues, Lockouts, Limits)
            $partyMatchesToday = $matchmakingService->countPartyMatchesTodayForPlayers($selectedIds->all(), $arenaMode);
            if ($partyMatchesToday >= $matchmakingService->getPremadeDailyLimit()) {
                return back()->withErrors(['error' => 'Esta party alcanzo su limite diario de ' . $matchmakingService->getPremadeDailyLimit() . ' matches.']);
            }

            $roleInputs = collect($validated['party_conjurer_roles'] ?? [])->values();
            $supportCount = 0; $composition = [];

            foreach ($players as $index => $player) {
                /** @var \App\Models\Player $player */
                if (!$player->is_active) throw new \RuntimeException('Todos los personajes de la party deben estar habilitados.');
                if ($player->isQueueLocked()) throw new \RuntimeException($player->character_name . ' esta bloqueado de las colas de juego.');
                
                $role = $this->resolveConjurerRoleForPlayer($player, $roleInputs->get($index));
                if ($role === 'support') $supportCount++;
                
                $composition[] = [
                    'player' => $player,
                    'role' => $role
                ];
            }

            if ($supportCount > 1) {
                return back()->withErrors(['error' => 'No se permiten 2 conjuradores soporte dentro de la misma party.']);
            }

            DB::transaction(function () use ($leader, $composition, $arenaMode) {
                $party = Party::create([
                    'leader_player_id' => $leader->id,
                    'status' => 'forming',
                    'realm' => $leader->realm,
                    'arena_mode' => $arenaMode,
                ]);

                foreach ($composition as $index => $comp) {
                    PartyMember::create([
                        'party_id' => $party->id,
                        'player_id' => $comp['player']->id,
                        'is_accepted_invite' => $index === 0,
                        'is_leader' => $index === 0,
                        'conjurer_role' => $comp['role'],
                    ]);
                }
            });

            return back()->with('success', 'Invitaciones enviadas a la Party.');

        } catch (\Throwable $e) {
            Log::warning('Party creation rejected', [
                'user_id' => Auth::id(),
                'selected_player_ids' => $request->input('party_player_ids', []),
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function acceptPartyInvite(Party $party, PartyMember $member)
    {
        if ($member->party_id !== $party->id || $party->status !== 'forming') {
            return back()->withErrors(['error' => 'Esta invitacion ya no es valida.']);
        }

        if ($member->player->user_id !== Auth::id()) {
            return back()->withErrors(['error' => 'No puedes aceptar invitaciones de otros jugadores.']);
        }

        $conflictingPartyMember = $this->findPartyConflictForPlayers(collect([$member->player_id]), $party->id);
        if ($conflictingPartyMember) {
            return back()->withErrors(['error' => $this->describePartyConflict($conflictingPartyMember)]);
        }

        // Por usuario, no por personaje: aceptar con un personaje mientras otro
        // de la misma cuenta esta en cola llevaria a dos matches simultaneos.
        if ($this->findQueueConflictForPlayers(collect([$member->player_id]))) {
            return back()->withErrors(['error' => 'Ya tienes un personaje en cola o en match. Sal de esa cola antes de aceptar la party.']);
        }

        $member->update(['is_accepted_invite' => true]);

        if ($party->fresh()->isFull()) {
            $party->update(['status' => 'ready']); // Can now enqueue
        }

        return back()->with('success', 'Has aceptado unirte a la party.');
    }

    public function rejectPartyInvite(Party $party, PartyMember $member)
    {
        if ($member->party_id !== $party->id || $party->status !== 'forming') {
            return back()->withErrors(['error' => 'Esta invitacion ya no es valida.']);
        }
        if ($member->player->user_id !== Auth::id()) {
            return back()->withErrors(['error' => 'No tienes permiso.']);
        }

        $party->update(['status' => 'dissolved']);
        PartyMember::where('party_id', $party->id)->delete();

        return back()->with('success', 'Has rechazado la invitacion y la party fue disuelta.');
    }

    public function leaveParty(Party $party)
    {
        $hasMember = $party->members()->whereIn('player_id', Auth::user()->players()->pluck('id'))->exists();
        if (!$hasMember) {
            return back()->withErrors(['error' => 'No perteneces a esta party.']);
        }

        DB::transaction(function () use ($party) {
            if ($party->status === 'queued') {
                // Cancel queues for all members
                Queue::query()
                    ->whereIn('player_id', $party->members->pluck('player_id'))
                    ->where('queue_type', 'premade')
                    ->where('status', 'waiting')
                    ->whereNull('match_id')
                    ->update([
                        'status' => 'cancelled',
                        'team_id' => null,
                        'match_id' => null,
                        'expires_at' => null,
                    ]);
            }

            $party->update(['status' => 'dissolved']);
            PartyMember::where('party_id', $party->id)->delete();
        });

        return back()->with('success', 'Has abandonado la party y esta se disolvio exitosamente.');
    }

    public function enqueueParty(Party $party, ArenaMatchmakingService $matchmakingService)
    {
        if ($party->leader->user_id !== Auth::id()) {
            return back()->withErrors(['error' => 'Solo el lider puede ingresar a la cola.']);
        }

        if ($party->status !== 'ready' || !$party->isFull()) {
            return back()->withErrors(['error' => 'La party no esta lista o ya esta en cola.']);
        }

        // Una party armada para una modalidad que el admin apago mientras tanto
        // no puede entrar a la cola.
        if (!ArenaMode::isEnabled($party->arena_mode)) {
            return back()->withErrors([
                'error' => 'La modalidad ' . ArenaMode::label($party->arena_mode) . ' ya no esta activa.',
            ]);
        }

        $partyPlayers = $party->members()->with('player.user')->get();

        $conflictingPartyMember = $this->findPartyConflictForPlayers($partyPlayers->pluck('player_id'), $party->id);
        if ($conflictingPartyMember) {
            return back()->withErrors(['error' => $this->describePartyConflict($conflictingPartyMember)]);
        }

        $conflictingQueue = $this->findQueueConflictForPlayers($partyPlayers->pluck('player_id'));
        if ($conflictingQueue) {
            return back()->withErrors([
                'error' => ($conflictingQueue->player?->character_name ?? 'Uno de los personajes')
                    . ' ya tiene una cola o match activo externamente.',
            ]);
        }

        // Entre crear la party y pulsar "Buscar" pueden pasar horas: hay que
        // revalidar lo que createParty comprobo en su momento.
        foreach ($partyPlayers as $member) {
            $memberPlayer = $member->player;

            if (!$memberPlayer || !$memberPlayer->is_active) {
                return back()->withErrors([
                    'error' => ($memberPlayer->character_name ?? 'Un integrante') . ' ya no esta activo.',
                ]);
            }

            if ($memberPlayer->isQueueLocked()) {
                $reason = $memberPlayer->queue_lock_reason_name ? ' (' . $memberPlayer->queue_lock_reason_name . ')' : '';

                return back()->withErrors([
                    'error' => $memberPlayer->character_name . ' esta bloqueado de las colas' . $reason . '.',
                ]);
            }
        }

        // El limite diario tambien se revalida: la party sobrevive a sus
        // matches, y sin esto entraba a la cola para quedarse ahi en silencio
        // (buildPremadeTeams la descarta sin avisar a nadie).
        $partyMatchesToday = $matchmakingService->countPartyMatchesTodayForPlayers(
            $partyPlayers->pluck('player_id')->all(),
            $party->arena_mode
        );

        if ($partyMatchesToday >= $matchmakingService->getPremadeDailyLimit()) {
            return back()->withErrors([
                'error' => 'Esta party alcanzo su limite diario de ' . $matchmakingService->getPremadeDailyLimit() . ' matches.',
            ]);
        }

        DB::transaction(function () use ($party, $partyPlayers) {
            $partySignature = collect($partyPlayers)->pluck('player.user_id')->sort()->values()->implode('-');
            $teamId = (string) Str::uuid();

            $composition = $partyPlayers->map(fn($member) => [
                'player_id' => $member->player_id,
                'user_id' => $member->player->user_id,
                'character_name' => $member->player->character_name,
                'subclass' => $member->player->subclass,
                'realm' => $member->player->realm,
                'discord_id' => (string) ($member->player->user->discord_id ?? ''),
                'conjurer_role' => $member->conjurer_role,
            ])->toArray();

            foreach ($partyPlayers as $member) {
                Queue::create([
                    'player_id' => $member->player_id,
                    'queue_type' => 'premade',
                    'arena_mode' => $party->arena_mode,
                    'status' => 'waiting',
                    'conjurer_role' => $member->conjurer_role,
                    'estimated_mmr' => $member->player->mmr ?? 800,
                    'team_composition' => $composition,
                    'premade_leader_discord_id' => (string) (Auth::user()->discord_id ?? ''),
                    'party_signature' => $partySignature,
                    'joined_at' => now(),
                    'expires_at' => now()->addMinutes(30),
                    'team_id' => $teamId,
                ]);
            }

            $party->update(['status' => 'queued']);
        });

        $matchmakingService->processQueue();

        return back()->with('success', 'La party entro en la cola de busqueda global.');
    }

    public function statePoll(Request $request)
    {
        if (!Auth::check()) {
            return response()->json([
                'hash' => 'unknown',
                'state' => null,
            ]);
        }

        $user = Auth::user();
        $playerIds = $user->players()->where('is_active', true)->pluck('id');
        
        if ($playerIds->isEmpty()) {
            return response()->json([
                'hash' => 'none',
                'state' => [
                    'party' => null,
                    'pending_invites' => [],
                    'queues' => [],
                    'current_match' => null,
                ],
            ]);
        }

        $playerIdLookup = $playerIds
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
        $recentCutoff = now()->subMinutes(5);
        $pollRelevantStatuses = ['pending_acceptance', 'in_progress', 'completed', 'disputed'];

        // 1. Party State
        $party = Party::query()
            ->select('parties.id', 'parties.status')
            ->whereIn('status', Party::ACTIVE_STATUSES)
            ->whereHas('members', function ($query) use ($playerIds) {
                $query->whereIn('player_id', $playerIds);
            })
            ->withCount([
                'members as accepted_members_count' => function ($query) {
                    $query->where('is_accepted_invite', true);
                },
            ])
            ->first();

        $pendingInvites = PartyMember::query()
            ->select('id', 'party_id', 'player_id')
            ->whereIn('player_id', $playerIds)
            ->where('is_accepted_invite', false)
            ->whereHas('party', function($q) {
                $q->where('status', 'forming');
            })
            ->orderBy('id')
            ->get();

        // 2. Queue State
        $activeQueues = Queue::query()
            ->whereIn('player_id', $playerIds)
            ->whereIn('status', ['waiting', 'matched', 'accepted'])
            // arena_mode se selecciona para poder contar el pulso de la cola en
            // la modalidad que el jugador esta jugando, no en la de por defecto.
            ->select('id', 'player_id', 'queue_type', 'status', 'match_id', 'arena_mode')
            ->orderBy('id')
            ->get();

        // 3. Match State (via active queues + recent direct transitions)
        $matchIdsFromQueues = $activeQueues
            ->whereNotNull('match_id')
            ->pluck('match_id')
            ->map(fn ($matchId) => (string) $matchId)
            ->unique();

        $relevantMatches = ArenaMatch::query()
            ->select('id', 'status', 'team_a', 'team_b', 'updated_at')
            ->with(['report:match_id,status,reporting_team,updated_at'])
            ->where(function ($query) use ($matchIdsFromQueues, $pollRelevantStatuses, $recentCutoff) {
                $query->where(function ($recentQuery) use ($pollRelevantStatuses, $recentCutoff) {
                    $recentQuery->whereIn('status', $pollRelevantStatuses)
                        ->where('updated_at', '>=', $recentCutoff);
                });

                if ($matchIdsFromQueues->isNotEmpty()) {
                    $query->orWhereIn('id', $matchIdsFromQueues);
                }
            })
            ->orderBy('id')
            ->get();

        $acceptedCountsByMatch = collect();
        if ($matchIdsFromQueues->isNotEmpty()) {
            $acceptedCountsByMatch = Queue::query()
                ->selectRaw('match_id, COUNT(*) as accepted_count')
                ->whereIn('match_id', $matchIdsFromQueues)
                ->where('status', 'accepted')
                ->groupBy('match_id')
                ->pluck('accepted_count', 'match_id');
        }

        $queueMatches = $relevantMatches
            ->filter(function (ArenaMatch $match) use ($matchIdsFromQueues) {
                return $matchIdsFromQueues->contains((string) $match->id)
                    && in_array($match->status, ['pending_acceptance', 'in_progress'], true);
            })
            ->values();

        // 4. Direct match state (catches transitions after queues are closed)
        $directMatches = $relevantMatches
            ->filter(function (ArenaMatch $match) use ($playerIdLookup, $pollRelevantStatuses, $recentCutoff) {
                return $match->updated_at !== null
                    && $match->updated_at->gte($recentCutoff)
                    && in_array($match->status, $pollRelevantStatuses, true)
                    && $this->matchIncludesAnyPlayer($match, $playerIdLookup);
            })
            ->values();

        $currentMatch = $this->resolveCurrentPollMatch($queueMatches, $directMatches);

        $pollState = [
            'party' => $party ? [
                // Party usa UUID: castear a int lo truncaba y hacia que dos
                // partys distintas parecieran la misma en el poller.
                'id' => (string) $party->id,
                'status' => (string) $party->status,
                'accepted_members_count' => (int) $party->accepted_members_count,
            ] : null,
            'pending_invites' => $pendingInvites
                ->map(fn (PartyMember $invite) => [
                    'id' => (int) $invite->id,
                    'party_id' => (string) $invite->party_id,
                    'player_id' => (int) $invite->player_id,
                ])
                ->values()
                ->all(),
            'queues' => $activeQueues
                ->map(fn (Queue $queue) => [
                    'id' => (int) $queue->id,
                    'player_id' => (int) $queue->player_id,
                    'queue_type' => (string) $queue->queue_type,
                    'status' => (string) $queue->status,
                    'match_id' => $queue->match_id !== null ? (string) $queue->match_id : null,
                ])
                ->values()
                ->all(),
            'current_match' => $currentMatch
                ? $this->buildPollMatchState($currentMatch, $acceptedCountsByMatch)
                : null,
        ];

        // El pulso de cola va DELIBERADAMENTE fuera del hash. Si entrara, cada
        // vez que cualquier jugador entrase o saliese de la cola cambiaria el
        // hash y el poller recargaria la pagina entera a todo el mundo. Aqui
        // fuera, el contador se refresca en vivo sin recargar nada.
        // El reino desde el que se cuenta es el del personaje que esta en cola.
        // Quien tiene personajes en varios reinos veria una pista equivocada si
        // se cogiese siempre el primero de la lista.
        $queuedPlayerId = $activeQueues->first()?->player_id;
        $pulseRealm = Player::query()
            ->when($queuedPlayerId, fn ($query) => $query->where('id', $queuedPlayerId))
            ->whereIn('id', $playerIds)
            ->value('realm');

        return response()->json([
            'hash' => md5(json_encode($pollState)),
            'state' => $pollState,
            'queue_pulse' => app(QueuePulseService::class)->forMode(
                $activeQueues->first()?->arena_mode,
                $pulseRealm
            ),
        ]);
    }

    private function matchIncludesAnyPlayer(ArenaMatch $match, array $playerIdLookup): bool
    {
        foreach ($match->getAllPlayers() as $player) {
            $playerId = (int) ($player['player_id'] ?? 0);

            if ($playerId !== 0 && isset($playerIdLookup[$playerId])) {
                return true;
            }
        }

        return false;
    }

    private function resolveCurrentPollMatch(Collection $queueMatches, Collection $directMatches): ?ArenaMatch
    {
        if ($queueMatches->isNotEmpty()) {
            return $queueMatches
                ->sortByDesc(fn (ArenaMatch $match) => $match->updated_at?->timestamp ?? 0)
                ->first();
        }

        if ($directMatches->isNotEmpty()) {
            return $directMatches
                ->sortByDesc(fn (ArenaMatch $match) => $match->updated_at?->timestamp ?? 0)
                ->first();
        }

        return null;
    }

    private function buildPollMatchState(ArenaMatch $match, Collection $acceptedCountsByMatch): array
    {
        $acceptedCount = (int) ($acceptedCountsByMatch->get((string) $match->id)
            ?? ($match->status === 'in_progress' ? $match->player_count : 0));

        return [
            'id' => (string) $match->id,
            'status' => (string) $match->status,
            'accepted_count' => $acceptedCount,
            'player_count' => (int) $match->player_count,
            'report_status' => $match->report?->status ? (string) $match->report->status : null,
            'reporting_team' => $match->report?->reporting_team ? (string) $match->report->reporting_team : null,
            'updated_at' => $match->updated_at?->timestamp,
        ];
    }

    private function ensurePlayerCanQueueRandom(Player $player): void
    {
        if ($player->user_id !== Auth::id()) {
            throw new \RuntimeException('No tienes permiso para usar este personaje.');
        }

        if (!$player->is_active) {
            throw new \RuntimeException('Este personaje esta deshabilitado: recuperalo desde el lobby para volver a usarlo.');
        }

        if ($player->isQueueLocked()) {
            $reason = $player->queue_lock_reason_name ? ' (' . $player->queue_lock_reason_name . ')' : '';
            throw new \RuntimeException(
                'Este personaje tiene bloqueo activo' . $reason . ' hasta ' . $player->queue_locked_until?->format('Y-m-d H:i')
            );
        }
    }

    private function resolveConjurerRoleForPlayer(Player $player, ?string $role): ?string
    {
        if ($player->subclass !== 'conjurer') {
            return null;
        }

        if (!in_array($role, ['support', 'offensive'], true)) {
            throw new \RuntimeException('Los conjuradores deben seleccionar un rol.');
        }

        return $role;
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
        ], $request->boolean('replace_existing', true));

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
            'status' => 'waiting',
            'conjurer_role' => $this->assignSandboxConjurerRole($player),
            'estimated_mmr' => $player->mmr ?? 800,
            'joined_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        return back()->with('success', $player->character_name . ' entro a la cola sandbox.');
    }

    public function sandboxEnqueueRealm(Request $request, TestingLabService $testingLabService, ArenaMatchmakingService $matchmakingService)
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
                'status' => 'waiting',
                'conjurer_role' => $this->assignSandboxConjurerRole($player),
                'estimated_mmr' => $player->mmr ?? 800,
                'joined_at' => now(),
                'expires_at' => now()->addMinutes(30),
            ]);
        }

        $matchmakingService->processQueue();

        return back()->with('success', 'Se encolaron ' . $players->count() . ' bots de ' . ucfirst($validated['realm']) . ' en ' . $arenaMode . ' y se ejecuto el matchmaking auto.');
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
            $created = $matchmakingService->processQueue();
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

        // El reporte lo firma un bot: si lo firmara la persona, seria ella
        // quien tendria que confirmarlo, y no habria nada que ensayar.
        $reporterEntry = collect($match->getAllPlayers())
            ->first(fn ($player) => $botPlayerIds->contains((int) ($player['player_id'] ?? 0)));

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
        $winnerTeam = $validated['winner_team']
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

    private function assignSandboxConjurerRole(Player $player): ?string
    {
        if ($player->subclass !== 'conjurer') {
            return null;
        }

        return random_int(1, 100) <= 30 ? 'support' : 'offensive';
    }

    /**
     * Busca una cola activa que impida encolar a estos personajes.
     *
     * El conflicto se evalua por USUARIO, no por personaje: una cuenta puede
     * tener hasta 5 personajes y una persona solo puede jugar un match a la
     * vez. Mirando solo el player_id, alguien podia entrar a random con un
     * personaje y a la vez a una party premade con otro, terminando en dos
     * matches simultaneos. join() ya lo hacia bien; esto alinea el camino
     * premade con esa misma regla.
     */
    private function findQueueConflictForPlayers(Collection $playerIds): ?Queue
    {
        if ($playerIds->isEmpty()) {
            return null;
        }

        $userIds = Player::query()
            ->whereIn('id', $playerIds->all())
            ->pluck('user_id')
            ->filter()
            ->unique();

        if ($userIds->isEmpty()) {
            return null;
        }

        return Queue::query()
            ->with('player')
            ->whereHas('player', fn ($query) => $query->whereIn('user_id', $userIds->all()))
            ->whereIn('status', ['waiting', 'matched', 'accepted'])
            ->orderBy('id')
            ->first();
    }

    private function findPartyConflictForPlayers(Collection $playerIds, ?string $ignorePartyId = null): ?PartyMember
    {
        if ($playerIds->isEmpty()) {
            return null;
        }

        $query = PartyMember::query()
            ->with(['player', 'party'])
            ->whereIn('player_id', $playerIds->all())
            ->whereIn('party_id', Party::query()
                ->select('id')
                ->whereIn('status', Party::ACTIVE_STATUSES)
            );

        if ($ignorePartyId !== null) {
            $query->where('party_id', '!=', $ignorePartyId);
        }

        return $query->orderBy('id')->first();
    }

    private function describePartyConflict(PartyMember $partyMember): string
    {
        $characterName = $partyMember->player?->character_name ?? 'Uno de los personajes';

        if ($partyMember->is_accepted_invite) {
            return $characterName . ' ya pertenece a otra party activa.';
        }

        return $characterName . ' ya tiene una invitacion de party pendiente.';
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
