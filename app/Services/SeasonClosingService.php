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

        if (!ArenaSeason::current() instanceof ArenaSeason) {
            return ['ok' => false, 'motivo' => 'No hay ninguna temporada abierta que cerrar.'];
        }

        $premios = app(SeasonPrizeService::class);

        return DB::transaction(function () use ($premios, $nombreSiguiente) {
            // La temporada se elige DENTRO de la transaccion y con candado.
            // Cerrar deja otra abierta al instante, asi que dos peticiones a la
            // vez -o el doble clic de siempre- archivarian dos temporadas y la
            // segunda seria la que acaba de nacer: vacia, de un minuto y con el
            // podio en blanco, metida en el Salon de la Fama para siempre.
            $actual = ArenaSeason::query()
                ->where('status', ArenaSeason::STATUS_ACTIVE)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (!$actual instanceof ArenaSeason) {
                return ['ok' => false, 'motivo' => 'No hay ninguna temporada abierta que cerrar.'];
            }

            if ($this->vieneDeUnCierreRecien($actual)) {
                return [
                    'ok' => false,
                    'motivo' => 'Acabas de cerrar una temporada. La que esta abierta nacio hace un momento: si de verdad quieres cerrarla tambien, espera unos minutos.',
                ];
            }

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

    /** Minutos que un cierre "protege" a la temporada que acaba de abrir. */
    private const GRACIA_MINUTOS = 5;

    /**
     * Si esta temporada es la que abrio un cierre de hace un momento.
     *
     * Es el doble clic de siempre: cerrar archiva y abre otra en el acto, asi
     * que el segundo clic cae sobre una temporada de un segundo de vida y la
     * mete en el Salon de la Fama con el podio en blanco, para siempre.
     *
     * Lo que se mira NO es si la temporada es reciente -una temporada corta y
     * legitima tiene que poder cerrarse igual- sino si viene detras de un
     * cierre recien hecho, que es lo unico que el doble clic produce.
     */
    private function vieneDeUnCierreRecien(ArenaSeason $season): bool
    {
        if ($season->starts_at === null || $season->starts_at->lt(now()->subMinutes(self::GRACIA_MINUTOS))) {
            return false;
        }

        return ArenaSeason::query()
            ->where('status', ArenaSeason::STATUS_ARCHIVED)
            ->whereKeyNot($season->getKey())
            ->where('ends_at', '>=', now()->subMinutes(self::GRACIA_MINUTOS))
            ->exists();
    }

    /**
     * Copia las cifras de cada jugador activo a la temporada que se cierra.
     *
     * Si ya habia filas -porque el sistema las fuera escribiendo durante la
     * temporada- se actualizan en vez de duplicarse.
     *
     * Va por lotes y con una sola escritura por lote. Fila a fila serian dos
     * consultas por jugador, y con cinco mil jugadores eso son diez mil viajes
     * a MySQL dentro de una peticion HTTP de hosting compartido: el cierre se
     * quedaria a medias por tiempo agotado, con la transaccion abierta.
     */
    private function congelarCifras(ArenaSeason $season): int
    {
        $congelados = 0;
        $ahora = now();

        Player::query()
            ->where('matches_played', '>', 0)
            ->orderBy('id')
            ->select([
                'id', 'character_name', 'realm', 'subclass', 'is_active',
                'pl_points', 'mmr', 'matches_played', 'wins', 'losses',
            ])
            ->chunk(500, function ($jugadores) use ($season, $ahora, &$congelados) {
                $filas = $jugadores->map(fn (Player $player) => [
                    'season_id' => $season->id,
                    'player_id' => $player->id,
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
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ])->all();

                if ($filas === []) {
                    return;
                }

                SeasonPlayerStat::query()->upsert(
                    $filas,
                    ['season_id', 'player_id'],
                    [
                        'character_name', 'realm', 'subclass', 'is_hall_eligible',
                        'pl_points', 'mmr', 'matches_played', 'wins', 'losses', 'updated_at',
                    ]
                );

                $congelados += count($filas);
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

    /** "Season 2" despues de "Season 1", y si no se entiende, por numero. */
    private function nombrePorDefecto(ArenaSeason $anterior): string
    {
        if (preg_match('/^(.*?)(\d+)\s*$/u', (string) $anterior->name, $partes)) {
            return trim($partes[1]) . ' ' . ((int) $partes[2] + 1);
        }

        // Nada de la fecha: 'Temporada ' . now()->format('Y-m') sale como
        // "Temporada 2026-09", y al cierre siguiente esa misma regla lee el
        // "09" final y propone "Temporada 2026- 10".
        return 'Temporada ' . (ArenaSeason::query()->count() + 1);
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
