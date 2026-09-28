<?php

namespace App\Services\Matches;

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\AvisosPendientesService;
use App\Services\DiscordBotService;
use App\Services\LadderCacheService;
use App\Services\LadderScoringService;
use App\Services\WebPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La vida de un combate despues del cruce: arrancarlo cuando todos aceptan,
 * cerrarlo y repartir puntos, y lo que caduca solo (reportes sin confirmar,
 * combates sin reporte, disputas viejas).
 *
 * Parte de lo que era ArenaMatchResultService.
 */
class MatchLifecycleService
{
    public function __construct(
        private readonly DiscordBotService $discordBotService,
        private readonly LadderCacheService $ladderCacheService,
        private readonly LadderScoringService $ladderScoringService,
        private readonly MatchPoints $points,
    ) {
    }

    public function finalizeMatch(
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
        // 'abandoned' cuenta igual: puntuarlo despues borraba el abandono del
        // expediente y lo pasaba a 'completed', mientras la sancion al que se
        // fue seguia aplicada. Quedaban castigo y partida normal a la vez.
        if (in_array($match->status, ['cancelled', 'void', 'abandoned'], true)) {
            $comoQuedo = match ($match->status) {
                'void' => 'anulado',
                'abandoned' => 'marcado como abandonado',
                default => 'interrumpido',
            };

            throw new \RuntimeException('Este match fue ' . $comoQuedo . ' y ya no puede puntuarse.');
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

            $repeatMultiplier = $this->points->calculateRepeatMultiplier($match);

            foreach ($scoring['players'] as $playerResult) {
                $player = Player::findOrFail($playerResult['player_id']);
                $dailyMultiplier = $playerResult['pl_change'] > 0
                    ? $this->points->calculateDailyGainMultiplier($player->id)
                    : 1.0;
                $playerSide = $match->getTeamSideForPlayer($player->id);
                $playerQueueType = $playerSide ? $match->getTeamQueueType($playerSide) : $match->queue_mode;
                $opponentQueueType = $playerSide ? $match->getOpponentQueueTypeForSide($playerSide) : $match->queue_mode;
                $queueTypeMultipliers = $this->points->calculateQueueTypeMultipliers(
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

                // La columna guarda lo que de verdad cambio, no lo que tocaba.
                // Nadie baja de cero PL: a quien pierde estando a cero no se le
                // quita nada, y anotar la resta entera dejaba la fila mintiendo.
                // Quien luego la use para deshacer -la purga del laboratorio-
                // le devolveria puntos que nunca perdio.
                //
                // Lo que pedia la formula no se pierde: va al contexto, que es
                // donde se audita el reparto.
                $plChangeTeorico = $finalPlChange;
                $mmrChangeTeorico = $finalMmrChange;
                $finalPlChange = round($finalPlAfter - $playerResult['pl_before'], 1);
                $finalMmrChange = $finalMmrAfter - $playerResult['mmr_before'];

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
                    // Lo que pedia la formula ya con multiplicadores y topes,
                    // antes del suelo de cero.
                    'pl_change_theoretical' => $plChangeTeorico,
                    'mmr_change_theoretical' => $mmrChangeTeorico,
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
            'notes' => MatchNotes::append(
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

        // El resultado cerrado era el unico momento del flujo sin aviso: el
        // rival confirmaba con tu pagina cerrada y no te enterabas de si
        // habias subido o bajado. A quien confirmo no: acaba de pulsar.
        app(WebPushService::class)->avisarAJugadores(
            $match->getAllPlayers(),
            array_filter([(int) ($resolutionContext['confirmed_by_player_id'] ?? 0)])
        );

        $this->discordBotService->notifyReportResolved($match->fresh(['report', 'results']), $payload);

        return $payload;
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

        // Ya aceptaron todos: a partir de aqui hay que ir a la zona.
        app(WebPushService::class)->avisarAJugadores($match->getAllPlayers());

        app(DiscordBotService::class)->notifyMatchAccepted($match->fresh());

        return true;
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
            // Las que ya repartieron puntos se quedan fuera del barrido a
            // proposito. markVoid sabe deshacerlas, pero quitarle puntos a
            // cuatro jugadores es una decision que toma una persona, no un
            // reloj: desde el panel se anula a mano cuando toca.
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
                    // El mismo camino que cuando el jugador llega tarde a la
                    // pantalla. Estaba copiado aqui, y las dos copias ya habian
                    // empezado a separarse: una anotaba el motivo en el
                    // enfrentamiento y la otra no, y cada una guardaba un
                    // origen distinto para el mismo suceso.
                    $this->expirePendingReport($match, 'Report confirmation window expired');
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
                    $this->disputePendingReport($match, 'Report confirmation window expired');
                });
            }
        }

        return $closed;
    }

