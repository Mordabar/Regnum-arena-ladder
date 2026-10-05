<?php

namespace App\Services\Matches;

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use App\Services\AvisosPendientesService;
use App\Services\DiscordBotService;
use App\Services\LadderCacheService;
use App\Services\WebPushService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * El reporte del resultado: enviarlo con capturas, confirmarlo o rechazarlo.
 *
 * Parte de lo que era ArenaMatchResultService.
 */
class MatchReportService
{
    public function __construct(
        private readonly DiscordBotService $discordBotService,
        private readonly EvidenceStorage $evidenceStorage,
        private readonly LadderCacheService $ladderCacheService,
        private readonly MatchLifecycleService $lifecycle,
    ) {
    }

    /**
     * Si la base ya tiene la columna de las capturas del rechazo.
     *
     * Se resuelve una vez por peticion: preguntarle el esquema a la base en
     * cada rechazo es una consulta de mas por algo que no cambia.
     */
    private ?bool $soportaPruebas = null;

    public function submitReport(ArenaMatch $match, Player $reporter, array $payload): MatchReport
    {
        if ($match->status !== 'in_progress') {
            throw new \RuntimeException('Solo puedes reportar matches en progreso.');
        }

        // Un amistoso no se reporta: no hay nada que puntuar. Se termina con su
        // propio boton y no deja rastro en el ranking.
        if ($match->isFriendly()) {
            throw new \RuntimeException('Un amistoso no se reporta: no mueve el ranking. Termínalo con el botón "Terminar amistoso".');
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
                        'rejection_evidence_paths' => null,
                        'reviewed_by_user_id' => null,
                        'reviewed_at' => null,
                        'admin_note' => null,
                        'resolution_payload' => null,
                    ]
                );

                $match->update([
                    'reported_at' => now(),
                    'expires_at' => now()->addMinutes($this->reportConfirmationWindowMinutes()),
                    'notes' => MatchNotes::append($match->notes, 'Result report submitted by ' . $reporter->character_name),
                ]);

