<?php

namespace App\Console\Commands;

use App\Services\LadderMaintenanceService;
use Illuminate\Console\Command;

/**
 * Rehace la puntuacion de cada personaje desde sus enfrentamientos.
 *
 * Lo mismo que el boton del panel, para cuando se prefiere la consola. El
 * trabajo lo hace el servicio: dos copias de esta logica acabarian dando
 * resultados distintos.
 */
class LadderRecalculateCommand extends Command
{
    protected $signature = 'ladder:recalcular {--dry-run : Solo enseña lo que cambiaria}';

    protected $description = 'Rehace PL, MMR y el historial de cada personaje desde sus enfrentamientos.';

    public function handle(LadderMaintenanceService $mantenimiento): int
    {
        $ensayo = (bool) $this->option('dry-run');
        $resumen = $mantenimiento->recalcularRanking($ensayo);

        foreach ($resumen['detalle'] as $fila) {
            $this->line(sprintf(
                '%-24s PL %s → %s   MMR %d → %d   %d/%d → %d/%d   partidas %d → %d',
                $fila['character_name'],
                $fila['antes']['pl_points'], $fila['despues']['pl_points'],
                $fila['antes']['mmr'], $fila['despues']['mmr'],
                $fila['antes']['wins'], $fila['antes']['losses'],
                $fila['despues']['wins'], $fila['despues']['losses'],
                $fila['antes']['matches_played'], $fila['despues']['matches_played']
            ));
        }

        if ($resumen['corregidos'] === 0) {
            $this->info('Todo cuadra en los ' . $resumen['revisados'] . ' personajes revisados.');

            return self::SUCCESS;
        }

        if ($ensayo) {
            $this->warn($resumen['corregidos'] . ' personaje(s) descuadrados. Quita --dry-run para arreglarlos.');

            return self::SUCCESS;
        }

        $this->info($resumen['corregidos'] . ' personaje(s) rehechos.');

        return self::SUCCESS;
    }
}
