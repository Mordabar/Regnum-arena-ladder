<?php

namespace App\Services\Matches;

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\MatchResult;
use App\Models\Player;
use App\Services\LadderScoringService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Cuanto vale un resultado: los multiplicadores de PL y MMR (limite diario,
 * revancha, premade contra random), las filas corregidas al rehacer un combate
 * y el desplazamiento del historial posterior.
 *
 * Parte de lo que era ArenaMatchResultService.
 */
class MatchPoints
{
    public function __construct(
        private readonly LadderScoringService $ladderScoringService,
    ) {
    }

    private const REPEAT_WINDOW_HOURS = 24;
    // Premades carry a coordination advantage, so mixed matches give random teams a
    // stronger compensation in ladder value while keeping same-type mirrors neutral.
    private const RANDOM_VS_PREMADE_PL_BONUS_PCT = 25;
    private const RANDOM_VS_PREMADE_MMR_BONUS_PCT = 18;
    private const PREMADE_VS_RANDOM_PL_WIN_PENALTY_PCT = 20;
    private const PREMADE_VS_RANDOM_MMR_WIN_PENALTY_PCT = 14;

    public function calculateDailyGainMultiplier(int $playerId): float
    {
        $positivePlToday = (float) MatchResult::query()
            ->where('player_id', $playerId)
            ->whereDate('created_at', now()->toDateString())
            ->where('pl_change', '>', 0)
            ->sum('pl_change');

        return match (true) {
            $positivePlToday >= 24 => 0.55,
            $positivePlToday >= 16 => 0.7,
            $positivePlToday >= 10 => 0.85,
            default => 1.0,
        };
    }

    private function calculateHistoricalDailyGainMultiplier(
        int $playerId,
        ?CarbonInterface $anchorTime,
        int $resultId
    ): float {
        if (!$anchorTime) {
            return 1.0;
        }

        $positivePlToday = (float) MatchResult::query()
            ->where('player_id', $playerId)
            ->whereDate('created_at', $anchorTime->toDateString())
            ->where('pl_change', '>', 0)
            ->where(function ($query) use ($anchorTime, $resultId) {
                $query->where('created_at', '<', $anchorTime)
                    ->orWhere(function ($sameTimestamp) use ($anchorTime, $resultId) {
                        $sameTimestamp->where('created_at', $anchorTime)
                            ->where('id', '<', $resultId);
                    });
            })
            ->sum('pl_change');

        return match (true) {
            $positivePlToday >= 24 => 0.55,
            $positivePlToday >= 16 => 0.7,
            $positivePlToday >= 10 => 0.85,
            default => 1.0,
        };
    }

    public function calculateRepeatMultiplier(ArenaMatch $match): float
    {
        $currentTeamA = $this->teamSignature($match->getTeamPlayerIds('team_a'));
        $currentTeamB = $this->teamSignature($match->getTeamPlayerIds('team_b'));
        $currentTeamAIds = $match->getTeamPlayerIds('team_a');
        $currentTeamBIds = $match->getTeamPlayerIds('team_b');

        $recentMatches = ArenaMatch::query()
            ->where('id', '!=', $match->id)
            ->where('status', 'completed')
            // Un amistoso contra el mismo rival no devalua el siguiente
            // competitivo: no repartio puntos, asi que no cuenta como repeticion.
            ->where('is_ranked', true)
            ->where('completed_at', '>=', now()->subHours(self::REPEAT_WINDOW_HOURS))
            ->get();

        $repeatCount = $recentMatches
            ->filter(function (ArenaMatch $previous) use ($currentTeamA, $currentTeamB) {
                $previousTeamA = $this->teamSignature($previous->getTeamPlayerIds('team_a'));
                $previousTeamB = $this->teamSignature($previous->getTeamPlayerIds('team_b'));

                return ($previousTeamA === $currentTeamA && $previousTeamB === $currentTeamB)
                    || ($previousTeamA === $currentTeamB && $previousTeamB === $currentTeamA);
            })
            ->count();

        if ($repeatCount >= 2) {
            return 0.6;
        }

        if ($repeatCount === 1) {
            return 0.8;
        }

        $partialRepeatLevel = $recentMatches->reduce(function (int $carry, ArenaMatch $previous) use ($currentTeamAIds, $currentTeamBIds) {
            $forwardLevel = $this->calculatePartialRepeatLevel(
                $this->countPlayerOverlap($currentTeamAIds, $previous->getTeamPlayerIds('team_a')),
                $this->countPlayerOverlap($currentTeamBIds, $previous->getTeamPlayerIds('team_b'))
            );

            $reverseLevel = $this->calculatePartialRepeatLevel(
                $this->countPlayerOverlap($currentTeamAIds, $previous->getTeamPlayerIds('team_b')),
                $this->countPlayerOverlap($currentTeamBIds, $previous->getTeamPlayerIds('team_a'))
            );

            return max($carry, $forwardLevel, $reverseLevel);
        }, 0);

        return match (true) {
            $partialRepeatLevel >= 2 => 0.85,
            $partialRepeatLevel === 1 => 0.95,
            default => 1.0,
        };
    }

