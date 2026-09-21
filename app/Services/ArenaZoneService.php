<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\ArenaZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Las zonas del mapa: quien las guarda, quien las sirve y donde se queda.
 *
 * Una sola fuente de verdad -la tabla `arena_zones`- para las tres cosas que
 * antes no se hablaban entre si: el editor del panel, el mapa del navegador y
 * el emparejador, que necesita saber a que punto exacto manda a los dos bandos.
 */
class ArenaZoneService
{
    /** Vueltas de afinado de la busqueda del punto automatico. */
    private const VUELTAS = 5;

    /** En cuantas casillas se parte el lado mas largo en la primera vuelta. */
    private const REJILLA = 16;

    private ?Collection $enMemoria = null;

    /**
     * Todas las zonas, ordenadas por su numero.
     *
     * Se guardan en memoria durante la peticion: el mapa, el editor y el
     * emparejador las piden por separado y no tiene sentido ir tres veces a la
     * base por lo mismo.
     */
    public function todas(): Collection
    {
        if ($this->enMemoria !== null) {
            return $this->enMemoria;
        }

        if (!Schema::hasTable('arena_zones')) {
            return $this->enMemoria = collect();
        }

        return $this->enMemoria = ArenaZone::query()->orderBy('number')->get();
    }

    /** Olvida lo guardado en memoria. Lo usa el editor tras publicar. */
    public function olvidar(): void
    {
        $this->enMemoria = null;
    }

    public function buscar(?string $zoneKey): ?ArenaZone
    {
        $key = ArenaMatch::normalizeZoneKey($zoneKey);

        if ($key === null) {
            return null;
        }

        return $this->todas()->firstWhere('key', $key);
    }

    /**
     * La huella de la configuracion actual.
     *
     * Va en la URL del JS de zonas. Mientras no cambie, el navegador puede
     * quedarse con su copia para siempre; en cuanto el admin publica, la URL es
     * otra y todo el mundo recibe lo nuevo a la vez. Ese "a la vez" es el
     * arreglo: antes el admin veia su cambio y los jugadores seguian con el
     * punto de encuentro de la semana pasada.
     */
    public function sello(): string
    {
        $zonas = $this->todas();

        if ($zonas->isEmpty()) {
            return 'vacio';
        }

        $ultima = $zonas->max('updated_at');

        return substr(sha1($zonas->count() . '|' . ($ultima?->timestamp ?? 0)), 0, 12);
    }

    /**
     * La configuracion tal y como la espera el mapa del navegador.
     *
     * @return array<int, array<string, mixed>>
     */
    public function configuracionParaElMapa(): array
    {
        return $this->todas()
            ->map(fn (ArenaZone $zona) => $zona->paraElMapa())
            ->values()
            ->all();
    }

    /**
     * Elige a que punto de la zona van los dos bandos de un enfrentamiento.
     *
     * Si la zona tiene los dos puestos, sale uno al azar: esa es la gracia de
     * tener dos, que la misma zona no siempre se juegue en el mismo sitio. Con
     * uno solo, ese. Con ninguno, el automatico.
     *
     * Devuelve el punto YA RESUELTO en coordenadas, no una referencia, porque
     * se guarda en el enfrentamiento: si el admin mueve el punto mañana, la
     * gente que ya tiene cruce sigue yendo a donde quedo.
     *
     * @return array{slot: int, punto: array{0: float, 1: float}}|null
     */
    public function elegirPuntoDeEncuentro(?string $zoneKey): ?array
    {
        $zona = $this->buscar($zoneKey);

        if (!$zona instanceof ArenaZone || !$zona->tieneContorno()) {
            return null;
        }

        $fijados = $zona->puntosFijados();

        if ($fijados === []) {
            $automatico = $this->puntoAutomatico($zona->coords);

            return $automatico === null ? null : ['slot' => 1, 'punto' => $automatico];
        }

        return $fijados[array_rand($fijados)];
    }

    /**
     * El punto de una zona por su puesto, resuelto a coordenadas.
     *
     * @return array{0: float, 1: float}|null
     */
    public function puntoDelPuesto(?string $zoneKey, int $slot): ?array
    {
        $zona = $this->buscar($zoneKey);

        if (!$zona instanceof ArenaZone || !$zona->tieneContorno()) {
            return null;
        }

        $guardado = $slot === 2 ? $zona->meeting_b : $zona->meeting;

        if (ArenaZone::esUnPunto($guardado)) {
            return [(float) $guardado[0], (float) $guardado[1]];
        }

        return $slot === 1 ? $this->puntoAutomatico($zona->coords) : null;
    }