    /**
     * Red de seguridad: el reporte vencio y ademas no se pudo puntuar.
     *
     * No es el caso normal -ese lo resuelve expirePendingReport dando el
     * reporte por bueno- sino el del enfrentamiento que ya se cerro por otro
     * camino o que rompe al calcular. Eso necesita una persona, asi que va a
     * disputa, que es donde moderacion lo ve.
     */
    private function disputePendingReport(ArenaMatch $match, string $reason): void
    {
        // Se relee de la base a proposito. Aqui se llega desde el catch de una
        // transaccion que ya marco el reporte como confirmado EN MEMORIA antes
        // de fallar; la vuelta atras deshizo la fila, no el objeto. Con el
        // objeto viejo la guardia de abajo veia 'confirmed', se iba sin hacer
        // nada, y el enfrentamiento se quedaba colgado: nunca llegaba a
        // moderacion y los dos jugadores seguian atrapados en su fila de cola.
        $match->unsetRelation('report');
        $match->load('report');

        if (!$match->report || $match->report->status !== 'pending_confirmation') {
            return;
        }

        $match->report->update([
            'status' => 'disputed',
            'resolution_payload' => [
                // Nombre propio: 'report_confirmation_timeout' es el silencio
                // que SI puntua. Compartir cadena para dos desenlaces opuestos
                // deja cualquier consulta de moderacion sin poder separarlos.
                'resolution_source' => 'report_confirmation_timeout_unscorable',
            ],
        ]);

        $match->update([
            'status' => 'disputed',
            'expires_at' => null,
            'notes' => MatchNotes::append($match->notes, $reason),
        ]);

        $this->closeMatchQueues($match);
        $this->ladderCacheService->forgetRecentMatches();
    }

    /**
     * El rival dejo pasar su plazo sin decir nada.
     *
     * Antes esto mandaba el enfrentamiento a disputa, y no cuadraba: a disputa
     * se entra cuando alguien RECHAZA, porque hay dos versiones enfrentadas que
     * un arbitro tiene que mirar. El silencio no es una version enfrentada; el
     * silencio otorga. Ademas mandar a disputa cada reporte no contestado
     * llenaba la bandeja de moderacion de cosas que nadie discutia.
     *
     * Asi que el reporte se da por bueno y reparte puntos, igual que si el
     * rival hubiera pulsado confirmar. Queda anotado de donde salio.
     */
    public function expirePendingReport(ArenaMatch $match, string $reason): void
    {
        $match->loadMissing('report');

        if (!$match->report || $match->report->status !== 'pending_confirmation') {
            return;
        }

        $report = $match->report;

        $report->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $match->update([
            'notes' => MatchNotes::append($match->notes, $reason),
        ]);

        $this->finalizeMatch($match->fresh('report'), $report->claimed_winner_team, false, [
            'resolution_source' => 'report_confirmation_timeout',
        ]);

        $this->ladderCacheService->forgetRecentMatches();
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
        $devueltos = [];
        $anulado = false;

        DB::transaction(function () use ($match, $admin, $note, &$devueltos, &$anulado) {
            // Bajo llave y releido: dos clics seguidos en "anular" veian los
            // mismos resultados y devolvian los puntos dos veces.
            $bloqueado = ArenaMatch::query()->whereKey($match->getKey())->lockForUpdate()->first();

            if (!$bloqueado || $bloqueado->status === 'void') {
                return;
            }

            $devueltos = $this->revertMatchResults($bloqueado);

            if ($bloqueado->report) {
                $cambios = [
                    'status' => 'voided',
                    'reviewed_at' => now(),
                    'admin_note' => $note,
                    'resolution_payload' => array_filter([
                        'resolution_source' => $devueltos === [] ? 'admin_void' : 'admin_void_scored',
                        // Se guarda lo que se deshizo: borrar las filas sin
                        // dejar rastro no dejaba forma de reconstruir la
                        // partida si la anulacion fue un error.
                        'reverted_results' => $devueltos ?: null,
                        'previous_resolution' => $bloqueado->report->resolution_payload ?: null,
                        'previous_admin_note' => $bloqueado->report->admin_note ?: null,
                    ], fn ($valor) => $valor !== null),
                ];

                // Solo se pisa si hay un admin de verdad; desde el panel llega
                // null, y sobrescribir borraria quien reviso la vez anterior.
                if ($admin) {
                    $cambios['reviewed_by_user_id'] = $admin->id;
                }

                $bloqueado->report->update($cambios);
            }

            $bloqueado->update([
                'status' => 'void',
                'winner_team' => null,
                'winner_realm' => null,
                // Se conserva: pisarlo con ahora subiria una partida de hace
                // semanas a lo alto de la lista de combates recientes.
                'completed_at' => $bloqueado->completed_at ?? now(),
                'expires_at' => null,
                'notes' => MatchNotes::append(
                    $bloqueado->notes,
                    ($devueltos === [] ? 'Match voided' : 'Scored match voided, points reverted')
                        . ($note ? ': ' . $note : '')
                ),
            ]);

            $this->closeMatchQueues($bloqueado);
            $anulado = true;
        });

        // Despues del commit, no antes.
        //
        // markVoid se llama ahora desde dentro de otra transaccion -la del
        // abandono, la de "interrumpido"-, asi que estas lineas corrian con
        // todo sin confirmar: si la operacion externa reventaba despues, la
        // base volvia atras pero Discord ya habia anunciado que se anulo el
        // resultado y se devolvieron los puntos. Cuatro jugadores leyendo algo
        // que no paso. DB::afterCommit() lo ejecuta cuando ya no hay vuelta
        // atras, y fuera de transaccion corre igual, en el acto.
        DB::afterCommit(function () use ($match, $devueltos, $note, $anulado) {
            $this->ladderCacheService->forgetRecentMatches();

            if ($anulado) {
                $jugadores = $match->getAllPlayers();
                app(AvisosPendientesService::class)->registrarHecho(
                    $jugadores,
                    'combate:' . $match->id,
                    'Combate anulado',
                    $devueltos === [] ? 'Un admin anulo el combate.' : 'Un admin anulo el combate y devolvio los puntos.'
                );
                app(WebPushService::class)->avisarAJugadores($jugadores);
            }

            if ($devueltos !== []) {
                // Quien pierde PL y MMR por una decision de moderacion tiene
                // que enterarse, igual que cuando se corrige un resultado.
                $this->ladderCacheService->forgetSummary();
                $this->discordBotService->notifyReportResolved($match->fresh(['report', 'results']), [
                    'resolution_source' => 'admin_void_scored',
                    'winner_team' => null,
                    'winner_realm' => null,
                    'note' => $note,
                ]);
            }
        });
    }

