<?php

namespace App\Services;

use App\Models\ArenaSeason;
use App\Models\Player;
use App\Models\SeasonPlayerStat;
use App\Support\ArenaMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Cerrar una temporada y abrir la siguiente.
 *
 * Cerrar no es cambiar un estado: es congelar. Las cifras de cada jugador se
 * copian tal y como estan ese dia, porque el Salon de la Fama tiene que poder
 * decir con cuantos puntos se gano la temporada 0 dentro de dos años, y para
 * entonces esos jugadores habran seguido jugando.
 *
 * Lo que NO hace: tocar el ranking vivo. Poner a cero a todo el mundo es otra
 * decision, con su propio boton, y mezclarlas aqui haria que cerrar la vitrina
 * borrase el ladder sin avisar.
 */
class SeasonClosingService
{
    public function disponible(): bool
    {
        return Schema::hasTable('arena_seasons') && Schema::hasTable('season_player_stats');
    }

    /**
     * Cierra la temporada en curso y deja su podio en el Salon de la Fama.
     *
     * @return array{ok: bool, motivo?: string, season?: ArenaSeason, congelados?: int}
     */
    public function cerrar(?string $nombreSiguiente = null): array
    {
        if (!$this->disponible()) {
            return ['ok' => false, 'motivo' => 'Las temporadas no estan disponibles en este esquema.'];
        }

        $actual = ArenaSeason::current();

        if (!$actual instanceof ArenaSeason) {
            return ['ok' => false, 'motivo' => 'No hay ninguna temporada abierta que cerrar.'];
        }

        $premios = app(SeasonPrizeService::class);

        return DB::transaction(function () use ($actual, $premios, $nombreSiguiente) {
            $congelados = $this->congelarCifras($actual);

            // Cualquier otra que hubiera quedado abierta se archiva tambien.
            // current() devuelve la mas reciente, asi que con dos activas
            // cerrar la temporada dejaria la otra viva y escondida, y el
            // siguiente cierre archivaria la nueva en vez de aquella.
            ArenaSeason::query()
                ->where('status', ArenaSeason::STATUS_ACTIVE)
                ->whereKeyNot($actual->getKey())
                ->update(['status' => ArenaSeason::STATUS_ARCHIVED, 'ends_at' => now()]);

            $actual->update([
                'status' => ArenaSeason::STATUS_ARCHIVED,
                'ends_at' => now(),
                // El reparto se guarda CON la temporada. Si el Salon lo leyera
                // de los ajustes, la temporada 0 diria lo que reparte la 3.
                'prizes' => $premios->reparto(),
                'prize_currency' => $premios->moneda(),
            ]);

            $siguiente = $this->abrirSiguiente($actual, $nombreSiguiente);

            return [
                'ok' => true,
                'season' => $actual->refresh(),
                'siguiente' => $siguiente,
                'congelados' => $congelados,
            ];
        });
    }

    /**
     * Copia las cifras de cada jugador activo a la temporada que se cierra.
     *
     * Si ya habia filas -porque el sistema las fuera escribiendo durante la
     * temporada- se actualizan en vez de duplicarse.
     */
    private function congelarCifras(ArenaSeason $season): int
    {
        $congelados = 0;

        Player::query()
            ->where('matches_played', '>', 0)
            ->orderBy('id')
            ->chunk(300, function ($jugadores) use ($season, &$congelados) {
                foreach ($jugadores as $player) {
                    SeasonPlayerStat::updateOrCreate(
                        ['season_id' => $season->id, 'player_id' => $player->id],
                        [
                            'character_name' => (string) $player->character_name,
                            'realm' => (string) $player->realm,
                            'subclass' => (string) $player->subclass,
                            // Quien esta sancionado no entra en la vitrina.
                            'is_hall_eligible' => (bool) $player->is_active,
                            'pl_points' => (float) $player->pl_points,
                            'mmr' => (int) $player->mmr,
                            'matches_played' => (int) $player->matches_played,
                            'wins' => (int) $player->wins,
                            'losses' => (int) $player->losses,
                        ]
                    );

                    $congelados++;
                }
            });

        return $congelados;
    }

    /**
     * Abre la temporada siguiente.
     *
     * Hereda las modalidades de la anterior: cerrar una temporada no puede
     * dejar la arena sin modos y a todo el mundo fuera de la cola.
     */
    private function abrirSiguiente(ArenaSeason $anterior, ?string $nombre): ArenaSeason
    {
        $nombre = trim((string) $nombre);

        if ($nombre === '') {
            $nombre = $this->nombrePorDefecto($anterior);
        }

        return ArenaSeason::create([
            'name' => $nombre,
            'slug' => $this->slugLibre($nombre),
            'status' => ArenaSeason::STATUS_ACTIVE,
            'enabled_modes' => $anterior->enabledModes() ?: ArenaMode::enabled(),
            'starts_at' => now(),
        ]);
    }

    /** "Season 2" despues de "Season 1", y si no se entiende, la fecha. */
    private function nombrePorDefecto(ArenaSeason $anterior): string
    {
        if (preg_match('/^(.*?)(\d+)\s*$/u', (string) $anterior->name, $partes)) {
            return trim($partes[1]) . ' ' . ((int) $partes[2] + 1);
        }

        return 'Temporada ' . now()->format('Y-m');
    }

    private function slugLibre(string $nombre): string
    {
        $base = Str::slug($nombre) ?: 'temporada';
        $slug = $base;
        $n = 2;

        while (ArenaSeason::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
