<?php

namespace App\Services\Matches;

use App\Models\ArenaMatch;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\User;
use App\Services\DiscordBotService;
use App\Services\LadderCacheService;
use App\Services\WebPushService;
use Illuminate\Support\Facades\DB;

/**
 * Lo que hace moderacion sobre un combate: forzar un resultado, corregir o
 * anular uno ya puntuado y resolver abandonos e infracciones.
 *
 * Parte de lo que era ArenaMatchResultService.
 */
class MatchModerationService
{
    public function __construct(
        private readonly DiscordBotService $discordBotService,
        private readonly LadderCacheService $ladderCacheService,
        private readonly MatchLifecycleService $lifecycle,
        private readonly MatchPenaltyService $penalties,
        private readonly MatchPoints $points,
        private readonly MatchReportService $reports,
    ) {
    }

    /**
     * Un amistoso no se puntua nunca, ni siquiera desde moderacion: forzarle un
     * resultado crearia las filas que el ranking lee, y el ladder se movería por
     * una partida que prometio no tocarlo. Lo unico que se le puede hacer es
     * anularlo.
     */
    private function rechazarAmistoso(ArenaMatch $match): void
    {
        if ($match->isFriendly()) {
            throw new \RuntimeException('Un amistoso no mueve el ranking: no se puede puntuar ni sancionar. Solo se puede anular.');
        }
    }

    public function forceComplete(
        ArenaMatch $match,
        string $winnerTeam,
        ?User $admin = null,
        ?string $note = null
    ): array {
        $this->rechazarAmistoso($match);

        if ($match->results()->exists()) {
            return $this->correctProcessedMatch($match, $winnerTeam, $admin, $note);
        }

        if (!in_array($winnerTeam, ['team_a', 'team_b', 'draw'], true)) {
            throw new \RuntimeException('Equipo ganador invalido.');
        }

        $match->loadMissing('report');

        if (!$match->report && $match->status === 'in_progress') {
            $reporterId = $match->getTeamPlayerIds($winnerTeam)[0] ?? null;
            $reporter = $reporterId ? Player::find($reporterId) : null;

            if ($reporter) {
                $this->reports->submitSyntheticReport($match, $reporter, $winnerTeam, 'Synthetic admin completion report');
                $match->refresh()->load('report');
            }
        }

        $originalClaimedWinnerTeam = $match->report?->claimed_winner_team;

        if ($match->report) {

            $match->report->update([
                'status' => 'admin_resolved',
                'claimed_winner_team' => $winnerTeam,
                'claimed_winner_realm' => $winnerTeam === 'draw'
                    ? null
                    : ($winnerTeam === 'team_a' ? $match->team_a_realm : $match->team_b_realm),
                'reviewed_by_user_id' => $admin?->id,
                'reviewed_at' => now(),
                'admin_note' => $note,
                'resolution_payload' => [
                    'resolution_source' => 'admin_force_complete',
                    'winner_team' => $winnerTeam,
                    'original_claimed_winner_team' => $originalClaimedWinnerTeam,
                ],
            ]);
        }

        return DB::transaction(function () use ($match, $winnerTeam, $admin, $note, $originalClaimedWinnerTeam) {
            return $this->lifecycle->finalizeMatch($match->fresh('report'), $winnerTeam, true, [
                'resolution_source' => 'admin_force_complete',
                'admin_id' => $admin?->id,
                'winner_team' => $winnerTeam,
                'original_claimed_winner_team' => $originalClaimedWinnerTeam,
                'note' => $note,
            ]);
        });
    }

