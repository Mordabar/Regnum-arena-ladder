<?php

namespace App\Services;

use App\Models\Player;
use Illuminate\Support\Collection;

class LadderScoringService
{
    const PL_BASE_WIN = 3.0;
    const PL_BASE_LOSS = -2.0;
    const PL_CAP_WIN = 8.0;
    const PL_CAP_LOSS = -10.0;
    const PL_MIN_LOSS = -0.5;
    const MMR_K_FACTOR = 32;
    const GRAN_UNDERDOG_THRESHOLD = -300.0;
    const UNDERDOG_THRESHOLD = -100.0;
    const FAVORITE_THRESHOLD = 100.0;
    const GRAN_FAVORITE_THRESHOLD = 300.0;

    // MMR drives matchmaking value; PL only nudges the visible ladder context.
    const LADDER_PL_WEIGHT = 10.0;
    const PLAYER_PL_ADJUSTMENT_FACTOR = 0.08;
    const PLAYER_PL_ADJUSTMENT_CAP = 1.5;
    const TOP_LADDER_PRESSURE_START = 18.0;
    const TOP_LADDER_PRESSURE_FACTOR = 0.02;
    const TOP_LADDER_PRESSURE_CAP = 0.7;

    public function calculateMatchResult(array $winnerPlayerIds, array $loserPlayerIds, bool $persist = false): array
    {
        $winners = Player::whereIn('id', $winnerPlayerIds)->get();
        $losers = Player::whereIn('id', $loserPlayerIds)->get();

        return $this->calculateMatchResultFromCollections($winners, $losers, $persist);
    }

    public function calculateMatchResultFromSnapshots(array $winnerSnapshots, array $loserSnapshots): array
    {
        $winners = collect($winnerSnapshots)
            ->map(fn (array $snapshot) => $this->makeVirtualPlayer($snapshot));
        $losers = collect($loserSnapshots)
            ->map(fn (array $snapshot) => $this->makeVirtualPlayer($snapshot));

        return $this->calculateMatchResultFromCollections($winners, $losers, false);
    }

    private function calculateMatchResultFromCollections(Collection $winners, Collection $losers, bool $persist = false): array
    {
        if ($winners->isEmpty() || $losers->isEmpty()) {
            return ['error' => 'Equipos vacios'];
        }

        $winnerAvgMMR = $winners->avg('mmr');
        $loserAvgMMR = $losers->avg('mmr');
        $winnerAvgPL = $winners->avg('pl_points');
        $loserAvgPL = $losers->avg('pl_points');

        $mmrDiff = $winnerAvgMMR - $loserAvgMMR;
        $plDiff = $winnerAvgPL - $loserAvgPL;
        $effectiveDiff = $this->calculateEffectiveDifficultyDiff($mmrDiff, $plDiff);

        $category = $this->getMatchCategory($effectiveDiff);
        $basePlWin = $this->calculatePLChange($effectiveDiff, true);
        $basePlLoss = $this->calculatePLChange($effectiveDiff, false);

        $results = [
            'winner_avg_mmr' => round($winnerAvgMMR),
            'loser_avg_mmr' => round($loserAvgMMR),
            'winner_avg_pl' => round($winnerAvgPL, 1),
            'loser_avg_pl' => round($loserAvgPL, 1),
            'mmr_diff' => round($mmrDiff),
            'pl_diff' => round($plDiff, 1),
            'effective_diff' => round($effectiveDiff, 1),
            'category' => $category,
            'pl_win' => round($basePlWin, 1),
            'pl_loss' => round($basePlLoss, 1),
            'players' => [],
        ];

        foreach ($winners as $player) {
            $mmrChange = $this->calculateMMRChange($player->mmr, $loserAvgMMR, true);
            $plChange = $this->calculatePlayerPLChange($basePlWin, $player->pl_points, $loserAvgPL, true);
            $results['players'][] = $this->buildPlayerResult($player, 'win', $plChange, $mmrChange, $persist);
        }

        foreach ($losers as $player) {
            $mmrChange = $this->calculateMMRChange($player->mmr, $winnerAvgMMR, false);
            $plChange = $this->calculatePlayerPLChange($basePlLoss, $player->pl_points, $winnerAvgPL, false);
            $results['players'][] = $this->buildPlayerResult($player, 'loss', $plChange, $mmrChange, $persist);
        }

        $winnerChanges = collect($results['players'])->where('result', 'win')->pluck('pl_change');
        $loserChanges = collect($results['players'])->where('result', 'loss')->pluck('pl_change');
        $results['pl_win'] = round($winnerChanges->avg(), 1);
        $results['pl_loss'] = round($loserChanges->avg(), 1);

        return $results;
    }

