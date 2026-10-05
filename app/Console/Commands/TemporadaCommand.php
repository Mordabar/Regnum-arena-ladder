<?php

namespace App\Console\Commands;

use App\Models\ArenaSeason;
use App\Services\SeasonScheduleService;
use Illuminate\Console\Command;

class TemporadaCommand extends Command
{
    protected $signature = 'arena:temporada {--cerrar-vencida : Cierra la temporada si ya llego a su fecha de fin}';

    protected $description = 'Muestra el calendario de la temporada en curso y, si se pide, cierra la que ya vencio.';

    public function handle(SeasonScheduleService $reloj): int
    {
        $actual = ArenaSeason::current();

        if ($actual === null) {
            $this->warn('No hay ninguna temporada abierta.');

            return self::SUCCESS;
        }

        $progreso = $actual->progreso();
        $this->line('Temporada: ' . $actual->name);

        if ($progreso === null) {
            $this->line('Sin fecha de fin: queda abierta hasta que se cierre a mano.');
        } else {
            $this->line('Inicio:    ' . $progreso['inicio']->format('Y-m-d H:i') . ' (' . ArenaSeason::zone() . ')');
            $this->line('Fin:       ' . $progreso['fin']->format('Y-m-d H:i'));
            $this->line(sprintf('Progreso:  %s%% (dia %d de %d) · %s', $progreso['porcentaje'], $progreso['dia'], $progreso['dias'], $progreso['restante']));
            $this->line('Cierre automatico: ' . ($actual->auto_close ? 'si' : 'no'));
        }

        if (!$this->option('cerrar-vencida')) {
            if ($reloj->vencida() !== null) {
                $this->warn('Esta temporada ya vencio. Ejecuta con --cerrar-vencida para cerrarla ahora.');
            }

            return self::SUCCESS;
        }

        $resultado = $reloj->aplicar();

        if (!$resultado['cerrada']) {
            $this->info('No hay nada que cerrar.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s cerrada con %d personaje(s) en la vitrina. Abierta: %s.',
            $resultado['season']->name,
            $resultado['congelados'],
            $resultado['siguiente']->name
        ));

        return self::SUCCESS;
    }
}