    private function correctProcessedMatch(
        ArenaMatch $match,
        string $winnerTeam,
        ?User $admin = null,
        ?string $note = null
    ): array {
        if (!in_array($winnerTeam, ['team_a', 'team_b', 'draw'], true)) {
            throw new \RuntimeException('Equipo ganador invalido.');
        }

        $match->loadMissing(['results.player', 'report']);
        $existingResults = $match->results
            ->sortBy(fn (MatchResult $result) => ($result->created_at?->timestamp ?? 0) . '-' . $result->id)
            ->values();

        if ($existingResults->isEmpty()) {
            throw new \RuntimeException('Este match no tiene resultados persistidos para corregir.');
        }

        $originalClaimedWinnerTeam = $match->report?->claimed_winner_team ?? $match->winner_team;
        $updatedRows = $this->points->buildCorrectedResultRows($match, $existingResults, $winnerTeam);

        DB::transaction(function () use ($match, $existingResults, $updatedRows, $winnerTeam, $admin, $note, $originalClaimedWinnerTeam) {
            foreach ($existingResults as $existingResult) {
                $updatedRow = $updatedRows[(int) $existingResult->player_id] ?? null;

                if (!$updatedRow) {
                    continue;
                }

                $plOffset = round((float) $updatedRow['pl_change'] - (float) $existingResult->pl_change, 1);
                $mmrOffset = (int) $updatedRow['mmr_change'] - (int) $existingResult->mmr_change;
                $oldWinCount = $this->points->countsAsWin($existingResult->result) ? 1 : 0;
                $newWinCount = $this->points->countsAsWin($updatedRow['result']) ? 1 : 0;
                $oldLossCount = $this->points->countsAsLoss($existingResult->result) ? 1 : 0;
                $newLossCount = $this->points->countsAsLoss($updatedRow['result']) ? 1 : 0;

                $existingResult->update([
                    'result' => $updatedRow['result'],
                    'pl_change' => $updatedRow['pl_change'],
                    'mmr_change' => $updatedRow['mmr_change'],
                    'pl_before' => $updatedRow['pl_before'],
                    'pl_after' => $updatedRow['pl_after'],
                    'mmr_before' => $updatedRow['mmr_before'],
                    'mmr_after' => $updatedRow['mmr_after'],
                    'reported_by_admin' => true,
                    'scoring_context' => $updatedRow['scoring_context'],
                ]);

                $this->points->shiftFutureResultsForPlayer($existingResult, $plOffset, $mmrOffset);

                $player = Player::findOrFail((int) $existingResult->player_id);
                $player->update([
                    'pl_points' => max(0, round((float) $player->pl_points + $plOffset, 1)),
                    'mmr' => max(100, (int) $player->mmr + $mmrOffset),
                    'wins' => max(0, (int) $player->wins + ($newWinCount - $oldWinCount)),
                    'losses' => max(0, (int) $player->losses + ($newLossCount - $oldLossCount)),
                ]);
            }

            $winnerRealm = match ($winnerTeam) {
                'team_a' => $match->team_a_realm,
                'team_b' => $match->team_b_realm,
                default => null,
            };

            $match->update([
                'status' => 'completed',
                'winner_team' => $winnerTeam === 'draw' ? null : $winnerTeam,
                'winner_realm' => $winnerRealm,
                'completed_at' => $match->completed_at ?? now(),
                'expires_at' => null,
                'notes' => MatchNotes::append(
                    $match->notes,
                    'Admin corrected resolved match from '
                    . ($originalClaimedWinnerTeam ?? 'unknown')
                    . ' to ' . $winnerTeam
                    . ($note ? ': ' . $note : '')
                ),
            ]);

            if ($match->report) {
                $match->report->update([
                    'status' => 'admin_resolved',
                    'claimed_winner_team' => $winnerTeam,
                    'claimed_winner_realm' => $winnerRealm,
                    'reviewed_by_user_id' => $admin?->id,
                    'reviewed_at' => now(),
                    'admin_note' => $note,
                    'resolution_payload' => [
                        'resolution_source' => 'admin_force_complete_correction',
                        'winner_team' => $winnerTeam,
                        'original_claimed_winner_team' => $originalClaimedWinnerTeam,
                    ],
                ]);
            }
        });

        $this->ladderCacheService->forgetSummary();
        $this->ladderCacheService->forgetRecentMatches();

        $payload = [
            'match_id' => $match->id,
            'winner_team' => $winnerTeam,
            'winner_realm' => $winnerTeam === 'draw'
                ? null
                : ($winnerTeam === 'team_a' ? $match->team_a_realm : $match->team_b_realm),
            'resolution_source' => 'admin_force_complete_correction',
            'results' => array_values($updatedRows),
        ];

        app(WebPushService::class)->avisarAJugadores($match->getAllPlayers());

        $this->discordBotService->notifyReportResolved($match->fresh(['report', 'results']), $payload);

        return $payload;
    }

