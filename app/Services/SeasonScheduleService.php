<?php

namespace App\Services;

use App\Models\ArenaSeason;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * El reloj de las temporadas: cierra la que ya llego a su fecha.
 *
 * Corre en cada tick del mantenimiento (el cron y el respaldo por HTTP), asi que
 * tiene que ser barato cuando no hay nada que hacer -una sola consulta con
 * indice- y seguro cuando lo hay: dos ticks a la vez no pueden cerrar dos
 * temporadas. Eso lo garantiza SeasonClosingService con `esperada`.
 */
class SeasonScheduleService
{
    public function __construct(private readonly SeasonClosingService $cierre)
    {
    }

    /** La temporada abierta que ya debia haberse cerrado, si hay alguna. */
    public function vencida(): ?ArenaSeason
    {
        if (!$this->cierre->disponible() || !Schema::hasColumn('arena_seasons', 'auto_close')) {
            return null;
        }

        $actual = ArenaSeason::current();

        return $actual !== null && $actual->vencida() ? $actual : null;
    }

    /**
     * Cierra la temporada vencida y deja su podio en el Salon de la Fama.
     *
     * @return array{cerrada: bool, season?: ArenaSeason, siguiente?: ArenaSeason, congelados?: int, motivo?: string}
     */
    public function aplicar(): array
    {
        $vencida = $this->vencida();

        if ($vencida === null) {
            return ['cerrada' => false];
        }

        // forzar: una temporada con fecha de fin se cierra aunque nadie haya
        // jugado. El candado contra el doble clic es para cierres a mano; aqui
        // lo que manda es el calendario.
        $resultado = $this->cierre->cerrar(null, true, [
            'esperada' => $vencida->getKey(),
            'motivo' => 'auto',
        ]);

        if (!$resultado['ok']) {
            // Que otro tick la cerrara antes no es un fallo.
            if (empty($resultado['ya_cerrada'])) {
                Log::warning('No se pudo cerrar la temporada vencida', [
                    'season' => $vencida->getKey(),
                    'motivo' => $resultado['motivo'] ?? null,
                ]);
            }

            return ['cerrada' => false, 'motivo' => $resultado['motivo'] ?? null];
        }

        Log::info('Temporada cerrada por calendario', [
            'season' => $resultado['season']->name,
            'siguiente' => $resultado['siguiente']->name,
            'congelados' => $resultado['congelados'],
        ]);

        return [
            'cerrada' => true,
            'season' => $resultado['season'],
            'siguiente' => $resultado['siguiente'],
            'congelados' => $resultado['congelados'],
        ];
    }
}
