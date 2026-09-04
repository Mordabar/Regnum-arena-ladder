<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\DiscordBotService;

class ArenaMatchResultService
{
    private const REPEAT_WINDOW_HOURS = 24;
    private const ABANDON_LOCK_HOURS = 12;
    private const SUPPORT_INFRACTION_LOCK_HOURS = 24;
    private const ABANDON_TRUST_PENALTY = 15;
    private const SUPPORT_INFRACTION_TRUST_PENALTY = 25;
    private const MAX_PENALTY_LOCK_HOURS = 96;
    private const MAX_STRIKE_MULTIPLIER = 4;
    // Premades carry a coordination advantage, so mixed matches give random teams a
    // stronger compensation in ladder value while keeping same-type mirrors neutral.
    private const RANDOM_VS_PREMADE_PL_BONUS_PCT = 25;
    private const RANDOM_VS_PREMADE_MMR_BONUS_PCT = 18;
    private const PREMADE_VS_RANDOM_PL_WIN_PENALTY_PCT = 20;
    private const PREMADE_VS_RANDOM_MMR_WIN_PENALTY_PCT = 14;

    public function __construct(
        private readonly LadderScoringService $ladderScoringService,
        private readonly DiscordBotService $discordBotService,
        private readonly LadderCacheService $ladderCacheService,
    ) {
    }

    public function submitReport(ArenaMatch $match, Player $reporter, array $payload): MatchReport
    {
        if ($match->status !== 'in_progress') {
            throw new \RuntimeException('Solo puedes reportar matches en progreso.');
        }

        if ($match->results()->exists()) {
            throw new \RuntimeException('Este match ya fue procesado.');
        }

        $reportingTeam = $match->getTeamSideForPlayer($reporter->id, (string) $reporter->user?->discord_id);
        if ($reportingTeam === null) {
            throw new \RuntimeException('El jugador no pertenece a este match.');
        }

        $existingReport = $match->report;
        if ($existingReport && in_array($existingReport->status, ['pending_confirmation', 'confirmed', 'admin_resolved'], true)) {
            throw new \RuntimeException('Este match ya tiene un reporte activo.');
        }

        $claimedWinnerTeam = $payload['claimed_winner_team'];
        if (!in_array($claimedWinnerTeam, ['team_a', 'team_b', 'draw'], true)) {
            throw new \RuntimeException('Equipo ganador invalido.');
        }

        $evidenceFiles = collect($payload['evidence_files'] ?? [])
            ->filter(fn ($file) => $file instanceof UploadedFile)
            ->values();

        if ($evidenceFiles->isEmpty()) {
            throw new \RuntimeException('Debes adjuntar al menos una captura final del combate.');
        }

        $storedPaths = [];

        try {
            foreach ($evidenceFiles as $index => $file) {
                $storedPaths[] = $this->storeScreenshot($match, $file, 'evidence-' . ($index + 1));
            }

            $primaryEvidencePath = $storedPaths[0] ?? null;

            if (!$primaryEvidencePath) {
                throw new \RuntimeException('No se pudo almacenar ninguna evidencia del reporte.');
            }

            $report = DB::transaction(function () use (
                $match,
                $reporter,
                $reportingTeam,
                $claimedWinnerTeam,
                $payload,
                $storedPaths,
                $primaryEvidencePath
            ) {
                $report = MatchReport::updateOrCreate(
                    ['match_id' => $match->id],
                    [
                        'reported_by_player_id' => $reporter->id,
                        'reporting_team' => $reportingTeam,
                        'claimed_winner_team' => $claimedWinnerTeam,
                        'claimed_winner_realm' => $claimedWinnerTeam === 'draw' ? null : ($claimedWinnerTeam === 'team_a' ? $match->team_a_realm : $match->team_b_realm),
                        'status' => 'pending_confirmation',
                        // Keep legacy columns populated with the primary screenshot so old data readers stay safe.
                        'encounter_screenshot_path' => $primaryEvidencePath,
                        'final_screenshot_path' => $primaryEvidencePath,
                        'evidence_paths' => $storedPaths,
                        'reporter_note' => $payload['reporter_note'] ?? null,
                        'confirmed_by_player_id' => null,
                        'confirmed_at' => null,
                        'rejected_by_player_id' => null,
                        'rejected_at' => null,
                        'rejection_note' => null,
                        'reviewed_by_user_id' => null,
                        'reviewed_at' => null,
                        'admin_note' => null,
                        'resolution_payload' => null,
                    ]
                );

                $match->update([
                    'reported_at' => now(),
                    'expires_at' => now()->addMinutes($this->reportConfirmationWindowMinutes()),
                    'notes' => $this->appendNote($match->notes, 'Result report submitted by ' . $reporter->character_name),
                ]);

                return $report;
            });
        } catch (\Throwable $e) {
            $this->deleteEvidencePaths($storedPaths);

            throw $e;
        }

        $this->discordBotService->notifyReportSubmitted($match->fresh('report'), $report);

        return $report;
    }

