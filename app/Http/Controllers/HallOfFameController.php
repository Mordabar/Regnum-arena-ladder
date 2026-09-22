<?php

namespace App\Http\Controllers;

use App\Models\ArenaSeason;
use App\Models\SeasonPlayerStat;
use App\Services\SeasonPrizeService;
use Illuminate\Support\Facades\Schema;

/**
 * El Salon de la Fama: las temporadas que ya terminaron.
 *
 * Cada una con su podio congelado -las cifras del dia que se cerro, no las de
 * hoy- y con lo que repartio. Un ladder sin memoria empieza de cero cada
 * temporada y lo ganado no vale nada tres meses despues.
 */
class HallOfFameController extends Controller
{
    public function index(SeasonPrizeService $premios)
    {
        if (!Schema::hasTable('arena_seasons')) {
            return view('hall-of-fame.index', [
                'seasons' => collect(),
                'actual' => null,
                'podioActual' => collect(),
                'premios' => $premios,
            ]);
        }

        $seasons = ArenaSeason::query()
            ->where('status', ArenaSeason::STATUS_ARCHIVED)
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (ArenaSeason $season) {
                $season->setRelation('leaders', $this->podioDe($season));

                return $season;
            });

        return view('hall-of-fame.index', [
            'seasons' => $seasons,
            // La temporada en marcha tambien sale, arriba y marcada como tal:
            // quien entra a ver la vitrina quiere saber que hay en juego ahora.
            'actual' => ArenaSeason::current(),
            // Y quien va ganandola HOY, con las cifras vivas. Es la misma
            // vitrina contada hacia delante: lo que hoy es provisional se
            // congela tal cual el dia que la temporada se cierra.
            'podioActual' => $premios->podio(),
            'premios' => $premios,
        ]);
    }

    /**
     * El podio de una temporada, tal y como quedo.
     *
     * Se leen las cifras congeladas en season_player_stats, no las del jugador
     * hoy: si se leyeran las de hoy, el campeon de la temporada 0 cambiaria de
     * puntos cada vez que juega una partida en la 1.
     */
    private function podioDe(ArenaSeason $season)
    {
        if (!Schema::hasTable('season_player_stats')) {
            return collect();
        }

        return SeasonPlayerStat::query()
            ->with('player:id,character_name,realm,subclass,race,gender')
            ->where('season_id', $season->id)
            ->where('is_hall_eligible', true)
            ->where('matches_played', '>', 0)
            ->orderByDesc('pl_points')
            ->orderByDesc('mmr')
            ->orderByDesc('wins')
            ->orderBy('player_id')
            ->take(max(3, count($season->reparto())))
            ->get();
    }
}