                return $report;
            });
        } catch (\Throwable $e) {
            $this->deleteEvidencePaths($storedPaths);

            throw $e;
        }

        // Al rival, no a quien acaba de subirlo: ya sabe lo que ha hecho.
        app(WebPushService::class)->avisarAJugadores(
            $match->getAllPlayers(),
            $match->getTeamPlayerIds($report->reporting_team)
        );

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

    private function submitSyntheticReportRecord(
        ArenaMatch $match,
        Player $reporter,
        string $claimedWinnerTeam,
        ?string $note,
        array $evidencePaths
    ): MatchReport {
        if ($match->isFriendly()) {
            throw new \RuntimeException('Un amistoso no se reporta: no mueve el ranking.');
        }

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
            'notes' => MatchNotes::append($match->notes, 'Synthetic report created for testing'),
        ]);

        return $report;
    }

    private function storeScreenshot(ArenaMatch $match, UploadedFile $file, string $slot): string
    {
        return $this->evidenceStorage->store($match, $file, $slot);
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

    public function confirmReport(MatchReport $report, Player $confirmer, ?string $note = null): array
    {
        $match = $report->match()->firstOrFail();

        if ($this->hasPendingReportExpired($match, $report)) {
            DB::transaction(function () use ($match) {
                $this->lifecycle->expirePendingReport($match, 'Report confirmation window expired before confirmation');
            });

            throw new \RuntimeException('El tiempo para responder este reporte expiro. Al no contestar, el resultado se dio por bueno.');
        }

        if ($report->status !== 'pending_confirmation') {
            throw new \RuntimeException('Este reporte ya no esta esperando confirmacion.');
        }

        $confirmerTeam = $match->getTeamSideForPlayer($confirmer->id, (string) $confirmer->user?->discord_id);
        if ($confirmerTeam === null || $confirmerTeam === $report->reporting_team) {
            throw new \RuntimeException('Solo el equipo rival puede confirmar este reporte.');
        }

        $note = $note !== null ? trim($note) : null;

        return DB::transaction(function () use ($report, $match, $confirmer, $note) {
            $report->update([
                'status' => 'confirmed',
                'confirmed_by_player_id' => $confirmer->id,
                'confirmed_at' => now(),
                'confirmation_note' => $note !== '' ? $note : null,
            ]);

            return $this->lifecycle->finalizeMatch($match->fresh('report'), $report->claimed_winner_team, false, [
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

            return $this->lifecycle->finalizeMatch($match->fresh('report'), $report->claimed_winner_team, true, [
                'resolution_source' => 'admin_confirmed_for_rival',
            ]);
        });
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
    ): MatchReport {
        $match = $report->match()->firstOrFail();

        if ($this->hasPendingReportExpired($match, $report)) {
            DB::transaction(function () use ($match) {
                $this->lifecycle->expirePendingReport($match, 'Report confirmation window expired before rejection');
            });

            throw new \RuntimeException('El tiempo para responder este reporte expiro. Al no contestar, el resultado se dio por bueno.');
        }

        if ($report->status !== 'pending_confirmation') {
            throw new \RuntimeException('Este reporte ya no esta esperando confirmacion.');
        }

        $rejectorTeam = $match->getTeamSideForPlayer($rejector->id, (string) $rejector->user?->discord_id);
        if ($rejectorTeam === null || $rejectorTeam === $report->reporting_team) {
            throw new \RuntimeException('Solo el equipo rival puede rechazar este reporte.');
        }

        // Si el despliegue todavia no ha corrido la migracion, la columna de
        // las capturas del rechazo no existe. Sin esta comprobacion el UPDATE
        // reventaba y el rechazo entero se perdia: el jugador pulsaba, volvia
        // al lobby y el enfrentamiento seguia igual, sin pasar a disputa.
        // Rechazar es lo importante; las capturas son el extra.
        $puedeGuardarPruebas = $this->soportaPruebasDeRechazo();

        if (!$puedeGuardarPruebas && $evidenceFiles !== []) {
            Log::warning('Rechazo con capturas en una base sin migrar: se guarda el rechazo sin ellas.', [
                'match_id' => $match->id,
            ]);

            $evidenceFiles = [];
        }

        // Las capturas se guardan ANTES de abrir la transaccion: escribir
        // ficheros dentro de una transaccion no se puede deshacer si algo falla
        // despues, y quedarian huerfanos en el disco.
        $archivos = array_slice(
            array_values(array_filter($evidenceFiles, fn ($file) => $file instanceof UploadedFile)),
            0,
            3
        );

        $storedPaths = [];

        try {
            foreach ($archivos as $index => $file) {
                $storedPaths[] = $this->storeScreenshot($match, $file, 'rejection-' . ($index + 1));
            }
        } catch (\Throwable $e) {
            // Si la segunda captura falla, la primera ya esta en disco y no la
            // referencia nadie. Se limpia antes de propagar.
            $this->deleteEvidencePaths($storedPaths);

            throw $e;
        }

        try {
            DB::transaction(function () use ($report, $rejector, $note, $match, $storedPaths, $puedeGuardarPruebas) {
                $cambios = [
                    'status' => 'rejected',
                    'rejected_by_player_id' => $rejector->id,
                    'rejected_at' => now(),
                    'rejection_note' => $note,
                ];

                if ($puedeGuardarPruebas) {
                    $cambios['rejection_evidence_paths'] = $storedPaths !== [] ? $storedPaths : null;
                }

                $report->update($cambios);

            $match->update([
                'status' => 'disputed',
                'expires_at' => null,
                'notes' => MatchNotes::append($match->notes, 'Report rejected by rival: ' . $rejector->character_name),
            ]);

                $this->lifecycle->closeMatchQueues($match);
            });
        } catch (\Throwable $e) {
            $this->deleteEvidencePaths($storedPaths);

            throw $e;
        }

        $this->ladderCacheService->forgetRecentMatches();
        $this->discordBotService->notifyMatchDisputed($match->fresh('report'), $report);

        // Al equipo que reporto: su resultado queda en manos de un admin.
        $reportaron = $match->getTeamPlayerIds($report->reporting_team);
        app(AvisosPendientesService::class)->registrarHecho(
            $reportaron,
            'reporte:' . $match->id,
            'Resultado en disputa',
            'El rival rechazo tu reporte. Un admin lo revisara.'
        );
        app(WebPushService::class)->avisarAJugadores($reportaron);

        return $report->fresh();
    }

    private function reportConfirmationWindowMinutes(): int
    {
        return max(1, (int) AppSetting::getValue('report_confirmation_window_minutes', 15));
    }

    private function hasPendingReportExpired(ArenaMatch $match, MatchReport $report): bool
    {
        return $report->status === 'pending_confirmation'
            && $match->expires_at !== null
            && now()->gt($match->expires_at);
    }

    private function soportaPruebasDeRechazo(): bool
    {
        // Por instancia, no estatico: un estatico sobreviviria a toda la
        // ejecucion y en las pruebas se llevaria la respuesta de un caso al
        // siguiente, donde el esquema puede ser otro.
        if ($this->soportaPruebas === null) {
            $this->soportaPruebas = Schema::hasColumn('match_reports', 'rejection_evidence_paths');
        }

        return $this->soportaPruebas;
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
}