    public function submitSyntheticReport(
        ArenaMatch $match,
        Player $reporter,
        string $claimedWinnerTeam,
        ?string $note = null
    ): MatchReport {
        $primaryEvidencePath = $this->storeSyntheticScreenshot($match, 'evidence-1');

        return $this->submitSyntheticReportRecord(
            $match,
            $reporter,
            $claimedWinnerTeam,
            $note,
            [$primaryEvidencePath]
        );
    }

    public function confirmReport(MatchReport $report, Player $confirmer): array
    {
        $match = $report->match()->firstOrFail();

        if ($this->hasPendingReportExpired($match, $report)) {
            DB::transaction(function () use ($match) {
                $this->expirePendingReport($match, 'Report confirmation window expired before confirmation');
            });

            $freshMatch = $match->fresh('report');
            if ($freshMatch?->report) {
                $this->discordBotService->notifyMatchDisputed($freshMatch, $freshMatch->report);
            }

            throw new \RuntimeException('El tiempo para confirmar este reporte expiro. El match paso a disputa.');
        }

        if ($report->status !== 'pending_confirmation') {
            throw new \RuntimeException('Este reporte ya no esta esperando confirmacion.');
        }

        $confirmerTeam = $match->getTeamSideForPlayer($confirmer->id, (string) $confirmer->user?->discord_id);
        if ($confirmerTeam === null || $confirmerTeam === $report->reporting_team) {
            throw new \RuntimeException('Solo el equipo rival puede confirmar este reporte.');
        }

        return DB::transaction(function () use ($report, $match, $confirmer) {
            $report->update([
                'status' => 'confirmed',
                'confirmed_by_player_id' => $confirmer->id,
                'confirmed_at' => now(),
            ]);

            return $this->finalizeMatch($match->fresh('report'), $report->claimed_winner_team, false, [
                'resolution_source' => 'rival_confirmation',
                'confirmed_by_player_id' => $confirmer->id,
            ]);
        });
    }

