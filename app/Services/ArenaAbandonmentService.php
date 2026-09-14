<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\AppSetting;
use App\Models\MatchAbandonmentReport;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Abandonos: avisar de que alguien se fue, y resolverlo.
 *
 * El aviso no sanciona. Deja el enfrentamiento en disputa, sin mover un solo
 * punto, y espera a moderacion. Es a proposito: un boton que castigue con el
 * clic de un jugador se convierte en un arma el primer dia, y da igual que
 * pidamos captura, porque nadie la mira antes de aplicar nada.
 *
 * Al confirmarlo desde el panel el enfrentamiento pasa a 'abandoned' y la
 * factura la paga solo quien se fue: bloqueo de cola, confianza y PL. Su
 * compañero no pierde nada por haberse quedado solo, y los rivales tampoco
 * ganan: el combate no se jugo entero, asi que no hay resultado que repartir.
 */
class ArenaAbandonmentService
{
    /** PL que pierde quien abandona. Una derrota normal resta 2. */
    private const ABANDON_PL_PENALTY = 2.0;

    public function __construct(
        private readonly ArenaMatchResultService $resultService,
        private readonly LadderCacheService $ladderCacheService,
    ) {
    }

    /**
     * Un jugador avisa de que alguien abandono. Puede señalar a un rival o a su
     * propio compañero.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function report(
        ArenaMatch $match,
        Player $reporter,
        int $accusedPlayerId,
        ?string $note = null,
        array $files = []
    ): MatchAbandonmentReport {
        $participantes = $match->getAllPlayers()->pluck('player_id')->map(fn ($id) => (int) $id);

        if (!$participantes->contains((int) $reporter->id)) {
            throw new \RuntimeException('No participaste en este enfrentamiento.');
        }

        if (!$participantes->contains($accusedPlayerId)) {
            throw new \RuntimeException('Ese jugador no esta en este enfrentamiento.');
        }

        if ($accusedPlayerId === (int) $reporter->id) {
            throw new \RuntimeException('No puedes reportarte a ti mismo.');
        }

        if (!in_array($match->status, ['in_progress', 'disputed'], true)) {
            throw new \RuntimeException('Solo se puede reportar un abandono mientras el combate esta en curso.');
        }

        $yaAvisado = MatchAbandonmentReport::query()
            ->where('match_id', $match->id)
            ->where('reported_by_player_id', $reporter->id)
            ->where('accused_player_id', $accusedPlayerId)
            ->exists();

        if ($yaAvisado) {
            throw new \RuntimeException('Ya reportaste a ese jugador en este enfrentamiento.');
        }

        $rutas = [];
        foreach (array_slice(array_values(array_filter($files)), 0, 3) as $indice => $file) {
            $rutas[] = $this->guardarCaptura($match, $file, 'abandono-' . ($indice + 1));
        }

        return DB::transaction(function () use ($match, $reporter, $accusedPlayerId, $note, $rutas) {
            $aviso = MatchAbandonmentReport::create([
                'match_id' => $match->id,
                'reported_by_player_id' => $reporter->id,
                'accused_player_id' => $accusedPlayerId,
                'note' => $note,
                'evidence_paths' => $rutas ?: null,
                'status' => 'pending',
            ]);

            // El aviso NO toca el estado del enfrentamiento, solo lo anota.
            //
            // Mandarlo a 'disputed' parecia lo natural y era un agujero: con el
            // match fuera de 'in_progress', submitReport() deja de aceptar el
            // reporte del rival y los barridos de vencimiento dejan de verlo,
            // asi que a las 48 horas se auto-anulaba. Resultado: quien iba
            // perdiendo pulsaba el boton y convertia su derrota en un cero a
            // cero, gratis y sin una sola captura. El aviso no sancionaba al
            // acusado, pero decidia el combate, que es peor.
            //
            // Ahora el combate sigue su curso -se reporta, se confirma y se
            // puntua como siempre- y el aviso viaja en paralelo hasta que
            // moderacion lo resuelve.
            //
            // Ni siquiera se le anota la nota al enfrentamiento: escribir en el
            // toca `updated_at`, y expireStaleDisputes() elige por ese campo,
            // asi que cada aviso reiniciaba el reloj de 48 horas de la disputa.
            // Con un par (avisador, acusado) distinto cada vez se podia aplazar
            // el cierre automatico durante meses. El aviso ya es su propia
            // fila con su propia fecha: no hace falta duplicarlo aqui.
            return $aviso;
        });
    }

    /**
     * Moderacion confirma el abandono: el enfrentamiento queda abandonado y
     * paga quien se fue.
     */
    public function confirm(
        MatchAbandonmentReport $aviso,
        ?User $admin = null,
        ?string $note = null
    ): void {
        $match = $aviso->match()->firstOrFail();
        $acusado = Player::findOrFail($aviso->accused_player_id);

        // PRIMERO se reclama el aviso, y solo despues se toca nada.
        //
        // Antes la devolucion de puntos iba delante de esta comprobacion, y
        // fuera de la transaccion: confirmar un aviso YA resuelto -boton atras,
        // reenvio del formulario, dos pestañas- anulaba un combate puntuado,
        // borraba los cuatro resultados, y el guard abortaba despues, con el
        // daño ya escrito. El admin leia "abandono confirmado" sin que nadie
        // hubiera sido sancionado. El candado tiene que ir delante del efecto,
        // no detras.
        $tomado = MatchAbandonmentReport::query()
            ->whereKey($aviso->getKey())
            ->where('status', 'pending')
            ->update([
                'status' => 'confirmed',
                'reviewed_by_user_id' => $admin?->id,
                'reviewed_at' => now(),
                'admin_note' => $note,
            ]);

        if ($tomado === 0) {
            return;
        }

        try {
            $this->aplicarAbandono($aviso, $match, $acusado, $admin, $note);
        } catch (\Throwable $e) {
            // Si algo revienta a mitad, el aviso vuelve a estar pendiente: peor
            // que reintentarlo es dejarlo marcado como resuelto sin efecto.
            MatchAbandonmentReport::query()
                ->whereKey($aviso->getKey())
                ->update(['status' => 'pending', 'reviewed_at' => null]);

            throw $e;
        }

        $this->ladderCacheService->forgetRecentMatches();
    }

