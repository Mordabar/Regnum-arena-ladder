<?php

namespace App\Services;

use App\Models\Party;
use App\Models\Queue;
use App\Support\ArenaMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ArenaMaintenanceService
{
    private const TICK_THROTTLE_SECONDS = 50;
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

        $staleQueues = $this->cleanupStaleWaitingQueues();
        $expiredMatches = $this->matchmakingService->expirePendingAcceptanceMatches(false);
        // Despues de expirar matches: al cancelarlos se reencola a los jugadores
        // conservando su modalidad, y si esa modalidad quedo apagada hay que
        // sacarlos de ahi en el mismo tick.
        $disabledModeQueues = $this->releaseQueuesInDisabledModes();
        $sweep = $this->resultService->sweepPostMatchState();
        $createdMatches = $this->matchmakingService->processRandomQueue(false);

        return [
            'skipped' => false,
            'reason' => null,
            'stale_queues' => $staleQueues,
            'disabled_mode_queues' => $disabledModeQueues,
            'expired_matches' => $expiredMatches,
            'created_matches' => $createdMatches,
            'expired_hunts' => (int) ($sweep['expired_hunts'] ?? 0),
            'expired_report_confirmations' => (int) ($sweep['expired_report_confirmations'] ?? 0),
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

        // Las partys que estaban en esa cola vuelven a su estado previo para no
        // quedar congeladas en "buscando".
        $affectedModes = $stuckQueues->pluck('arena_mode')->unique()->filter()->all();

        if ($affectedModes !== []) {
            Party::query()
                ->whereIn('arena_mode', $affectedModes)
                ->where('status', 'queued')
                ->get()
                ->each(function (Party $party) {
                    $acceptedCount = (int) $party->members()->where('is_accepted_invite', true)->count();

                    $party->update([
                        'status' => $acceptedCount >= $party->teamSize() ? 'ready' : 'forming',
                    ]);
                });
        }

        Log::info('ArenaMaintenanceService libero colas de modalidades apagadas', [
            'queues' => $stuckQueues->count(),
            'modes' => $affectedModes,
        ]);

        return $stuckQueues->count();
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

        $expiredPremadeGroups = $expiredQueues
            ->where('queue_type', 'premade')
            ->filter(fn (Queue $queue) => filled($queue->team_id))
            ->groupBy('team_id');

        foreach ($expiredPremadeGroups as $queueGroup) {
            $playerIds = $queueGroup->pluck('player_id')->map(fn ($id) => (int) $id)->sort()->values();

            $party = Party::query()
                ->with('members:id,party_id,player_id,is_accepted_invite')
                ->where('status', 'queued')
                ->get()
                ->first(function (Party $party) use ($playerIds) {
                    return $party->members
                        ->pluck('player_id')
                        ->map(fn ($id) => (int) $id)
                        ->sort()
                        ->values()
                        ->all() === $playerIds->all();
                });

            if (!$party) {
                continue;
            }

            $acceptedCount = (int) $party->members
                ->where('is_accepted_invite', true)
                ->count();

            $party->update([
                'status' => $acceptedCount >= $party->teamSize() ? 'ready' : 'forming',
            ]);
        }

        return $expiredQueues->count();
    }
}
