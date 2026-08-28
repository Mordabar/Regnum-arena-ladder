<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\MatchResult;
use App\Models\Party;
use App\Models\PartyMember;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PlayerCleanupService
{
    public function __construct(
        private readonly LadderCacheService $ladderCacheService,
    ) {
    }

    public function purgePlayer(Player $player): array
    {
        $player->loadMissing('user');

        if ($this->hasActiveQueueState($player)) {
            throw new \RuntimeException('No puedes eliminar un jugador con cola o match activo.');
        }

        $targetPlayerIds = collect([$player->id]);
        $matches = $this->collectMatchesForPlayers($targetPlayerIds);
        $matchIds = $matches->pluck('id')->map(fn ($id) => (int) $id)->values();
        $affectedPlayerIds = $this->collectAffectedPlayerIds($matches, $matchIds)
            ->merge($targetPlayerIds)
            ->unique()
            ->values();
        $reportEvidence = $this->collectReportEvidence($matchIds);
        $managedUser = $player->user;

        $summary = DB::transaction(function () use ($player, $managedUser, $targetPlayerIds, $matchIds, $affectedPlayerIds) {
            $deletedReports = 0;
            $deletedResults = 0;
            $deletedMatches = 0;
            $deletedQueues = 0;
            $deletedParties = 0;
            $deletedUsers = 0;
            $deletedLegacyTestMatches = 0;

            $partyIds = PartyMember::query()
                ->whereIn('player_id', $targetPlayerIds)
                ->pluck('party_id')
                ->unique()
                ->values();

            if ($partyIds->isNotEmpty()) {
                $deletedParties = Party::query()
                    ->whereIn('id', $partyIds)
                    ->delete();
            }

            $deletedQueues = Queue::query()
                ->whereIn('player_id', $targetPlayerIds)
                ->delete();

            if (Schema::hasTable('test_matches')) {
                $deletedLegacyTestMatches = DB::table('test_matches')
                    ->whereIn('player1_id', $targetPlayerIds)
                    ->orWhereIn('player2_id', $targetPlayerIds)
                    ->orWhereIn('player3_id', $targetPlayerIds)
                    ->delete();
            }

            if ($matchIds->isNotEmpty()) {
                $deletedReports = MatchReport::query()
                    ->whereIn('match_id', $matchIds)
                    ->delete();

                $deletedResults = MatchResult::query()
                    ->whereIn('match_id', $matchIds)
                    ->delete();

                $deletedMatches = ArenaMatch::query()
                    ->whereIn('id', $matchIds)
                    ->delete();
            }

            $player->delete();

            if ($managedUser && !$managedUser->players()->exists() && $this->shouldDeleteOrphanedUser($managedUser)) {
                $managedUser->delete();
                $deletedUsers = 1;
            }

            $survivingPlayerIds = $affectedPlayerIds
                ->reject(fn ($playerId) => (int) $playerId === (int) $player->id)
                ->values();

            if ($survivingPlayerIds->isNotEmpty()) {
                $this->rebuildPlayerSummaries($survivingPlayerIds);
            }

            return [
                'players_deleted' => 1,
                'users_deleted' => $deletedUsers,
                'parties_deleted' => $deletedParties,
                'queues_deleted' => $deletedQueues,
                'legacy_test_matches_deleted' => $deletedLegacyTestMatches,
                'reports_deleted' => $deletedReports,
                'results_deleted' => $deletedResults,
                'matches_deleted' => $deletedMatches,
                'affected_player_ids' => $affectedPlayerIds->all(),
            ];
        });

        $allKnownRankingIds = Player::query()
            ->pluck('id')
            ->merge($summary['affected_player_ids'] ?? [])
            ->unique()
            ->values();

        $this->deleteEvidenceFiles($reportEvidence);
        $this->forgetRankingCaches($allKnownRankingIds);
        $this->ladderCacheService->forgetSummary();

        return $summary;
    }

    private function hasActiveQueueState(Player $player): bool
    {
        return $player->queues()
            ->whereIn('status', ['waiting', 'matched', 'accepted'])
            ->exists();
    }

    private function collectMatchesForPlayers(Collection $playerIds): Collection
    {
        if ($playerIds->isEmpty()) {
            return collect();
        }

        $matchIdsFromResults = MatchResult::query()
            ->whereIn('player_id', $playerIds)
            ->pluck('match_id');

        $matchIdsFromReports = MatchReport::query()
            ->where(function ($query) use ($playerIds) {
                $query->whereIn('reported_by_player_id', $playerIds)
                    ->orWhereIn('confirmed_by_player_id', $playerIds)
                    ->orWhereIn('rejected_by_player_id', $playerIds);
            })
            ->pluck('match_id');

        $playerIdLookup = $playerIds
            ->mapWithKeys(fn ($playerId) => [(int) $playerId => true])
            ->all();

        $matchesByRoster = ArenaMatch::query()
            ->select('id', 'team_a', 'team_b')
            ->get()
            ->filter(fn (ArenaMatch $match) => $this->matchIncludesAnyPlayer($match, $playerIdLookup));

        $allMatchIds = $matchIdsFromResults
            ->merge($matchIdsFromReports)
            ->merge($matchesByRoster->pluck('id'))
            ->map(fn ($matchId) => (int) $matchId)
            ->unique()
            ->values();

        if ($allMatchIds->isEmpty()) {
            return collect();
        }

        return ArenaMatch::query()
            ->with(['report', 'results'])
            ->whereIn('id', $allMatchIds)
            ->get()
            ->keyBy('id')
            ->sortKeys()
            ->values();
    }

    private function collectAffectedPlayerIds(Collection $matches, Collection $matchIds): Collection
    {
        $fromMatches = $matches
            ->flatMap(function (ArenaMatch $match) {
                return $match->getAllPlayers()
                    ->pluck('player_id')
                    ->map(fn ($playerId) => (int) $playerId)
                    ->filter();
            });

        $fromResults = $matchIds->isEmpty()
            ? collect()
            : MatchResult::query()
                ->whereIn('match_id', $matchIds)
                ->pluck('player_id')
                ->map(fn ($playerId) => (int) $playerId);

        return $fromMatches
            ->merge($fromResults)
            ->filter()
            ->unique()
            ->values();
    }

    private function collectReportEvidence(Collection $matchIds): Collection
    {
        if ($matchIds->isEmpty()) {
            return collect();
        }

        return MatchReport::query()
            ->whereIn('match_id', $matchIds)
            ->get()
            ->flatMap(function (MatchReport $report) {
                return collect($report->evidenceItems())
                    ->map(function (array $item) use ($report) {
                        return [
                            'disk' => $report->resolveEvidenceDisk((string) $item['slot']),
                            'path' => $item['path'] ?? null,
                        ];
                    });
            })
            ->filter(fn (array $entry) => filled($entry['disk']) && filled($entry['path']))
            ->unique(fn (array $entry) => ($entry['disk'] ?? '') . '|' . ($entry['path'] ?? ''))
            ->values();
    }

    private function deleteEvidenceFiles(Collection $evidence): void
    {
        foreach ($evidence as $entry) {
            try {
                Storage::disk($entry['disk'])->delete($entry['path']);
            } catch (\Throwable $e) {
                Log::warning('Player cleanup could not delete stored evidence', [
                    'disk' => $entry['disk'],
                    'path' => $entry['path'],
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function rebuildPlayerSummaries(Collection $playerIds): void
    {
        $players = Player::query()
            ->with('user')
            ->whereIn('id', $playerIds)
            ->get();

        foreach ($players as $player) {
            $results = MatchResult::query()
                ->where('player_id', $player->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($results->isEmpty()) {
                $player->update([
                    'pl_points' => 0,
                    'mmr' => $this->defaultMmrForPlayer($player),
                    'matches_played' => 0,
                    'wins' => 0,
                    'losses' => 0,
                ]);

                continue;
            }

            $lastResult = $results->last();

            $player->update([
                'pl_points' => round((float) $lastResult->pl_after, 1),
                'mmr' => (int) $lastResult->mmr_after,
                'matches_played' => $results->count(),
                'wins' => $results->where('result', 'win')->count(),
                'losses' => $results->filter(fn (MatchResult $result) => in_array($result->result, ['loss', 'no_show'], true))->count(),
            ]);
        }
    }

    private function defaultMmrForPlayer(Player $player): int
    {
        $discordId = (string) ($player->user?->discord_id ?? '');

        if (str_starts_with($discordId, 'admin-managed-') || str_starts_with($discordId, TestingLabService::LAB_DISCORD_PREFIX)) {
            return 800;
        }

        return 1000;
    }

    private function shouldDeleteOrphanedUser(User $user): bool
    {
        $discordId = (string) ($user->discord_id ?? '');
        $email = (string) ($user->email ?? '');

        return str_starts_with($discordId, 'admin-managed-')
            || str_starts_with($discordId, TestingLabService::LAB_DISCORD_PREFIX)
            || str_ends_with($email, '@' . TestingLabService::LAB_EMAIL_DOMAIN);
    }

    private function forgetRankingCaches(Collection $playerIds): void
    {
        if ($playerIds->isEmpty()) {
            return;
        }

        foreach ($playerIds->unique() as $playerId) {
            Cache::forget('player:' . $playerId . ':ranking_position');
        }
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
}
