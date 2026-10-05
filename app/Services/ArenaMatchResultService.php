<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\User;
use App\Services\Matches\MatchLifecycleService;
use App\Services\Matches\MatchModerationService;
use App\Services\Matches\MatchPenaltyService;
use App\Services\Matches\MatchReportService;
use Illuminate\Http\UploadedFile;

/**
 * La puerta de entrada al ciclo de un combate despues del cruce.
 *
 * Antes todo vivia aqui (mas de 2.000 lineas). Ahora el trabajo lo hacen
 * servicios con una sola tarea en App\Services\Matches (reporte, ciclo de
 * vida, moderacion, sanciones y puntos) y esta clase solo delega, para que
 * nada de lo que ya la usaba tenga que cambiar.
 */
class ArenaMatchResultService
{
    public function __construct(
        private readonly MatchLifecycleService $lifecycle,
        private readonly MatchReportService $reports,
        private readonly MatchModerationService $moderation,
        private readonly MatchPenaltyService $penalties,
    ) {
    }

    public function submitReport(ArenaMatch $match, Player $reporter, array $payload): MatchReport
    {
        return $this->reports->submitReport($match, $reporter, $payload);
    }

    public function submitSyntheticReport(
        ArenaMatch $match,
        Player $reporter,
        string $claimedWinnerTeam,
        ?string $note = null
    ): MatchReport
    {
        return $this->reports->submitSyntheticReport($match, $reporter, $claimedWinnerTeam, $note);
    }

    public function confirmReport(MatchReport $report, Player $confirmer): array
    {
        return $this->reports->confirmReport($report, $confirmer);
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
        return $this->reports->confirmReportForRival($report, $note);
    }

    /**
     * El rival rechaza el reporte y el enfrentamiento pasa a disputa.
     *
     * $evidenceFiles son las capturas con las que el que rechaza sostiene su
     * version. Son opcionales: obligarlas dejaria sin salida a quien no tomo
     * captura, y le forzaria a tragarse un resultado falso. Pero se guardan
     * aparte de las del reporte para que moderacion pueda poner las dos
     * versiones una al lado de la otra.
     *
     * @param array<int, \Illuminate\Http\UploadedFile> $evidenceFiles
     */
    public function rejectReport(
        MatchReport $report,
        Player $rejector,
        ?string $note = null,
        array $evidenceFiles = []
    ): MatchReport
    {
        return $this->reports->rejectReport($report, $rejector, $note, $evidenceFiles);
    }

    public function forceComplete(
        ArenaMatch $match,
        string $winnerTeam,
        ?User $admin = null,
        ?string $note = null
    ): array
    {
        return $this->moderation->forceComplete($match, $winnerTeam, $admin, $note);
    }

    /** Termina un amistoso: PvP sin ranking. */
    public function finishFriendly(ArenaMatch $match, string $como = 'manual'): bool
    {
        return $this->lifecycle->finishFriendly($match, $como);
    }

    /**
     * Anula un enfrentamiento.
     *
     * Si ya estaba puntuado tambien se anula, deshaciendo lo que repartio. La
     * negativa de antes dejaba a moderacion sin salida: una partida cerrada por
     * un fallo -el rival confirmo algo que no paso, o el sistema la cerro sola-
     * se quedaba contando en el ladder para siempre, porque forceComplete solo
     * sabe cambiar el ganador, y aqui el problema es que no hubo partida.
     */
    public function markVoid(ArenaMatch $match, ?User $admin = null, ?string $note = null): void
    {
        $this->lifecycle->markVoid($match, $admin, $note);
    }

    public function markDisputed(ArenaMatch $match, ?User $admin = null, ?string $note = null): void
    {
        $this->lifecycle->markDisputed($match, $admin, $note);
    }

    public function applyAbandonmentPenalty(
        Player $player,
        ?ArenaMatch $match = null,
        ?User $admin = null,
        ?string $note = null
    ): void
    {
        $this->penalties->applyAbandonmentPenalty($player, $match, $admin, $note);
    }

    public function applyManualQueueLock(Player $player, int $hours = 12, ?string $note = null): void
    {
        $this->penalties->applyManualQueueLock($player, $hours, $note);
    }

    public function clearQueueLock(Player $player): void
    {
        $this->penalties->clearQueueLock($player);
    }

    public function applyAbandonmentWalkover(
        ArenaMatch $match,
        int $offendingPlayerId,
        ?User $admin = null,
        ?string $note = null
    ): array
    {
        return $this->moderation->applyAbandonmentWalkover($match, $offendingPlayerId, $admin, $note);
    }

    public function applySupportInfraction(
        ArenaMatch $match,
        int $offendingPlayerId,
        ?User $admin = null,
        ?string $note = null
    ): array
    {
        return $this->moderation->applySupportInfraction($match, $offendingPlayerId, $admin, $note);
    }

    public function sweepPostMatchState(): array
    {
        return $this->lifecycle->sweepPostMatchState();
    }

    /** Horas que una disputa espera a moderacion antes de anularse sola. */
    public function disputeAutoVoidHours(): int
    {
        return $this->lifecycle->disputeAutoVoidHours();
    }

    /**
     * Checks if all players have accepted the match and, if so, transitions it to in_progress.
     * This consolidates the duplicate logic from ArenaMatchController::checkAllPlayersAccepted()
     * and the old lobby controller into one authoritative location.
     *
     * @return bool Whether the match was promoted to in_progress
     */
    public function promoteMatchToInProgressIfReady(ArenaMatch $match): bool
    {
        return $this->lifecycle->promoteMatchToInProgressIfReady($match);
    }

    /**
     * Cerrar las colas de un enfrentamiento terminado, desde fuera.
     *
     * El servicio de abandonos lo necesita: era la unica via que terminaba un
     * combate sin cerrarlas, y dejaba a los cuatro jugadores con su fila en
     * 'accepted' apuntando a una partida acabada.
     */
    public function cerrarColasDelEnfrentamiento(ArenaMatch $match): void
    {
        $this->lifecycle->cerrarColasDelEnfrentamiento($match);
    }
}