    /**
     * Deshace lo que un enfrentamiento repartio y borra sus resultados.
     *
     * Devuelve lo que habia, para poder dejarlo anotado.
     *
     * @return array<int, array<string, mixed>>
     */
    private function revertMatchResults(ArenaMatch $match): array
    {
        $resultados = MatchResult::query()
            ->where('match_id', $match->id)
            ->lockForUpdate()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($resultados->isEmpty()) {
            return [];
        }

        $devueltos = [];

        foreach ($resultados as $fila) {
            // Se deshace el movimiento REAL, no lo que dice pl_change. Con el
            // suelo en cero una derrota puede apuntar -8 y haber quitado solo
            // 3: devolver los 8 seria regalar cinco puntos, y ademas moveria
            // todo el historial posterior de ese jugador.
            $plOffset = round(-1 * ((float) $fila->pl_after - (float) $fila->pl_before), 1);
            $mmrOffset = -1 * ((int) $fila->mmr_after - (int) $fila->mmr_before);

            $devueltos[] = [
                'player_id' => (int) $fila->player_id,
                'result' => $fila->result,
                'pl_change' => (float) $fila->pl_change,
                'mmr_change' => (int) $fila->mmr_change,
                'pl_before' => (float) $fila->pl_before,
                'pl_after' => (float) $fila->pl_after,
                'mmr_before' => (int) $fila->mmr_before,
                'mmr_after' => (int) $fila->mmr_after,
            ];

            // Primero las partidas posteriores, que aun apuntan a esta fila
            // para saber donde empiezan. Despues se borra.
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

        return $devueltos;
    }

    public function markDisputed(ArenaMatch $match, ?User $admin = null, ?string $note = null): void
    {
        // Un match ya puntuado no vuelve a disputa: desapareceria de los
        // listados de completados mientras sus puntos siguen contando en el
        // ladder, que es justo el estado incoherente que nadie quiere mirar.
        // Para cambiar el ganador esta forceComplete, y para deshacerlo del
        // todo, markVoid, que devuelve lo repartido.
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
                'notes' => MatchNotes::append($match->notes, 'Marked as disputed' . ($note ? ': ' . $note : '')),
            ]);

            $this->closeMatchQueues($match);
        });

        $this->ladderCacheService->forgetRecentMatches();
    }

    public function closeMatchQueues(ArenaMatch $match): void
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

    /**
     * Cerrar las colas de un enfrentamiento terminado, desde fuera.
     *
     * El servicio de abandonos lo necesita: era la unica via que terminaba un
     * combate sin cerrarlas, y dejaba a los cuatro jugadores con su fila en
     * 'accepted' apuntando a una partida acabada.
     */
    public function cerrarColasDelEnfrentamiento(ArenaMatch $match): void
    {
        $this->closeMatchQueues($match);
    }
}