    private function makeVirtualPlayer(array $snapshot): Player
    {
        $player = new Player();
        $player->id = (int) ($snapshot['player_id'] ?? 0);
        $player->character_name = (string) ($snapshot['character_name'] ?? 'Unknown');
        $player->realm = (string) ($snapshot['realm'] ?? 'unknown');
        $player->pl_points = (float) ($snapshot['pl_points'] ?? 0);
        $player->mmr = (int) ($snapshot['mmr'] ?? 1000);
        $player->matches_played = (int) ($snapshot['matches_played'] ?? 0);
        $player->wins = (int) ($snapshot['wins'] ?? 0);
        $player->losses = (int) ($snapshot['losses'] ?? 0);

        return $player;
    }

    private function calculateEffectiveDifficultyDiff(float $mmrDiff, float $plDiff): float
    {
        return $mmrDiff + ($plDiff * self::LADDER_PL_WEIGHT);
    }

    private function getMatchCategory(float $effectiveDiff): string
    {
        if ($effectiveDiff < self::GRAN_UNDERDOG_THRESHOLD) {
            return 'gran_underdog';
        }

        if ($effectiveDiff < self::UNDERDOG_THRESHOLD) {
            return 'underdog';
        }

        if ($effectiveDiff <= self::FAVORITE_THRESHOLD) {
            return 'parejo';
        }

        if ($effectiveDiff <= self::GRAN_FAVORITE_THRESHOLD) {
            return 'favorito';
        }

        return 'gran_favorito';
    }

    private function calculatePLChange(float $effectiveDiff, bool $isWin): float
    {
        if ($isWin) {
            $pl = $this->calculatePLWin($effectiveDiff);

            return round(min($pl, self::PL_CAP_WIN), 1);
        }

        $pl = $this->calculatePLLoss($effectiveDiff);

        return round(max($pl, self::PL_CAP_LOSS), 1);
    }

    private function calculatePLWin(float $effectiveDiff): float
    {
        if ($effectiveDiff < self::GRAN_UNDERDOG_THRESHOLD) {
            $extraDiff = abs($effectiveDiff) - abs(self::GRAN_UNDERDOG_THRESHOLD);
            $factor = min($extraDiff, 300) / 300;
            $pl = 5.1 + ($factor * 0.9);

            if ($extraDiff > 300) {
                $overflowFactor = min($extraDiff - 300, 400) / 400;
                $pl += $overflowFactor * 2.0;
            }

            return $pl;
        }

        if ($effectiveDiff < self::UNDERDOG_THRESHOLD) {
            $factor = (abs($effectiveDiff) - abs(self::UNDERDOG_THRESHOLD)) / 200;

            return 3.1 + ($factor * 1.9);
        }

        if ($effectiveDiff <= self::FAVORITE_THRESHOLD) {
            return self::PL_BASE_WIN;
        }

        if ($effectiveDiff <= self::GRAN_FAVORITE_THRESHOLD) {
            $factor = ($effectiveDiff - self::FAVORITE_THRESHOLD) / 200;

            return 2.9 - ($factor * 0.9);
        }

        $factor = min($effectiveDiff - self::GRAN_FAVORITE_THRESHOLD, 300) / 300;

        return 1.9 - ($factor * 1.4);
    }