    /**
     * Anula un enfrentamiento que ya repartio puntos.
     *
     * Devuelve a cada jugador lo que esta partida le dio o le quito, corrige
     * el rastro de las partidas que jugo despues -si no, su historial contaria
     * un recorrido que ya no existe- y borra las filas de resultado, porque la
     * partida pasa a no haber ocurrido.
     */
    private function voidProcessedMatch(ArenaMatch $match, ?User $admin = null, ?string $note = null): void
    {
        $match->loadMissing(['results', 'report']);

        $resultados = $match->results
            ->sortBy(fn (MatchResult $fila) => ($fila->created_at?->timestamp ?? 0) . '-' . $fila->id)
            ->values();

        DB::transaction(function () use ($match, $resultados, $admin, $note) {
            foreach ($resultados as $fila) {
                $plOffset = round(-1 * (float) $fila->pl_change, 1);
                $mmrOffset = -1 * (int) $fila->mmr_change;

                // Primero se corrigen las partidas posteriores, que aun apuntan
                // a esta fila para saber donde empiezan. Despues se borra.
                $this->points->shiftFutureResultsForPlayer($fila, $plOffset, $mmrOffset);

                $player = Player::find((int) $fila->player_id);

                if ($player) {
                    $player->update([
                        'pl_points' => max(0, round((float) $player->pl_points + $plOffset, 1)),
                        'mmr' => max(100, (int) $player->mmr + $mmrOffset),
                        'wins' => max(0, (int) $player->wins - ($this->points->countsAsWin($fila->result) ? 1 : 0)),
                        'losses' => max(0, (int) $player->losses - ($this->points->countsAsLoss($fila->result) ? 1 : 0)),
                        'matches_played' => max(0, (int) $player->matches_played - 1),
                    ]);
                }

                $fila->delete();
            }

            if ($match->report) {
                $match->report->update([
                    'status' => 'voided',
                    'reviewed_by_user_id' => $admin?->id,
                    'reviewed_at' => now(),
                    'admin_note' => $note,
                    'resolution_payload' => [
                        'resolution_source' => 'admin_void_scored',
                    ],
                ]);
            }

            $match->update([
                'status' => 'void',
                'winner_team' => null,
                'winner_realm' => null,
                'completed_at' => now(),
                'expires_at' => null,
                'notes' => MatchNotes::append(
                    $match->notes,
                    'Scored match voided, points reverted' . ($note ? ': ' . $note : '')
                ),
            ]);

            $this->lifecycle->closeMatchQueues($match);
        });

        $this->ladderCacheService->forgetRecentMatches();
        $this->ladderCacheService->forgetSummary();
    }

    public function applyAbandonmentWalkover(
        ArenaMatch $match,
        int $offendingPlayerId,
        ?User $admin = null,
        ?string $note = null
    ): array {
        $this->rechazarAmistoso($match);

        $offendingSide = $match->getTeamSideForPlayer($offendingPlayerId);
        if ($offendingSide === null) {
            throw new \RuntimeException('El jugador sancionado no pertenece al match.');
        }

        $winnerTeam = $offendingSide === 'team_a' ? 'team_b' : 'team_a';
        $offender = Player::findOrFail($offendingPlayerId);

        // Idempotencia: forceComplete si lo es (deriva a correctProcessedMatch),
        // pero la penalizacion no. Sin esto, reenviar el formulario (doble clic,
        // reintento tras timeout, dos admins a la vez) sumaba un segundo strike,
        // restaba trust otra vez y ENCADENABA el bloqueo sobre si mismo. Si el
        // match ya esta resuelto, solo se re-deriva el resultado.
        $alreadyResolved = $match->results()->exists();

        if (!$alreadyResolved) {
            $this->penalties->applyAbandonmentPenalty($offender, $match, $admin, $note ?? 'Abandonment walkover');
        }

        return $this->forceComplete(
            $match,
            $winnerTeam,
            $admin,
            trim('Abandonment walkover' . ($note ? ' - ' . $note : ''))
        );
    }

    public function applySupportInfraction(
        ArenaMatch $match,
        int $offendingPlayerId,
        ?User $admin = null,
        ?string $note = null
    ): array {
        $this->rechazarAmistoso($match);

        $offendingSide = $match->getTeamSideForPlayer($offendingPlayerId);
        if ($offendingSide === null) {
            throw new \RuntimeException('El jugador infractor no pertenece al match.');
        }

        $winnerTeam = $offendingSide === 'team_a' ? 'team_b' : 'team_a';
        $offender = Player::findOrFail($offendingPlayerId);

        // Idempotencia: forceComplete si lo es (deriva a correctProcessedMatch),
        // pero la penalizacion no. Sin esto, reenviar el formulario (doble clic,
        // reintento tras timeout, dos admins a la vez) sumaba un segundo strike,
        // restaba trust otra vez y ENCADENABA el bloqueo sobre si mismo. Si el
        // match ya esta resuelto, solo se re-deriva el resultado.
        $alreadyResolved = $match->results()->exists();

        if (!$alreadyResolved) {
            $this->penalties->applyPenalty($offender, 'support_infraction', $match, $admin, $note ?? 'Support role infraction');
        }

        return $this->forceComplete(
            $match,
            $winnerTeam,
            $admin,
            trim('Support role infraction' . ($note ? ' - ' . $note : ''))
        );
    }
}
