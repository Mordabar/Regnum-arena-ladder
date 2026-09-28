<?php

namespace App\Http\Controllers;

use App\Models\Party;
use App\Models\PartyMember;
use App\Models\Player;
use App\Models\Queue;
use App\Services\ArenaMatchmakingService;
use App\Support\ArenaMode;
use App\Support\ConjurerRole;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Los grupos (premade): buscar aliados, invitar, aceptar o rechazar la
 * invitacion, salir del grupo y entrar en cola todos juntos.
 *
 * Salio de QueueHubController, que con mas de 2.000 lineas mezclaba todo esto.
 */
class PartyController extends Controller
{
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

            // Un duelo no tiene con quien hacer grupo. La vista ya no ofrece el
            // boton, pero el formulario es una peticion como cualquier otra y
            // sin esto una party de una persona entraba: pasaba la validacion
            // de tamano -exactamente 1 personaje- y se colaba en la cola premade
            // como un equipo valido.
            if (!ArenaMode::supportsPremade($arenaMode)) {
                return back()->withErrors([
                    'error' => 'El duelo ' . ArenaMode::label($arenaMode) . ' se juega en solitario: no hay grupo que armar.',
                ]);
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
                
                $role = ConjurerRole::resolve($player, $roleInputs->get($index));
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

                // Los invitados se enteran aunque no esten mirando: una
                // invitacion que nadie ve caduca sin que el lider sepa por que.
                // Sale despues de guardar, asi que el aviso ya la encuentra.
                app(\App\Services\WebPushService::class)->avisarAJugadores(
                    collect($composition)->slice(1)->map(fn ($c) => $c['player']->id)->all()
                );
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

            // El lider suele esperar con la pestaña de lado: se entera por
            // push de que ya puede entrar en cola.
            $lider = [(int) $party->leader_player_id];
            app(\App\Services\AvisosPendientesService::class)->registrarHecho(
                $lider,
                'party',
                'Tu equipo esta listo',
                'Todos aceptaron. Ya podeis entrar en cola.'
            );
            app(\App\Services\WebPushService::class)->avisarAJugadores($lider);
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
}