    private function calculatePartialRepeatLevel(int $teamAOverlap, int $teamBOverlap): int
    {
        if ($teamAOverlap >= 2 && $teamBOverlap >= 2) {
            return 2;
        }

        if ($teamAOverlap >= 1 && $teamBOverlap >= 1) {
            return 1;
        }

        return 0;
    }

    private function countPlayerOverlap(array $currentPlayers, array $previousPlayers): int
    {
        return count(array_intersect(
            array_map('intval', $currentPlayers),
            array_map('intval', $previousPlayers)
        ));
    }

    private function teamSignature(array $playerIds): string
    {
        sort($playerIds);

        return implode('-', $playerIds);
    }

    public function calculateQueueTypeMultipliers(string $result, string $playerQueueType, string $opponentQueueType): array
    {
        if ($playerQueueType === $opponentQueueType) {
            return ['pl' => 1.0, 'mmr' => 1.0];
        }

        $randomPlBonus = max(0, (float) AppSetting::getValue('random_vs_premade_pl_bonus_pct', self::RANDOM_VS_PREMADE_PL_BONUS_PCT)) / 100;
        $randomMmrBonus = max(0, (float) AppSetting::getValue('random_vs_premade_mmr_bonus_pct', self::RANDOM_VS_PREMADE_MMR_BONUS_PCT)) / 100;
        $premadePlWinPenalty = max(0, (float) AppSetting::getValue('premade_vs_random_pl_win_penalty_pct', self::PREMADE_VS_RANDOM_PL_WIN_PENALTY_PCT)) / 100;
        $premadeMmrWinPenalty = max(0, (float) AppSetting::getValue('premade_vs_random_mmr_win_penalty_pct', self::PREMADE_VS_RANDOM_MMR_WIN_PENALTY_PCT)) / 100;

        $playerIsRandom = $playerQueueType === 'random' && $opponentQueueType === 'premade';
        $playerIsPremade = $playerQueueType === 'premade' && $opponentQueueType === 'random';

        if (!$playerIsRandom && !$playerIsPremade) {
            return ['pl' => 1.0, 'mmr' => 1.0];
        }

        if ($playerIsRandom) {
            return [
                'pl' => $result === 'win' ? 1 + $randomPlBonus : 1 - $premadePlWinPenalty,
                'mmr' => $result === 'win' ? 1 + $randomMmrBonus : 1 - $premadeMmrWinPenalty,
            ];
        }

        return [
            'pl' => $result === 'win' ? 1 - $premadePlWinPenalty : 1 + $randomPlBonus,
            'mmr' => $result === 'win' ? 1 - $premadeMmrWinPenalty : 1 + $randomMmrBonus,
        ];
    }

