<?php

namespace App\Services;

use App\Models\Party;
use App\Models\Queue;
use Illuminate\Support\Facades\Cache;

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
        $sweep = $this->resultService->sweepPostMatchState();
        $createdMatches = $this->matchmakingService->processRandomQueue(false);

        return [
            'skipped' => false,
            'reason' => null,
            'stale_queues' => $staleQueues,
            'expired_matches' => $expiredMatches,
            'created_matches' => $createdMatches,
            'expired_hunts' => (int) ($sweep['expired_hunts'] ?? 0),
            'expired_report_confirmations' => (int) ($sweep['expired_report_confirmations'] ?? 0),
        ];
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