    /**
     * El efecto del abandono, con el aviso ya reclamado.
     */
    private function aplicarAbandono(
        MatchAbandonmentReport $aviso,
        ArenaMatch $match,
        Player $acusado,
        ?User $admin,
        ?string $note
    ): void {
        // Si el combate llego a puntuar -se reporto y se confirmo mientras el
        // aviso esperaba-, esos puntos no pueden quedarse: un abandonado no
        // reparte resultado.
        if ($match->results()->exists()) {
            $this->resultService->markVoid(
                $match,
                $admin,
                'Puntos devueltos antes de marcar el abandono'
            );
            $match->refresh();
        }

        DB::transaction(function () use ($aviso, $match, $acusado, $admin, $note) {
            // Los demas avisos del mismo enfrentamiento contra el mismo
            // jugador quedan resueltos con este; no se sanciona dos veces.
            MatchAbandonmentReport::query()
                ->where('match_id', $match->id)
                ->where('accused_player_id', $acusado->id)
                ->where('id', '!=', $aviso->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'confirmed',
                    'reviewed_by_user_id' => $admin?->id,
                    'reviewed_at' => now(),
                ]);

            $plAntes = round((float) $acusado->pl_points, 1);
            $castigoPl = max(0.0, (float) AppSetting::getValue(
                'abandonment_pl_penalty',
                self::ABANDON_PL_PENALTY
            ));

            // El bloqueo de cola, la confianza y el strike ya los sabe aplicar
            // el servicio de resultados, con su escalado por reincidencia.
            $this->resultService->applyAbandonmentPenalty(
                $acusado,
                $match,
                $admin,
                $note ?? 'Abandono confirmado'
            );

            // El PL va aparte porque la sancion de siempre no lo tocaba.
            $acusado->refresh();
            $acusado->update([
                'pl_points' => max(0.0, round((float) $acusado->pl_points - $castigoPl, 1)),
            ]);

            $match->update([
                'status' => 'abandoned',
                'winner_team' => null,
                'winner_realm' => null,
                'completed_at' => $match->completed_at ?? now(),
                'expires_at' => null,
                'notes' => $this->añadirNota(
                    $match->notes,
                    'Abandono confirmado: ' . $acusado->character_name
                    . ' (-' . $castigoPl . ' PL, de ' . $plAntes . ')'
                    . ($note ? ': ' . $note : '')
                ),
            ]);

            if ($match->report) {
                $match->report->update([
                    'status' => 'admin_resolved',
                    'reviewed_by_user_id' => $admin?->id,
                    'reviewed_at' => now(),
                    'admin_note' => $note,
                ]);
            }
        });
    }

    /**
     * Moderacion descarta el aviso. Nadie es sancionado.
     */
    public function dismiss(
        MatchAbandonmentReport $aviso,
        ?User $admin = null,
        ?string $note = null
    ): void {
        $match = $aviso->match()->firstOrFail();

        DB::transaction(function () use ($aviso, $match, $admin, $note) {
            $tomado = MatchAbandonmentReport::query()
                ->whereKey($aviso->getKey())
                ->where('status', 'pending')
                ->update([
                    'status' => 'dismissed',
                    'reviewed_by_user_id' => $admin?->id,
                    'reviewed_at' => now(),
                    'admin_note' => $note,
                ]);

            if ($tomado === 0) {
                return;
            }

            // No hay nada que devolver a su sitio: el aviso nunca cambio el
            // estado del enfrentamiento. Antes si lo hacia, y descartarlo lo
            // resucitaba a 'in_progress' con el plazo ya vencido, asi que el
            // siguiente barrido lo anulaba al instante: darle la razon al
            // acusado destruia igualmente su combate.
            $match->update([
                'notes' => $this->añadirNota(
                    $match->notes,
                    'Aviso de abandono descartado' . ($note ? ': ' . $note : '')
                ),
            ]);
        });
    }

    private function guardarCaptura(ArenaMatch $match, UploadedFile $file, string $slot): string
    {
        $carpeta = 'match-reports/' . now()->format('Y/m') . '/' . strtolower($match->match_code);
        $disco = Storage::disk(MatchReport::EVIDENCE_DISK);
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'png');
        $ruta = $carpeta . '/' . $slot . '-' . now()->format('His') . '-' . bin2hex(random_bytes(6)) . '.' . $extension;

        $stream = fopen($file->getRealPath(), 'r');

        if ($stream === false) {
            throw new \RuntimeException('No se pudo leer la captura seleccionada. Intenta subirla de nuevo.');
        }

        try {
            $disco->makeDirectory($carpeta);
            $guardado = $disco->put($ruta, $stream);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'No se pudo guardar la captura del abandono. Revisa permisos de storage en el servidor.',
                previous: $e
            );
        } finally {
            fclose($stream);
        }

        if (!$guardado || !$disco->exists($ruta)) {
            throw new \RuntimeException('La captura no pudo almacenarse correctamente en el servidor.');
        }

        return $ruta;
    }

    private function añadirNota(?string $notas, string $nueva): string
    {
        return trim(($notas ?? '') . "\n" . $nueva);
    }
}