    public function buildCorrectedResultRows(ArenaMatch $match, Collection $existingResults, string $winnerTeam): array
    {
        $snapshots = $existingResults->mapWithKeys(function (MatchResult $result) {
            $player = $result->player ?? Player::find($result->player_id);

            return [
                (int) $result->player_id => [
                    'player_id' => (int) $result->player_id,
                    'character_name' => (string) ($player?->character_name ?? ('Player ' . $result->player_id)),
                    'realm' => (string) ($player?->realm ?? ''),
                    'pl_points' => (float) $result->pl_before,
                    'mmr' => (int) $result->mmr_before,
                    'matches_played' => max(0, (int) ($player?->matches_played ?? 1) - 1),
                    'wins' => max(0, (int) ($player?->wins ?? 0) - ($this->countsAsWin($result->result) ? 1 : 0)),
                    'losses' => max(0, (int) ($player?->losses ?? 0) - ($this->countsAsLoss($result->result) ? 1 : 0)),
                ],
            ];
        });

        if ($winnerTeam === 'draw') {
            return $existingResults->mapWithKeys(function (MatchResult $result) use ($snapshots) {
                $snapshot = $snapshots[(int) $result->player_id];

                return [
                    (int) $result->player_id => [
                        'player_id' => (int) $result->player_id,
                        'result' => 'draw',
                        'pl_change' => 0.0,
                        'mmr_change' => 0,
                        'pl_before' => round((float) $snapshot['pl_points'], 1),
                        'pl_after' => round((float) $snapshot['pl_points'], 1),
                        'mmr_before' => (int) $snapshot['mmr'],
                        'mmr_after' => (int) $snapshot['mmr'],
                        'scoring_context' => [
                            'match_category' => 'draw',
                            'resolution_source' => 'admin_force_complete_correction',
                        ],
                    ],
                ];
            })->all();
        }

        $winnerIds = $match->getTeamPlayerIds($winnerTeam);
        $loserIds = $match->getTeamPlayerIds($winnerTeam === 'team_a' ? 'team_b' : 'team_a');
        $scoring = $this->ladderScoringService->calculateMatchResultFromSnapshots(
            collect($winnerIds)->map(fn (int $playerId) => $snapshots[$playerId])->all(),
            collect($loserIds)->map(fn (int $playerId) => $snapshots[$playerId])->all()
        );

        if (isset($scoring['error'])) {
            throw new \RuntimeException($scoring['error']);
        }

        $repeatMultiplier = $this->calculateRepeatMultiplier($match);
        $updatedRows = [];

        foreach ($scoring['players'] as $playerResult) {
            $playerId = (int) $playerResult['player_id'];
            $existingResult = $existingResults->firstWhere('player_id', $playerId);

            if (!$existingResult) {
                continue;
            }

            $dailyMultiplier = $playerResult['pl_change'] > 0
                ? $this->calculateHistoricalDailyGainMultiplier(
                    $playerId,
                    $existingResult->created_at,
                    (int) $existingResult->id
                )
                : 1.0;
            $playerSide = $match->getTeamSideForPlayer($playerId);
            $playerQueueType = $playerSide ? $match->getTeamQueueType($playerSide) : $match->queue_mode;
            $opponentQueueType = $playerSide ? $match->getOpponentQueueTypeForSide($playerSide) : $match->queue_mode;
            $queueTypeMultipliers = $this->calculateQueueTypeMultipliers(
                $playerResult['result'],
                (string) $playerQueueType,
                (string) $opponentQueueType
            );

            $plMultiplier = $repeatMultiplier * $dailyMultiplier * $queueTypeMultipliers['pl'];
            $mmrMultiplier = $repeatMultiplier
                * ($playerResult['mmr_change'] > 0 ? $dailyMultiplier : 1.0)
                * $queueTypeMultipliers['mmr'];

            $finalPlChange = round($playerResult['pl_change'] * $plMultiplier, 1);
            if ($finalPlChange >= 0) {
                $finalPlChange = round(min(LadderScoringService::PL_CAP_WIN, $finalPlChange), 1);
            } else {
                $finalPlChange = round(max(LadderScoringService::PL_CAP_LOSS, min($finalPlChange, LadderScoringService::PL_MIN_LOSS)), 1);
            }

            $finalMmrChange = (int) round($playerResult['mmr_change'] * $mmrMultiplier);
            $finalPlAfter = max(0, round((float) $playerResult['pl_before'] + $finalPlChange, 1));
            $finalMmrAfter = max(100, (int) $playerResult['mmr_before'] + $finalMmrChange);

            // Igual que arriba: se anota el movimiento real, con el suelo de
            // cero ya aplicado, y el teorico se guarda en el contexto.
            $plChangeTeorico = $finalPlChange;
            $mmrChangeTeorico = $finalMmrChange;
            $finalPlChange = round($finalPlAfter - (float) $playerResult['pl_before'], 1);
            $finalMmrChange = $finalMmrAfter - (int) $playerResult['mmr_before'];

            $updatedRows[$playerId] = [
                'player_id' => $playerId,
                'result' => $playerResult['result'],
                'pl_change' => $finalPlChange,
                'mmr_change' => $finalMmrChange,
                'pl_before' => round((float) $playerResult['pl_before'], 1),
                'pl_after' => $finalPlAfter,
                'mmr_before' => (int) $playerResult['mmr_before'],
                'mmr_after' => $finalMmrAfter,
                'scoring_context' => [
                    'match_category' => $scoring['category'],
                    'mmr_diff' => $scoring['mmr_diff'],
                    'pl_diff' => $scoring['pl_diff'],
                    'effective_diff' => $scoring['effective_diff'],
                    'repeat_multiplier' => $repeatMultiplier,
                    'daily_multiplier' => $dailyMultiplier,
                    'queue_type_multiplier_pl' => $queueTypeMultipliers['pl'],
                    'queue_type_multiplier_mmr' => $queueTypeMultipliers['mmr'],
                    'player_queue_type' => $playerQueueType,
                    'opponent_queue_type' => $opponentQueueType,
                    'base_pl_change' => $playerResult['pl_change'],
                    'base_mmr_change' => $playerResult['mmr_change'],
                    'pl_change_theoretical' => $plChangeTeorico,
                    'mmr_change_theoretical' => $mmrChangeTeorico,
                    'resolution_source' => 'admin_force_complete_correction',
                ],
            ];
        }

        return $updatedRows;
    }