    private function calculatePLLoss(float $effectiveDiff): float
    {
        $loserPerspective = -$effectiveDiff;

        if ($loserPerspective > self::GRAN_FAVORITE_THRESHOLD) {
            $extraDiff = $loserPerspective - self::GRAN_FAVORITE_THRESHOLD;
            $factor = min($extraDiff, 300) / 300;
            $pl = -(6.1 + ($factor * 1.9));

            if ($extraDiff > 300) {
                $overflowFactor = min($extraDiff - 300, 400) / 400;
                $pl -= $overflowFactor * 2.0;
            }

            return $pl;
        }

        if ($loserPerspective > self::FAVORITE_THRESHOLD) {
            $factor = ($loserPerspective - self::FAVORITE_THRESHOLD) / 200;

            return -(2.1 + ($factor * 3.9));
        }

        if ($loserPerspective >= -self::FAVORITE_THRESHOLD) {
            return self::PL_BASE_LOSS;
        }

        if ($loserPerspective >= -self::GRAN_FAVORITE_THRESHOLD) {
            $factor = (abs($loserPerspective) - self::FAVORITE_THRESHOLD) / 200;

            return -(1.6 - ($factor * 0.6));
        }

        $factor = min(abs($loserPerspective) - self::GRAN_FAVORITE_THRESHOLD, 300) / 300;

        return -(0.9 - ($factor * 0.4));
    }

    private function calculatePlayerPLChange(float $basePlChange, float $playerPL, float $opponentAvgPL, bool $isWin): float
    {
        $expectationGap = $playerPL - $opponentAvgPL;
        $ladderAdjustment = max(
            -self::PLAYER_PL_ADJUSTMENT_CAP,
            min(self::PLAYER_PL_ADJUSTMENT_CAP, -($expectationGap * self::PLAYER_PL_ADJUSTMENT_FACTOR))
        );
        $topLadderPressure = max(
            0,
            min(
                self::TOP_LADDER_PRESSURE_CAP,
                ($playerPL - self::TOP_LADDER_PRESSURE_START) * self::TOP_LADDER_PRESSURE_FACTOR
            )
        );

        $adjustedChange = $basePlChange + $ladderAdjustment;

        if ($isWin) {
            $adjustedChange -= $topLadderPressure;
            return round(max(0.5, min($adjustedChange, self::PL_CAP_WIN)), 1);
        }

        $adjustedChange -= $topLadderPressure;
        return round(max(self::PL_CAP_LOSS, min($adjustedChange, self::PL_MIN_LOSS)), 1);
    }

    private function calculateMMRChange(int $playerMMR, float $opponentAvgMMR, bool $isWin): int
    {
        $expectedScore = 1 / (1 + pow(10, ($opponentAvgMMR - $playerMMR) / 400));
        $actualScore = $isWin ? 1 : 0;

        return (int) round(self::MMR_K_FACTOR * ($actualScore - $expectedScore));
    }

    private function buildPlayerResult(
        Player $player,
        string $result,
        float $plChange,
        int $mmrChange,
        bool $persist = false
    ): array
    {
        $plBefore = $player->pl_points;
        $mmrBefore = $player->mmr;

        $newPL = max(0, $plBefore + $plChange);
        $newMMR = max(100, $mmrBefore + $mmrChange);

        if ($persist) {
            $player->update([
                'pl_points' => round($newPL, 1),
                'mmr' => $newMMR,
                'matches_played' => $player->matches_played + 1,
                'wins' => $result === 'win' ? $player->wins + 1 : $player->wins,
                'losses' => $result === 'loss' ? $player->losses + 1 : $player->losses,
            ]);
        }

        return [
            'player_id' => $player->id,
            'name' => $player->character_name,
            'realm' => $player->realm,
            'result' => $result,
            'pl_before' => $plBefore,
            'pl_after' => round($newPL, 1),
            'pl_change' => round($plChange, 1),
            'mmr_before' => $mmrBefore,
            'mmr_after' => $newMMR,
            'mmr_change' => $mmrChange,
        ];
    }
}
