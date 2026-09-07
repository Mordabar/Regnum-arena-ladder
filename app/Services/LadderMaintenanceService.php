<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\MatchResult;
use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Mantenimiento del ranking desde el panel.
 *
 * Hasta ahora la unica forma de arreglar una puntuacion torcida era la consola
 * del servidor, y el laboratorio de pruebas solo sabia borrar lo suyo. Cuando
 * los enfrentamientos que sobraban eran entre personajes de verdad -unas
 * partidas de prueba jugadas a mano- no habia ninguna herramienta.
 */
class LadderMaintenanceService
{
    public function __construct(
        private readonly LadderCacheService $ladderCacheService,
    ) {
    }

    /**
     * Rehace PL, MMR y el historial de cada personaje desde los
     * enfrentamientos que le quedan.
     *
     * No inventa nada: la ultima fila de resultado de cada jugador ya guarda
     * como quedo tras ese enfrentamiento. Quien no tenga ninguna vuelve a los
     * valores de un personaje recien creado.
     *
     * @return array{revisados:int, corregidos:int, detalle:array<int, array<string, mixed>>}
     */
    public function recalcularRanking(bool $ensayo = false): array
    {
        $revisados = 0;
        $detalle = [];

        foreach (Player::query()->orderBy('id')->cursor() as $player) {
            $revisados++;
            $esperado = $this->estadoSegunHistorial($player);
            $actual = $this->estadoActual($player);

            if ($actual == $esperado) {
                continue;
            }

            $detalle[] = [
                'player_id' => $player->id,
                'character_name' => $player->character_name,
                'antes' => $actual,
                'despues' => $esperado,
            ];

            if (!$ensayo) {
                $player->forceFill($esperado)->save();
            }
        }

        if (!$ensayo && $detalle !== []) {
            $this->ladderCacheService->forgetSummary();
        }

        return [
            'revisados' => $revisados,
            'corregidos' => count($detalle),
            'detalle' => $detalle,
        ];
    }

    /**
     * Borra enfrentamientos y devuelve a cada jugador lo que le repartieron.
     *
     * Se pasa por el recalculo en vez de restar a mano: restar movimiento a
     * movimiento va bien mientras nadie toque nada por otro lado, y aqui se
     * borran partidas sueltas elegidas desde el panel.
     *
     * @param  Collection<int, string>|array<int, string>  $matchIds
     * @return array{matches_deleted:int, reports_deleted:int, results_deleted:int, evidence_deleted:int, players_recalculated:int}
     */
    public function borrarEnfrentamientos(Collection|array $matchIds): array
    {
        $ids = collect($matchIds)->filter()->unique()->values();

        $resultado = [
            'matches_deleted' => 0,
            'reports_deleted' => 0,
            'results_deleted' => 0,
            'evidence_deleted' => 0,
            'players_recalculated' => 0,
        ];

        if ($ids->isEmpty()) {
            return $resultado;
        }

        $resultado['evidence_deleted'] = $this->borrarPruebas($ids);

        DB::transaction(function () use ($ids, &$resultado) {
            $resultado['results_deleted'] = MatchResult::query()->whereIn('match_id', $ids)->delete();
            $resultado['reports_deleted'] = MatchReport::query()->whereIn('match_id', $ids)->delete();
            $resultado['matches_deleted'] = ArenaMatch::query()->whereIn('id', $ids)->delete();
        });

        $resultado['players_recalculated'] = $this->recalcularRanking()['corregidos'];

        return $resultado;
    }

    /**
     * Deja el ranking a cero: borra TODOS los enfrentamientos y su rastro.
     *
     * Es el boton de empezar de nuevo, para cerrar una fase de pruebas sin
     * tener que borrar los personajes.
     */
    public function reiniciarRanking(): array
    {
        return $this->borrarEnfrentamientos(ArenaMatch::query()->pluck('id'));
    }

    /** Con que valores deberia estar el personaje segun sus enfrentamientos. */
    private function estadoSegunHistorial(Player $player): array
    {
        $filas = MatchResult::query()
            ->where('player_id', $player->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $ultima = $filas->last();

        return [
            'pl_points' => $ultima ? max(0, round((float) $ultima->pl_after, 1)) : 0.0,
            'mmr' => $ultima ? max(100, (int) $ultima->mmr_after) : Player::MMR_INICIAL,
            'wins' => $filas->where('result', 'win')->count(),
            'losses' => $filas->whereIn('result', ['loss', 'no_show'])->count(),
            'matches_played' => $filas->count(),
        ];
    }

    private function estadoActual(Player $player): array
    {
        return [
            'pl_points' => round((float) $player->pl_points, 1),
            'mmr' => (int) $player->mmr,
            'wins' => (int) $player->wins,
            'losses' => (int) $player->losses,
            'matches_played' => (int) $player->matches_played,
        ];
    }

    /** Las capturas subidas como prueba tampoco tienen por que quedarse. */
    private function borrarPruebas(Collection $matchIds): int
    {
        $borradas = 0;

        foreach (MatchReport::query()->whereIn('match_id', $matchIds)->get() as $report) {
            foreach ((array) ($report->evidence_paths ?? []) as $path) {
                if (is_string($path) && $path !== '' && Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                    $borradas++;
                }
            }
        }

        return $borradas;
    }
}