    public function countsAsWin(string $result): bool
    {
        return $result === 'win';
    }

    public function countsAsLoss(string $result): bool
    {
        return in_array($result, ['loss', 'no_show'], true);
    }

    public function shiftFutureResultsForPlayer(MatchResult $sourceResult, float $plOffset, int $mmrOffset): void
    {
        if (abs($plOffset) < 0.0001 && $mmrOffset === 0) {
            return;
        }

        $futureResults = MatchResult::query()
            ->where('player_id', $sourceResult->player_id)
            ->where(function ($query) use ($sourceResult) {
                // Sin fecha no se puede comparar por fecha: cualquier
                // comparacion con null en SQL no devuelve nada, y las partidas
                // posteriores se quedaban sin corregir mientras el jugador si
                // perdia los puntos. En ese caso manda el id.
                if ($sourceResult->created_at === null) {
                    $query->where('id', '>', $sourceResult->id);

                    return;
                }

                $query->where('created_at', '>', $sourceResult->created_at)
                    ->orWhere(function ($sameTimestamp) use ($sourceResult) {
                        $sameTimestamp->where('created_at', $sourceResult->created_at)
                            ->where('id', '>', $sourceResult->id);
                    })
                    ->orWhereNull('created_at');
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($futureResults as $futureResult) {
            // Con los mismos suelos que al puntuar. Deshacer una partida entera
            // -no una correccion pequeña- puede empujar el historial por debajo
            // de cero, y ahi quedaba una ficha con PL negativo que no cuadraba
            // con el saldo del jugador, que si se topa en cero.
            $futureResult->update([
                'pl_before' => max(0, round((float) $futureResult->pl_before + $plOffset, 1)),
                'pl_after' => max(0, round((float) $futureResult->pl_after + $plOffset, 1)),
                'mmr_before' => max(100, (int) $futureResult->mmr_before + $mmrOffset),
                'mmr_after' => max(100, (int) $futureResult->mmr_after + $mmrOffset),
            ]);
        }
    }
}