    /**
     * Da por confirmado un reporte en nombre del rival.
     *
     * Existe para dos cosas que antes no se podian hacer: ensayar el flujo
     * completo sin necesitar la sesion del otro jugador, y desatascar un
     * reporte cuyo rival no va a contestar nunca. Puntua exactamente igual que
     * una confirmacion normal, y queda anotado como lo que es.
     */
    public function confirmReportForRival(MatchReport $report, ?string $note = null): array
    {
        $match = $report->match()->firstOrFail();

        if ($report->status !== 'pending_confirmation') {
            throw new \RuntimeException('Este reporte ya no esta esperando confirmacion.');
        }

        return DB::transaction(function () use ($report, $match, $note) {
            $report->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'admin_note' => $note,
                'resolution_payload' => [
                    'resolution_source' => 'admin_confirmed_for_rival',
                ],
            ]);

            return $this->finalizeMatch($match->fresh('report'), $report->claimed_winner_team, true, [
                'resolution_source' => 'admin_confirmed_for_rival',
            ]);
        });
    }

    public function rejectReport(MatchReport $report, Player $rejector, ?string $note = null): MatchReport
    {
        $match = $report->match()->firstOrFail();

        if ($this->hasPendingReportExpired($match, $report)) {
            DB::transaction(function () use ($match) {
                $this->expirePendingReport($match, 'Report confirmation window expired before rejection');
            });

            $freshMatch = $match->fresh('report');
            if ($freshMatch?->report) {
                $this->discordBotService->notifyMatchDisputed($freshMatch, $freshMatch->report);
            }

            throw new \RuntimeException('El tiempo para responder este reporte expiro. El match paso a disputa.');
        }

        if ($report->status !== 'pending_confirmation') {
            throw new \RuntimeException('Este reporte ya no esta esperando confirmacion.');
        }

        $rejectorTeam = $match->getTeamSideForPlayer($rejector->id, (string) $rejector->user?->discord_id);
        if ($rejectorTeam === null || $rejectorTeam === $report->reporting_team) {
            throw new \RuntimeException('Solo el equipo rival puede rechazar este reporte.');
        }

        DB::transaction(function () use ($report, $rejector, $note, $match) {
            $report->update([
                'status' => 'rejected',
                'rejected_by_player_id' => $rejector->id,
                'rejected_at' => now(),
                'rejection_note' => $note,
            ]);

            $match->update([
                'status' => 'disputed',
                'expires_at' => null,
                'notes' => $this->appendNote($match->notes, 'Report rejected by rival: ' . $rejector->character_name),
            ]);

            $this->closeMatchQueues($match);
        });

        $this->ladderCacheService->forgetRecentMatches();
        $this->discordBotService->notifyMatchDisputed($match->fresh('report'), $report);

        return $report->fresh();
    }

    public function forceComplete(
        ArenaMatch $match,
        string $winnerTeam,
        ?User $admin = null,
        ?string $note = null
    ): array {
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
                $this->submitSyntheticReport($match, $reporter, $winnerTeam, 'Synthetic admin completion report');
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
            return $this->finalizeMatch($match->fresh('report'), $winnerTeam, true, [
                'resolution_source' => 'admin_force_complete',
                'admin_id' => $admin?->id,
                'winner_team' => $winnerTeam,
                'original_claimed_winner_team' => $originalClaimedWinnerTeam,
                'note' => $note,
            ]);
        });
    }

    public function markVoid(ArenaMatch $match, ?User $admin = null, ?string $note = null): void
    {
        if ($match->results()->exists()) {
            throw new \RuntimeException('No puedes anular un match ya puntuado.');
        }

        DB::transaction(function () use ($match, $admin, $note) {
            if ($match->report) {
                $match->report->update([
                    'status' => 'voided',
                    'reviewed_by_user_id' => $admin?->id,
                    'reviewed_at' => now(),
                    'admin_note' => $note,
                    'resolution_payload' => [
                        'resolution_source' => 'admin_void',
                    ],
                ]);
            }

            $match->update([
                'status' => 'void',
                'completed_at' => now(),
                'expires_at' => null,
                'notes' => $this->appendNote($match->notes, 'Match voided' . ($note ? ': ' . $note : '')),
            ]);

            $this->closeMatchQueues($match);
        });

        $this->ladderCacheService->forgetRecentMatches();
    }

    public function markDisputed(ArenaMatch $match, ?User $admin = null, ?string $note = null): void
    {
        // Mismo criterio que markVoid: un match ya puntuado no puede volver a
        // disputa. Si lo hiciera, desapareceria de los listados de completados
        // pero sus puntos seguirian contando en el ladder, sin forma de
        // revertirlos. Para corregir un resultado ya dado esta forceComplete.
        if ($match->results()->exists()) {
            throw new \RuntimeException('No puedes mandar a disputa un match ya puntuado. Corrige el resultado en su lugar.');
        }

        DB::transaction(function () use ($match, $admin, $note) {
            if ($match->report) {
                $match->report->update([
                    'status' => 'disputed',
                    'reviewed_by_user_id' => $admin?->id,
                    'reviewed_at' => now(),
                    'admin_note' => $note,
                ]);
            }

            $match->update([
                'status' => 'disputed',
                'expires_at' => null,
                'notes' => $this->appendNote($match->notes, 'Marked as disputed' . ($note ? ': ' . $note : '')),
            ]);

            $this->closeMatchQueues($match);
        });

        $this->ladderCacheService->forgetRecentMatches();
    }

    public function applyAbandonmentPenalty(
        Player $player,
        ?ArenaMatch $match = null,
        ?User $admin = null,
        ?string $note = null
    ): void {
        $this->applyPenalty($player, 'abandonment', $match, $admin, $note);
    }

    public function applyManualQueueLock(Player $player, int $hours = 12, ?string $note = null): void
    {
        $lockAnchor = $player->queue_locked_until && $player->queue_locked_until->isFuture()
            ? $player->queue_locked_until->copy()
            : now();

        $player->update([
            'queue_locked_until' => $lockAnchor->copy()->addHours(max(1, $hours)),
            'queue_lock_reason' => 'manual_lock',
            'last_penalty_type' => 'manual_lock',
            'last_penalty_at' => now(),
        ]);
    }

    public function clearQueueLock(Player $player): void
    {
        $player->update([
            'queue_locked_until' => null,
            'queue_lock_reason' => null,
        ]);
    }

    public function applyAbandonmentWalkover(
        ArenaMatch $match,
        int $offendingPlayerId,
        ?User $admin = null,
        ?string $note = null
    ): array {
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
            $this->applyAbandonmentPenalty($offender, $match, $admin, $note ?? 'Abandonment walkover');
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
            $this->applyPenalty($offender, 'support_infraction', $match, $admin, $note ?? 'Support role infraction');
        }

        return $this->forceComplete(
            $match,
            $winnerTeam,
            $admin,
            trim('Support role infraction' . ($note ? ' - ' . $note : ''))
        );
    }

    public function sweepPostMatchState(): array
    {
        return [
            'expired_hunts' => $this->expireInProgressMatchesWithoutReport(),
            'expired_report_confirmations' => $this->expirePendingReportConfirmations(),
            'expired_disputes' => $this->expireStaleDisputes(),
        ];
    }

    /** Horas que una disputa espera a moderacion antes de anularse sola. */
    public function disputeAutoVoidHours(): int
    {
        return max(1, (int) AppSetting::getValue('dispute_auto_void_hours', 48));
    }

    /**
     * Anula las disputas que moderacion no ha mirado a tiempo.
     *
     * Una disputa es lo unico que quedaba sin plazo: esperaba a un
     * administrador para siempre, y un ladder de una persona no puede
     * apoyarse en que esa persona entre. Al vencer el plazo el
     * enfrentamiento se anula, que es lo unico honesto cuando las dos
     * versiones se contradicen o cuando nadie reporto: nadie gana ni pierde
     * puntos, y el historial guarda por que se cerro.
     */
    private function expireStaleDisputes(): int
    {
        $deadline = now()->subHours($this->disputeAutoVoidHours());

        $stale = ArenaMatch::query()
            ->with('report')
            ->where('status', 'disputed')
            ->where('updated_at', '<=', $deadline)
            // Un match ya puntuado no se anula: markVoid lo rechazaria y ademas
            // habria que devolver puntos que ya movieron el ladder.
            ->whereDoesntHave('results')
            ->get();

        foreach ($stale as $match) {
            try {
                $this->markVoid(
                    $match,
                    null,
                    'Anulado solo: la disputa cumplio ' . $this->disputeAutoVoidHours() . ' horas sin resolverse'
                );
            } catch (\Throwable $exception) {
                Log::warning('No se pudo anular una disputa vencida.', [
                    'match_id' => $match->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $stale->count();
    }

    /**
     * Checks if all players have accepted the match and, if so, transitions it to in_progress.
     * This consolidates the duplicate logic from ArenaMatchController::checkAllPlayersAccepted()
     * and QueueHubController::promoteMatchIfFullyAccepted() into one authoritative location.
     *
     * @return bool Whether the match was promoted to in_progress
     */
    public function promoteMatchToInProgressIfReady(ArenaMatch $match): bool
    {
        $acceptedCount = Queue::query()
            ->where('match_id', (string) $match->id)
            ->where('status', 'accepted')
            ->count();

        if ($acceptedCount !== $match->player_count) {
            return false;
        }

        $match->update([
            'status'      => 'in_progress',
            'accepted_at' => now(),
            'started_at'  => now(),
            'expires_at'  => now()->addMinutes((int) AppSetting::getValue('hunt_window_minutes', 30)),
        ]);

        app(DiscordBotService::class)->notifyMatchAccepted($match->fresh());

        return true;
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
        $updatedRows = $this->buildCorrectedResultRows($match, $existingResults, $winnerTeam);

        DB::transaction(function () use ($match, $existingResults, $updatedRows, $winnerTeam, $admin, $note, $originalClaimedWinnerTeam) {
            foreach ($existingResults as $existingResult) {
                $updatedRow = $updatedRows[(int) $existingResult->player_id] ?? null;

                if (!$updatedRow) {
                    continue;
                }

                $plOffset = round((float) $updatedRow['pl_change'] - (float) $existingResult->pl_change, 1);
                $mmrOffset = (int) $updatedRow['mmr_change'] - (int) $existingResult->mmr_change;
                $oldWinCount = $this->countsAsWin($existingResult->result) ? 1 : 0;
                $newWinCount = $this->countsAsWin($updatedRow['result']) ? 1 : 0;
                $oldLossCount = $this->countsAsLoss($existingResult->result) ? 1 : 0;
                $newLossCount = $this->countsAsLoss($updatedRow['result']) ? 1 : 0;

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

                $this->shiftFutureResultsForPlayer($existingResult, $plOffset, $mmrOffset);

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
                'notes' => $this->appendNote(
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

        $this->discordBotService->notifyReportResolved($match->fresh(['report', 'results']), $payload);

        return $payload;
    }

    private function finalizeMatch(
        ArenaMatch $match,
        string $winnerTeam,
        bool $reportedByAdmin,
        array $resolutionContext
    ): array {
        if ($match->results()->exists()) {
            throw new \RuntimeException('Este match ya fue procesado.');
        }

        // Un match cancelado o anulado no puede otorgar puntos: si quedo un
        // reporte pendiente al cancelarse, confirmarlo despues sumaria PL de
        // una partida que oficialmente no existe.
        if (in_array($match->status, ['cancelled', 'void'], true)) {
            throw new \RuntimeException('Este match fue ' . ($match->status === 'void' ? 'anulado' : 'cancelado') . ' y ya no puede puntuarse.');
        }

        $resultRows = [];
        $scoring = [];
        $winnerRealm = null;

        if ($winnerTeam === 'draw') {
            $allPlayers = array_merge($match->getTeamPlayerIds('team_a'), $match->getTeamPlayerIds('team_b'));
            foreach ($allPlayers as $playerId) {
                $player = Player::findOrFail($playerId);
                
                $player->update([
                    'matches_played' => $player->matches_played + 1,
                ]);

                $context = ['match_category' => 'draw'];

                MatchResult::updateOrCreate(
                    [
                        'match_id' => $match->id,
                        'player_id' => $player->id,
                    ],
                    [
                        'result' => 'draw',
                        'pl_change' => 0,
                        'mmr_change' => 0,
                        'pl_before' => $player->pl_points,
                        'pl_after' => $player->pl_points,
                        'mmr_before' => $player->mmr,
                        'mmr_after' => $player->mmr,
                        'reported_by_admin' => $reportedByAdmin,
                        'scoring_context' => $context,
                        'created_at' => now(),
                    ]
                );

                $resultRows[] = [
                    'player_id' => $player->id,
                    'result' => 'draw',
                    'pl_change' => 0,
                    'mmr_change' => 0,
                    'pl_before' => $player->pl_points,
                    'pl_after' => $player->pl_points,
                    'mmr_before' => $player->mmr,
                    'mmr_after' => $player->mmr,
                    'scoring_context' => $context,
                ];
            }
        } else {
            $loserTeam = $winnerTeam === 'team_a' ? 'team_b' : 'team_a';
            $winnerIds = $match->getTeamPlayerIds($winnerTeam);
            $loserIds = $match->getTeamPlayerIds($loserTeam);

            $scoring = $this->ladderScoringService->calculateMatchResult($winnerIds, $loserIds, false);

            if (isset($scoring['error'])) {
                throw new \RuntimeException($scoring['error']);
            }

            $repeatMultiplier = $this->calculateRepeatMultiplier($match);

            foreach ($scoring['players'] as $playerResult) {
                $player = Player::findOrFail($playerResult['player_id']);
                $dailyMultiplier = $playerResult['pl_change'] > 0
                    ? $this->calculateDailyGainMultiplier($player->id)
                    : 1.0;
                $playerSide = $match->getTeamSideForPlayer($player->id);
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
                $finalPlAfter = max(0, round($playerResult['pl_before'] + $finalPlChange, 1));
                $finalMmrAfter = max(100, $playerResult['mmr_before'] + $finalMmrChange);

                $player->update([
                    'pl_points' => $finalPlAfter,
                    'mmr' => $finalMmrAfter,
                    'matches_played' => $player->matches_played + 1,
                    'wins' => $playerResult['result'] === 'win' ? $player->wins + 1 : $player->wins,
                    'losses' => $playerResult['result'] === 'loss' ? $player->losses + 1 : $player->losses,
                ]);

                $context = [
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
                ];

                MatchResult::updateOrCreate(
                    [
                        'match_id' => $match->id,
                        'player_id' => $player->id,
                    ],
                    [
                        'result' => $playerResult['result'],
                        'pl_change' => $finalPlChange,
                        'mmr_change' => $finalMmrChange,
                        'pl_before' => $playerResult['pl_before'],
                        'pl_after' => $finalPlAfter,
                        'mmr_before' => $playerResult['mmr_before'],
                        'mmr_after' => $finalMmrAfter,
                        'reported_by_admin' => $reportedByAdmin,
                        'scoring_context' => $context,
                        'created_at' => now(),
                    ]
                );

                $resultRows[] = [
                    'player_id' => $player->id,
                    'result' => $playerResult['result'],
                    'pl_change' => $finalPlChange,
                    'mmr_change' => $finalMmrChange,
                    'pl_before' => $playerResult['pl_before'],
                    'pl_after' => $finalPlAfter,
                    'mmr_before' => $playerResult['mmr_before'],
                    'mmr_after' => $finalMmrAfter,
                    'scoring_context' => $context,
                ];
            }
            $winnerRealm = $winnerTeam === 'team_a' ? $match->team_a_realm : $match->team_b_realm;
        }



        $match->update([
            'status' => 'completed',
            'winner_team' => $winnerTeam === 'draw' ? null : $winnerTeam,
            'winner_realm' => $winnerRealm,
            'completed_at' => now(),
            'expires_at' => null,
            'notes' => $this->appendNote(
                $match->notes,
                'Match resolved via ' . ($resolutionContext['resolution_source'] ?? 'report_confirmation')
            ),
        ]);

        $this->closeMatchQueues($match);

        if ($match->report) {
            $match->report->update([
                'status' => $reportedByAdmin ? 'admin_resolved' : 'confirmed',
                'reviewed_by_user_id' => $resolutionContext['admin_id'] ?? $match->report->reviewed_by_user_id,
                'reviewed_at' => ($reportedByAdmin || isset($resolutionContext['admin_id'])) ? now() : $match->report->reviewed_at,
                'resolution_payload' => $resolutionContext,
            ]);
        }

        $this->ladderCacheService->forgetSummary();

        $payload = [
            'match_id' => $match->id,
            'winner_team' => $winnerTeam,
            'winner_realm' => $winnerRealm,
            'scoring' => $scoring,
            'results' => $resultRows,
        ];

        $this->discordBotService->notifyReportResolved($match->fresh(['report', 'results']), $payload);

        return $payload;
    }

    private function buildCorrectedResultRows(ArenaMatch $match, Collection $existingResults, string $winnerTeam): array
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
                    'resolution_source' => 'admin_force_complete_correction',
                ],
            ];
        }

        return $updatedRows;
    }

    /**
     * Cierra los enfrentamientos que se quedaron sin reporte.
     *
     * Nadie reporto dentro de la ventana: no hay capturas, no hay version de
     * nadie y no hay nada que juzgar. Antes esto abria una disputa, o sea una
     * cola que solo un administrador podia vaciar, para un caso en el que ni
     * siquiera hay algo que decidir. Se anula: la partida queda en cero y nadie
     * gana ni pierde puntos.
     */
    private function expireInProgressMatchesWithoutReport(): int
    {
        $expiredMatches = ArenaMatch::query()
            ->where('status', 'in_progress')
            ->whereNull('reported_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredMatches as $match) {
            try {
                $this->markVoid($match, null, 'Nadie reporto dentro del plazo para pelear');
            } catch (\Throwable $exception) {
                Log::warning('No se pudo anular un enfrentamiento sin reporte.', [
                    'match_id' => $match->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $expiredMatches->count();
    }

    /**
     * Cierra los reportes que el rival dejo sin contestar.
     *
     * El silencio no es una disputa. Quien reporta sube capturas y el rival
     * tiene una ventana para rechazarlas; si deja pasar el plazo sin decir
     * nada, el reporte se da por bueno y la partida se puntua. Antes esto
     * mandaba el enfrentamiento a disputa, o sea a una cola que solo un
     * administrador podia vaciar: bastaba con que un rival no volviera a
     * entrar para que el match se quedara colgado para siempre.
     *
     * Sigue siendo reversible: moderacion puede corregir el resultado despues,
     * y eso reajusta los puntos de las partidas posteriores.
     */
    private function expirePendingReportConfirmations(): int
    {
        $expiredMatches = ArenaMatch::query()
            ->with('report')
            ->where('status', 'in_progress')
            ->whereNotNull('reported_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get()
            ->filter(fn (ArenaMatch $match) => $match->report?->status === 'pending_confirmation')
            ->values();

        $closed = 0;

        foreach ($expiredMatches as $match) {
            try {
                DB::transaction(function () use ($match) {
                    $report = $match->report;

                    $report->update([
                        'status' => 'confirmed',
                        'confirmed_at' => now(),
                        'resolution_payload' => [
                            'resolution_source' => 'confirmation_window_elapsed',
                        ],
                    ]);

                    $this->finalizeMatch($match->fresh('report'), $report->claimed_winner_team, false, [
                        'resolution_source' => 'confirmation_window_elapsed',
                    ]);
                });

                $closed++;
            } catch (\Throwable $exception) {
                // Si por lo que sea no se puede puntuar (el match ya se cerro
                // por otro camino), no se deja a medias: pasa a disputa, que es
                // visible en el panel y tiene su propio plazo de cierre.
                Log::warning('No se pudo cerrar un reporte vencido; pasa a disputa.', [
                    'match_id' => $match->id,
                    'message' => $exception->getMessage(),
                ]);

                DB::transaction(function () use ($match) {
                    $this->expirePendingReport($match, 'Report confirmation window expired');
                });
            }
        }

        return $closed;
    }

    private function calculateDailyGainMultiplier(int $playerId): float
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

    private function calculateRepeatMultiplier(ArenaMatch $match): float
    {
        $currentTeamA = $this->teamSignature($match->getTeamPlayerIds('team_a'));
        $currentTeamB = $this->teamSignature($match->getTeamPlayerIds('team_b'));
        $currentTeamAIds = $match->getTeamPlayerIds('team_a');
        $currentTeamBIds = $match->getTeamPlayerIds('team_b');

        $recentMatches = ArenaMatch::query()
            ->where('id', '!=', $match->id)
            ->where('status', 'completed')
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

    private function submitSyntheticReportRecord(
        ArenaMatch $match,
        Player $reporter,
        string $claimedWinnerTeam,
        ?string $note,
        array $evidencePaths
    ): MatchReport {
        if ($match->status !== 'in_progress') {
            throw new \RuntimeException('Solo puedes crear un reporte sintetico en matches en progreso.');
        }

        $reportingTeam = $match->getTeamSideForPlayer($reporter->id, (string) $reporter->user?->discord_id);
        if ($reportingTeam === null) {
            throw new \RuntimeException('El jugador no pertenece a este match.');
        }

        $primaryEvidencePath = collect($evidencePaths)
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->first();

        if (!$primaryEvidencePath) {
            throw new \RuntimeException('No se pudo generar evidencia sintetica para el reporte.');
        }

        $report = MatchReport::updateOrCreate(
            ['match_id' => $match->id],
            [
                'reported_by_player_id' => $reporter->id,
                'reporting_team' => $reportingTeam,
                'claimed_winner_team' => $claimedWinnerTeam,
                'claimed_winner_realm' => $claimedWinnerTeam === 'draw'
                    ? null
                    : ($claimedWinnerTeam === 'team_a' ? $match->team_a_realm : $match->team_b_realm),
                'status' => 'pending_confirmation',
                'encounter_screenshot_path' => $primaryEvidencePath,
                'final_screenshot_path' => $primaryEvidencePath,
                'evidence_paths' => array_values($evidencePaths),
                'reporter_note' => $note,
            ]
        );

        $match->update([
            'reported_at' => now(),
            'expires_at' => now()->addMinutes($this->reportConfirmationWindowMinutes()),
            'notes' => $this->appendNote($match->notes, 'Synthetic report created for testing'),
        ]);

        return $report;
    }

    private function storeScreenshot(ArenaMatch $match, UploadedFile $file, string $slot): string
    {
        $directory = 'match-reports/' . now()->format('Y/m') . '/' . strtolower($match->match_code);
        $disk = Storage::disk(MatchReport::EVIDENCE_DISK);
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'png');
        $filename = $slot . '-' . now()->format('His') . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $path = $directory . '/' . $filename;
        $stream = fopen($file->getRealPath(), 'r');

        if ($stream === false) {
            throw new \RuntimeException('No se pudo leer la captura seleccionada. Intenta subirla de nuevo.');
        }

        try {
            $disk->makeDirectory($directory);

            $stored = $disk->put($path, $stream);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'No se pudo guardar la evidencia del match. Revisa permisos de storage en el servidor.',
                previous: $e
            );
        } finally {
            fclose($stream);
        }

        if (!$stored || !$disk->exists($path)) {
            throw new \RuntimeException('La captura no pudo almacenarse correctamente en el servidor.');
        }

        return $path;
    }

    private function storeSyntheticScreenshot(ArenaMatch $match, string $slot): string
    {
        $directory = 'match-reports/testing/' . strtolower($match->match_code);
        $path = $directory . '/' . $slot . '.svg';
        $label = strtoupper($slot) . ' - ' . $match->match_code;

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720">
  <rect width="1280" height="720" fill="#111827"/>
  <rect x="40" y="40" width="1200" height="640" rx="24" fill="#1f2937" stroke="#34d399" stroke-width="4"/>
  <text x="80" y="140" fill="#f9fafb" font-size="54" font-family="Arial">{$label}</text>
  <text x="80" y="240" fill="#d1d5db" font-size="34" font-family="Arial">Synthetic debug proof generated by MVP flow testing</text>
  <text x="80" y="320" fill="#d1d5db" font-size="28" font-family="Arial">Match: {$match->match_code}</text>
  <text x="80" y="380" fill="#d1d5db" font-size="28" font-family="Arial">Zone: {$match->zone_name}</text>
  <text x="80" y="440" fill="#d1d5db" font-size="28" font-family="Arial">Created at: {$match->created_at?->toDateTimeString()}</text>
</svg>
SVG;

        $disk = Storage::disk(MatchReport::EVIDENCE_DISK);
        $disk->makeDirectory($directory);
        $disk->put($path, $svg);

        return $path;
    }

    private function reportConfirmationWindowMinutes(): int
    {
        return max(1, (int) AppSetting::getValue('report_confirmation_window_minutes', 15));
    }

    private function applyPenalty(
        Player $player,
        string $type,
        ?ArenaMatch $match = null,
        ?User $admin = null,
        ?string $note = null
    ): array {
        $profile = $this->penaltyProfile($type);
        $nextStrikes = max(0, (int) $player->penalty_strikes) + 1;
        $lockHours = $this->calculatePenaltyLockHours($profile['base_hours'], $nextStrikes);
        $lockAnchor = $player->queue_locked_until && $player->queue_locked_until->isFuture()
            ? $player->queue_locked_until->copy()
            : now();
        $lockUntil = $lockAnchor->copy()->addHours($lockHours);

        $player->update([
            'queue_locked_until' => $lockUntil,
            'queue_lock_reason' => $type,
            'trust_score' => max(0, $player->trust_score - $profile['trust_penalty']),
            'penalty_strikes' => $nextStrikes,
            'last_penalty_type' => $type,
            'last_penalty_at' => now(),
        ]);

        if ($match) {
            $match->update([
                'notes' => $this->appendNote(
                    $match->notes,
                    $profile['label'] . ' applied to ' . $player->character_name
                    . ' (' . $lockHours . 'h lock, strike ' . $nextStrikes . ')'
                    . ($note ? ': ' . $note : '')
                ),
            ]);
        }

        if ($admin && $match && $match->report) {
            $match->report->update([
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
                'admin_note' => $note,
            ]);
        }

        return [
            'type' => $type,
            'lock_hours' => $lockHours,
            'lock_until' => $lockUntil,
            'trust_penalty' => $profile['trust_penalty'],
            'penalty_strikes' => $nextStrikes,
        ];
    }

    private function penaltyProfile(string $type): array
    {
        return match ($type) {
            'support_infraction' => [
                'label' => 'Support infraction',
                'base_hours' => max(1, (int) AppSetting::getValue('support_infraction_lock_hours', self::SUPPORT_INFRACTION_LOCK_HOURS)),
                'trust_penalty' => max(1, (int) AppSetting::getValue('support_infraction_trust_penalty', self::SUPPORT_INFRACTION_TRUST_PENALTY)),
            ],
            default => [
                'label' => 'Abandonment penalty',
                'base_hours' => max(1, (int) AppSetting::getValue('abandonment_lock_hours', self::ABANDON_LOCK_HOURS)),
                'trust_penalty' => max(1, (int) AppSetting::getValue('abandonment_trust_penalty', self::ABANDON_TRUST_PENALTY)),
            ],
        };
    }

    private function calculatePenaltyLockHours(int $baseHours, int $strikeCount): int
    {
        $multiplier = min(self::MAX_STRIKE_MULTIPLIER, max(1, $strikeCount));
        $maxHours = max($baseHours, (int) AppSetting::getValue('penalty_max_lock_hours', self::MAX_PENALTY_LOCK_HOURS));

        return min($maxHours, $baseHours * $multiplier);
    }

    private function calculateQueueTypeMultipliers(string $result, string $playerQueueType, string $opponentQueueType): array
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

    private function hasPendingReportExpired(ArenaMatch $match, MatchReport $report): bool
    {
        return $report->status === 'pending_confirmation'
            && $match->expires_at !== null
            && now()->gt($match->expires_at);
    }

    private function expirePendingReport(ArenaMatch $match, string $reason): void
    {
        $match->loadMissing('report');

        if (!$match->report || $match->report->status !== 'pending_confirmation') {
            return;
        }

        $match->report->update([
            'status' => 'disputed',
            'resolution_payload' => [
                'resolution_source' => 'report_confirmation_timeout',
            ],
        ]);

        $match->update([
            'status' => 'disputed',
            'expires_at' => null,
            'notes' => $this->appendNote($match->notes, $reason),
        ]);

        $this->closeMatchQueues($match);
        $this->ladderCacheService->forgetRecentMatches();
    }

    private function shiftFutureResultsForPlayer(MatchResult $sourceResult, float $plOffset, int $mmrOffset): void
    {
        if (abs($plOffset) < 0.0001 && $mmrOffset === 0) {
            return;
        }

        $futureResults = MatchResult::query()
            ->where('player_id', $sourceResult->player_id)
            ->where(function ($query) use ($sourceResult) {
                $query->where('created_at', '>', $sourceResult->created_at)
                    ->orWhere(function ($sameTimestamp) use ($sourceResult) {
                        $sameTimestamp->where('created_at', $sourceResult->created_at)
                            ->where('id', '>', $sourceResult->id);
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($futureResults as $futureResult) {
            $futureResult->update([
                'pl_before' => round((float) $futureResult->pl_before + $plOffset, 1),
                'pl_after' => round((float) $futureResult->pl_after + $plOffset, 1),
                'mmr_before' => (int) $futureResult->mmr_before + $mmrOffset,
                'mmr_after' => (int) $futureResult->mmr_after + $mmrOffset,
            ]);
        }
    }

    private function countsAsWin(string $result): bool
    {
        return $result === 'win';
    }

    private function countsAsLoss(string $result): bool
    {
        return in_array($result, ['loss', 'no_show'], true);
    }

    private function deleteEvidencePaths(array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            foreach ([MatchReport::EVIDENCE_DISK, 'public'] as $disk) {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                }
            }
        }
    }

    private function teamSignature(array $playerIds): string
    {
        sort($playerIds);

        return implode('-', $playerIds);
    }

    private function appendNote(?string $notes, string $line): string
    {
        $clean = trim((string) $notes);

        return trim($clean . PHP_EOL . '[' . now()->toDateTimeString() . '] ' . $line);
    }

    private function closeMatchQueues(ArenaMatch $match): void
    {
        $queues = Queue::query()
            ->where('match_id', (string) $match->id)
            ->whereIn('status', ['matched', 'accepted'])
            ->get();

        if ($queues->isEmpty()) {
            return;
        }

        Queue::query()
            ->whereIn('id', $queues->pluck('id'))
            ->update([
                'status' => 'cancelled',
                'expires_at' => null,
            ]);

        $premadePlayerIds = $queues->where('queue_type', 'premade')->pluck('player_id')->unique()->toArray();

        if (!empty($premadePlayerIds)) {
            $partyIds = \App\Models\PartyMember::whereIn('player_id', $premadePlayerIds)
                ->pluck('party_id')
                ->unique()
                ->toArray();

            if (!empty($partyIds)) {
                \App\Models\Party::whereIn('id', $partyIds)
                    ->where('status', 'queued')
                    ->update(['status' => 'ready']);
            }
        }
    }
}
