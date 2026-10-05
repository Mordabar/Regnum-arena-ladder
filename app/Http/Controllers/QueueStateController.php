<?php

namespace App\Http\Controllers;

use App\Models\ArenaMatch;
use App\Models\Party;
use App\Models\PartyMember;
use App\Models\Player;
use App\Models\Queue;
use App\Services\MatchPingService;
use App\Services\QueuePulseService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * El sondeo del lobby: el estado del jugador (cola, grupo, cruce) resumido en
 * una huella para que la pagina sepa cuando repintarse.
 *
 * Salio de QueueHubController, que con mas de 2.000 lineas mezclaba todo esto.
 */
class QueueStateController extends Controller
{
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
            ->select('id', 'player_id', 'queue_type', 'status', 'match_id', 'arena_mode', 'is_ranked')
            ->orderBy('id')
            ->get();

        // 3. Match State (via active queues + recent direct transitions)
        $matchIdsFromQueues = $activeQueues
            ->whereNotNull('match_id')
            ->pluck('match_id')
            ->map(fn ($matchId) => (string) $matchId)
            ->unique();

        $relevantMatches = ArenaMatch::query()
            // arena_mode NO es opcional aqui aunque el sondeo no lo pinte: los
            // avisos deciden con el si el nombre del rival se puede enseñar, y
            // sin la columna un duelo 1v1 -donde SI se ve- se leia como una
            // partida por equipos y salia firmado como "Rival".
            ->select('id', 'status', 'arena_mode', 'team_a', 'team_b', 'updated_at')
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

        // Los avisos van FUERA del hash, por el mismo motivo que el pulso: si
        // entraran, cada "voy de camino" recargaria la pantalla entera a los
        // dos bandos. Aqui fuera llegan en vivo y no mueven nada mas.
        $avisos = app(MatchPingService::class);

        return response()->json([
            'hash' => md5(json_encode($pollState)),
            'state' => $pollState,
            'queue_pulse' => app(QueuePulseService::class)->forMode(
                $activeQueues->first()?->arena_mode,
                $pulseRealm,
                $activeQueues->first()?->is_ranked !== false
            ),
            'pings' => $avisos->abierto($currentMatch)
                ? $avisos->historial($currentMatch, $this->jugadorEnElCruce($currentMatch, $playerIds))
                : [],
        ]);
    }

    /**
     * Con cual de mis personajes juego este cruce.
     *
     * Hace falta para los avisos: sin saber de que bando mira, no se puede
     * decidir si el nombre del que avisa se puede enseñar o el rival sigue
     * siendo anonimo.
     */
    private function jugadorEnElCruce(?ArenaMatch $match, Collection $playerIds): ?int
    {
        if (!$match instanceof ArenaMatch) {
            return null;
        }

        $mios = $playerIds->map(fn ($id) => (int) $id)->all();

        foreach ($match->getAllPlayers() as $fila) {
            $id = (int) ($fila['player_id'] ?? 0);

            if ($id !== 0 && in_array($id, $mios, true)) {
                // El id y nada mas: los avisos solo miran de que bando es, y
                // cargar el jugador entero seria una consulta por sondeo.
                return $id;
            }
        }

        return null;
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
}
