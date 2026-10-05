<?php

namespace App\Services;

use App\Models\AppSetting;
use Carbon\CarbonInterface;
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
        // Con las columnas del calendario: cerrar escribe closed_reason, y con el
        // codigo nuevo subido antes que las migraciones fallaria a medias.
        return Schema::hasTable('arena_seasons')
            && Schema::hasTable('season_player_stats')
            && Schema::hasColumn('arena_seasons', 'closed_reason');
    }

    /**
     * Cierra la temporada en curso y deja su podio en el Salon de la Fama.
     *
     * Opciones (todas opcionales; lo que falte se toma de la propia temporada):
     * - esperada: id de la temporada que se quiere cerrar. Si la abierta ya es
     *   otra, no se cierra nada: es lo que evita que dos ticks a la vez cierren
     *   la temporada y, acto seguido, la que acaba de nacer.
     * - motivo: 'manual' o 'auto', para el historial.
     * - duracion_dias: cuanto dura la siguiente; sin valor queda abierta sin fecha.
     * - resetear: poner el ranking a cero DESPUES de congelar el podio.
     * - premios_siguiente: false apaga el reparto de premios para la siguiente.
     *
     * @param  array{esperada?: int, motivo?: string, duracion_dias?: int|null, resetear?: bool, premios_siguiente?: bool}  $opciones
     * @return array{ok: bool, motivo?: string, season?: ArenaSeason, siguiente?: ArenaSeason, congelados?: int, reinicio?: array|null}
     */
    public function cerrar(?string $nombreSiguiente = null, bool $forzar = false, array $opciones = []): array
    {
        if (!$this->disponible()) {
            return ['ok' => false, 'motivo' => 'Las temporadas no estan disponibles en este esquema.'];
        }

        $premios = app(SeasonPrizeService::class);

        $resultado = DB::transaction(function () use ($premios, $nombreSiguiente, $forzar, $opciones) {
            // La temporada se elige DENTRO de la transaccion y con candado.
            // Cerrar deja otra abierta al instante, asi que dos peticiones a la
            // vez -o el doble clic de siempre- archivarian dos temporadas y la
            // segunda seria la que acaba de nacer: vacia, de un minuto y con el
            // podio en blanco, metida en el Salon de la Fama para siempre.
            //
            // El orden es el mismo que usa current() -la mas reciente por
            // starts_at- y eso importa: con otro criterio se cerraria una
            // temporada distinta de la que el resto del sitio da por viva, y la
            // viva se iria por el barrido de mas abajo, sin premios y sin
            // congelar. Eso no se puede deshacer.
            $actual = ArenaSeason::query()
                ->where('status', ArenaSeason::STATUS_ACTIVE)
                ->lockForUpdate()
                ->latest('starts_at')
                ->first();

            if (!$actual instanceof ArenaSeason) {
                return ['ok' => false, 'motivo' => 'No hay ninguna temporada abierta que cerrar.'];
            }

            if (isset($opciones['esperada']) && (int) $opciones['esperada'] !== (int) $actual->getKey()) {
                return ['ok' => false, 'motivo' => 'Esa temporada ya se habia cerrado.', 'ya_cerrada' => true];
            }

            if (!$forzar && $this->reciennacidaYSinJugar($actual)) {
                return [
                    'ok' => false,
                    'motivo' => 'Esa temporada acaba de abrirse y no se ha jugado nada en ella: cerrarla la dejaria en el Salon de la Fama con el podio en blanco. Marca la casilla de cerrarla igualmente si es lo que quieres.',
                    'necesita_forzar' => true,
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

            // Si se cierra porque llego su fecha, la temporada acabo ese dia y no
            // el minuto en que el cron se dio cuenta; si se cierra antes de
            // tiempo, acabo ahora. El Salon de la Fama ordena por esta fecha.
            $fin = $actual->ends_at !== null && $actual->ends_at->lte(now()) ? $actual->ends_at : now();

            $actual->update([
                'status' => ArenaSeason::STATUS_ARCHIVED,
                'ends_at' => $fin,
                'closed_reason' => $opciones['motivo'] ?? 'manual',
                // El reparto se guarda CON la temporada. Si el Salon lo leyera
                // de los ajustes, la temporada 0 diria lo que reparte la 3.
                // Si los premios estaban apagados no se repartio nada, y la
                // vitrina no puede decir que si.
                'prizes' => $premios->activos() ? $premios->reparto() : [],
                'prize_currency' => $premios->moneda(),
            ]);

            $siguiente = $this->abrirSiguiente($actual, $nombreSiguiente, $opciones);

            return [
                'ok' => true,
                'season' => $actual->refresh(),
                'siguiente' => $siguiente,
                'congelados' => $congelados,
            ];
        });

        if (!$resultado['ok']) {
            return $resultado;
        }

        // Lo que toca el ranking vivo va FUERA de la transaccion y DESPUES de
        // ella: el podio ya esta congelado y confirmado, asi que si el reinicio
        // falla la vitrina sigue intacta.
        $resultado['reinicio'] = null;

        if ($opciones['resetear'] ?? $resultado['season']->reset_on_close) {
            $resultado['reinicio'] = app(LadderMaintenanceService::class)->reiniciarRanking();
        }

        if (!($opciones['premios_siguiente'] ?? $resultado['season']->next_prizes_enabled)) {
            AppSetting::setValue('season_prizes_enabled', '0', 'branding', 'boolean', true);
        }

        return $resultado;
    }

    /**
     * Abre una temporada cuando no hay ninguna abierta (la primera, o despues de
     * haber cerrado una a mano sin sucesora). Con una abierta no hace nada: dos
     * temporadas vivas a la vez es justo lo que el resto del codigo no espera.
     *
     * @return array{ok: bool, motivo?: string, season?: ArenaSeason}
     */
    public function abrirNueva(string $nombre, ?CarbonInterface $inicio = null, ?CarbonInterface $fin = null, bool $cierreAutomatico = false): array
    {
        if (!$this->disponible()) {
            return ['ok' => false, 'motivo' => 'Las temporadas no estan disponibles en este esquema.'];
        }

        return DB::transaction(function () use ($nombre, $inicio, $fin, $cierreAutomatico) {
            if (ArenaSeason::query()->where('status', ArenaSeason::STATUS_ACTIVE)->lockForUpdate()->exists()) {
                return ['ok' => false, 'motivo' => 'Ya hay una temporada abierta. Cierrala antes de abrir otra.'];
            }

            $nombre = trim($nombre) !== '' ? trim($nombre) : 'Temporada ' . (ArenaSeason::query()->count() + 1);

            return ['ok' => true, 'season' => ArenaSeason::create([
                'name' => $nombre,
                'slug' => $this->slugLibre($nombre),
                'status' => ArenaSeason::STATUS_ACTIVE,
                'enabled_modes' => ArenaMode::enabled(),
                'starts_at' => $inicio ?? now(),
                'ends_at' => $fin,
                'auto_close' => $fin !== null && $cierreAutomatico,
            ])];
        });
    }

    /** Minutos que se considera "recien abierta" a una temporada. */
    private const GRACIA_MINUTOS = 5;

    /**
     * Una temporada que acaba de abrirse y en la que no se ha jugado nada.
     *
     * Es lo que deja el doble clic del boton: cerrar archiva y abre otra en el
     * acto, asi que el segundo clic cae sobre una temporada de un segundo de
     * vida y la mete en el Salon de la Fama con el podio en blanco. Eso no se
     * deshace.
     *
     * Se miran las DOS cosas, y no solo el reloj. Que sea reciente, por si
     * acaso; y que no tenga ni un cruce ni una cifra congelada, que es lo que
     * de verdad hace que cerrarla sea un error: una temporada corta pero
     * jugada se cierra sin protestar. Y por si aun asi el admin quiere cerrar
     * una vacia a proposito, `cerrar()` acepta forzarlo.
     */
    private function reciennacidaYSinJugar(ArenaSeason $season): bool
    {
        if ($season->starts_at === null || $season->starts_at->lt(now()->subMinutes(self::GRACIA_MINUTOS))) {
            return false;
        }

        // Con candado, igual que la lectura de la temporada. No por el bloqueo
        // en si, sino para no depender de que InnoDB fije el read view en la
        // primera lectura consistente: eso es cierto, pero es un detalle de
        // implementacion que un reordenamiento inocente rompe en silencio. Y
        // ojo: en SQLite el candado no se emite -el compilador lo ignora-, asi
        // que nada de esto lo cubren los tests.
        $tieneCruces = Schema::hasTable('matches')
            && DB::table('matches')->where('season_id', $season->id)->lockForUpdate()->exists();

        if ($tieneCruces) {
            return false;
        }

        return !SeasonPlayerStat::query()->where('season_id', $season->id)->exists();
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
    private function abrirSiguiente(ArenaSeason $anterior, ?string $nombre, array $opciones = []): ArenaSeason
    {
        $nombre = trim((string) $nombre);

        if ($nombre === '') {
            $nombre = trim((string) $anterior->next_name);
        }

        if ($nombre === '') {
            $nombre = $this->nombrePorDefecto($anterior);
        }

        $dias = array_key_exists('duracion_dias', $opciones)
            ? $opciones['duracion_dias']
            : $anterior->next_duration_days;
        $dias = $dias !== null && (int) $dias > 0 ? (int) $dias : null;

        return ArenaSeason::create([
            'name' => $nombre,
            'slug' => $this->slugLibre($nombre),
            'status' => ArenaSeason::STATUS_ACTIVE,
            'enabled_modes' => $anterior->enabledModes() ?: ArenaMode::enabled(),
            'starts_at' => now(),
            // Con duracion, la siguiente nace con su calendario y se cierra
            // sola; sin ella queda abierta hasta que alguien la cierre. El
            // nombre elegido era para esta vez, pero lo demas se hereda: asi
            // una liga de temporadas de 60 dias se mantiene sola.
            'ends_at' => $dias !== null ? now()->addDays($dias) : null,
            'auto_close' => $dias !== null,
            'next_duration_days' => $dias,
            'reset_on_close' => (bool) ($opciones['resetear'] ?? $anterior->reset_on_close),
            // No se hereda: "apagar los premios al cerrar la 0" es una decision
            // para ese cierre. Si el admin los vuelve a encender, el siguiente
            // cierre no debe apagarlos sin que nadie lo pida.
            'next_prizes_enabled' => (bool) ($opciones['premios_siguiente'] ?? true),
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