    /**
     * El sitio mas interior de un poligono.
     *
     * Mismo calculo que hace el mapa en el navegador, portado tal cual: una
     * busqueda por rejilla que se va afinando, quedandose siempre con el punto
     * que mas lejos queda de cualquier borde.
     *
     * No vale el centro de masas, que en una zona con forma de arco cae fuera
     * del propio terreno.
     *
     * @param  array<int, array{0: float, 1: float}>|null  $coords
     * @return array{0: float, 1: float}|null
     */
    public function puntoAutomatico(?array $coords): ?array
    {
        if (!is_array($coords) || count($coords) < 3) {
            return null;
        }

        $ys = array_map(fn ($c) => (float) $c[0], $coords);
        $xs = array_map(fn ($c) => (float) $c[1], $coords);

        $y0 = min($ys);
        $y1 = max($ys);
        $x0 = min($xs);
        $x1 = max($xs);

        $mejor = [($y0 + $y1) / 2, ($x0 + $x1) / 2];
        $mejorDistancia = -INF;
        $paso = max($y1 - $y0, $x1 - $x0) / self::REJILLA;

        // Una zona degenerada -todos los vertices en el mismo sitio- deja el
        // paso a cero, y un bucle que avanza de cero en cero no termina nunca.
        if (!($paso > 0)) {
            return $mejor;
        }

        for ($vuelta = 0; $vuelta < self::VUELTAS; $vuelta++) {
            $desdeY = $vuelta === 0 ? $y0 : $mejor[0] - $paso * 2;
            $hastaY = $vuelta === 0 ? $y1 : $mejor[0] + $paso * 2;
            $desdeX = $vuelta === 0 ? $x0 : $mejor[1] - $paso * 2;
            $hastaX = $vuelta === 0 ? $x1 : $mejor[1] + $paso * 2;

            for ($y = $desdeY; $y <= $hastaY; $y += $paso) {
                for ($x = $desdeX; $x <= $hastaX; $x += $paso) {
                    $d = $this->distanciaAlBorde([$y, $x], $coords);

                    if ($d > $mejorDistancia) {
                        $mejorDistancia = $d;
                        $mejor = [$y, $x];
                    }
                }
            }

            $paso /= 3;
        }

        return $mejor;
    }

    /**
     * Lo lejos que queda un punto del borde del poligono.
     *
     * Positivo dentro, negativo fuera. Sirve para las dos cosas: elegir el
     * punto mas interior, y avisar al admin cuando coloca uno fuera de su zona.
     *
     * @param  array{0: float, 1: float}  $punto
     * @param  array<int, array{0: float, 1: float}>  $coords
     */
    public function distanciaAlBorde(array $punto, array $coords): float
    {
        $dentro = false;
        $minimo = INF;
        $total = count($coords);

        for ($i = 0, $j = $total - 1; $i < $total; $j = $i++) {
            $ay = (float) $coords[$i][0];
            $ax = (float) $coords[$i][1];
            $by = (float) $coords[$j][0];
            $bx = (float) $coords[$j][1];

            if (($ay > $punto[0]) !== ($by > $punto[0])
                && ($by - $ay) != 0.0
                && $punto[1] < ($bx - $ax) * ($punto[0] - $ay) / ($by - $ay) + $ax) {
                $dentro = !$dentro;
            }

            $dy = $by - $ay;
            $dx = $bx - $ax;
            $largo = $dy * $dy + $dx * $dx;
            $t = $largo == 0.0 ? 0.0 : (($punto[0] - $ay) * $dy + ($punto[1] - $ax) * $dx) / $largo;
            $t = max(0.0, min(1.0, $t));
            $py = $ay + $t * $dy;
            $px = $ax + $t * $dx;
            $minimo = min($minimo, sqrt(($punto[0] - $py) ** 2 + ($punto[1] - $px) ** 2));
        }

        return $dentro ? $minimo : -$minimo;
    }

    /**
     * Guarda lo que publica el editor.
     *
     * @param  array<int, array<string, mixed>>  $zonas
     * @return int  cuantas se guardaron
     */
    public function publicar(array $zonas): int
    {
        $guardadas = 0;

        foreach ($zonas as $fila) {
            $key = ArenaMatch::normalizeZoneKey($fila['key'] ?? null);

            if ($key === null) {
                continue;
            }

            $datos = [
                'number' => (int) ($fila['number'] ?? ArenaMatch::ZONES[$key]['number'] ?? 0),
                'name' => (string) ($fila['name'] ?? ArenaMatch::zoneLabel($key) ?? $key),
                'coords' => $this->limpiarCoords($fila['coords'] ?? null),
                'meeting' => ArenaZone::esUnPunto($fila['meeting'] ?? null) ? $fila['meeting'] : null,
                'meeting_b' => ArenaZone::esUnPunto($fila['meeting_b'] ?? null) ? $fila['meeting_b'] : null,
            ];

            ArenaZone::query()->updateOrCreate(['key' => $key], $datos);
            $guardadas++;
        }

        $this->olvidar();

        return $guardadas;
    }

    /**
     * Deja fuera los vertices que no son un par de numeros.
     *
     * Un vertice a medias no revienta al guardar, revienta al dibujar, y para
     * entonces ya esta publicado para todo el mundo.
     *
     * @return array<int, array{0: float, 1: float}>|null
     */
    private function limpiarCoords(mixed $coords): ?array
    {
        if (!is_array($coords)) {
            return null;
        }

        $limpias = [];

        foreach ($coords as $vertice) {
            if (ArenaZone::esUnPunto($vertice)) {
                $limpias[] = [(float) $vertice[0], (float) $vertice[1]];
            }
        }

        return $limpias === [] ? null : $limpias;
    }
}
