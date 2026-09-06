<?php

namespace App\Console\Commands;

use App\Models\MatchResult;
use App\Models\Player;
use App\Services\LadderCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rehace la puntuacion de cada personaje a partir de los enfrentamientos que
 * le quedan.
 *
 * Hizo falta porque la purga del laboratorio devolvia mal los puntos: a quien
 * perdia estando a cero PL se le "restauraba" una caida que nunca ocurrio, y
 * acababa en el ladder con puntuacion sin haber ganado nada. El fallo ya no se
 * produce, pero los personajes que lo sufrieron siguen descuadrados.
 *
 * No inventa nada: la ultima fila de resultado de cada jugador ya guarda como
 * quedo tras ese enfrentamiento. Quien no tenga ninguna vuelve a los valores de
 * un personaje recien creado.
 */
class LadderRecalculateCommand extends Command
{
    protected $signature = 'ladder:recalcular {--dry-run : Solo enseña lo que cambiaria}';

    protected $description = 'Rehace PL, MMR y el historial de cada personaje desde sus enfrentamientos.';

    public function handle(): int
    {
        $ensayo = (bool) $this->option('dry-run');
        $cambiados = 0;

        foreach (Player::query()->orderBy('id')->cursor() as $player) {
            $filas = MatchResult::query()
                ->where('player_id', $player->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $ultima = $filas->last();

            $esperado = [
                'pl_points' => $ultima ? max(0, round((float) $ultima->pl_after, 1)) : 0.0,
                'mmr' => $ultima ? max(100, (int) $ultima->mmr_after) : 1000,
                'wins' => $filas->where('result', 'win')->count(),
                'losses' => $filas->whereIn('result', ['loss', 'no_show'])->count(),
                'matches_played' => $filas->count(),
            ];

            $actual = [
                'pl_points' => round((float) $player->pl_points, 1),
                'mmr' => (int) $player->mmr,
                'wins' => (int) $player->wins,
                'losses' => (int) $player->losses,
                'matches_played' => (int) $player->matches_played,
            ];

            if ($actual == $esperado) {
                continue;
            }

            $cambiados++;
            $this->line(sprintf(
                '%-24s PL %s → %s   MMR %d → %d   %d/%d → %d/%d   partidas %d → %d',
                $player->character_name,
                $actual['pl_points'], $esperado['pl_points'],
                $actual['mmr'], $esperado['mmr'],
                $actual['wins'], $actual['losses'],
                $esperado['wins'], $esperado['losses'],
                $actual['matches_played'], $esperado['matches_played']
            ));

            if (!$ensayo) {
                DB::transaction(fn () => $player->forceFill($esperado)->save());
            }
        }

        if ($cambiados === 0) {
            $this->info('Todo cuadra, no habia nada que rehacer.');

            return self::SUCCESS;
        }

        if ($ensayo) {
            $this->warn($cambiados . ' personaje(s) descuadrados. Quita --dry-run para arreglarlos.');

            return self::SUCCESS;
        }

        // El podio y el top por reino van cacheados: sin esto la portada
        // seguiria enseñando las cifras viejas hasta cinco minutos.
        app(LadderCacheService::class)->forgetSummary();
        $this->info($cambiados . ' personaje(s) rehechos.');

        return self::SUCCESS;
    }
}
