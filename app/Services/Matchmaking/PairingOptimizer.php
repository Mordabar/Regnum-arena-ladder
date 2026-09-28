<?php

namespace App\Services\Matchmaking;

/**
 * Mejora local de un reparto de cruces ya hecho: intercambios, rotaciones de
 * tres y aprovechar a los sueltos, mientras el reparto gane.
 *
 * Parte del emparejador (antes todo vivia en ArenaMatchmakingService).
 */
class PairingOptimizer
{
    /**
     * Tope de pasadas de mejora por intercambio.
     *
     * En la practica converge en dos o tres. El tope existe para que la mejora
     * no pueda convertirse en el problema de rendimiento que venia a arreglar.
     */
    private const PAIRING_IMPROVEMENT_PASSES = 6;

    /**
     * Cuantos cruces vecinos mira cada cruce al buscar un intercambio.
     *
     * Mismo motivo que la ventana de MMR del barrido: dos cruces solo se
     * mejoran intercambiando rivales si los cuatro andan por el mismo nivel.
     * Veinte vecinos cubren de sobra los intercambios que de verdad salen, y
     * es la diferencia entre que la cola llena tarde dos segundos o un minuto.
     */
    public const PAIRING_SWAP_WINDOW = 20;

    /** Cuantos de los peores cruces se intentan desatascar moviendo tres. */
    private const PAIRING_ROTATION_SEEDS = 12;

    /** Cuantos vecinos de nivel entran en esas rotaciones. */
    private const PAIRING_ROTATION_WINDOW = 4;

    /**
     * Las quince formas de repartir seis equipos en tres cruces.
     *
     * Se usan para desatascar los repartos que no mejoran tocando dos cruces
     * pero si tocando tres. Son todas, no una seleccion: con seis elementos
     * caben quince emparejamientos y probarlos es barato.
     */
    private const ROTACIONES_DE_TRES = [
        [[0, 1], [2, 3], [4, 5]], [[0, 1], [2, 4], [3, 5]], [[0, 1], [2, 5], [3, 4]],
        [[0, 2], [1, 3], [4, 5]], [[0, 2], [1, 4], [3, 5]], [[0, 2], [1, 5], [3, 4]],
        [[0, 3], [1, 2], [4, 5]], [[0, 3], [1, 4], [2, 5]], [[0, 3], [1, 5], [2, 4]],
        [[0, 4], [1, 2], [3, 5]], [[0, 4], [1, 3], [2, 5]], [[0, 4], [1, 5], [2, 3]],
        [[0, 5], [1, 2], [3, 4]], [[0, 5], [1, 3], [2, 4]], [[0, 5], [1, 4], [2, 3]],
    ];

    /**
     * Cambia a un emparejado por alguien que se quedo suelto, si mejora.
     *
     * Cuando no caben partidas para todos -tres reinos descompensados, por
     * ejemplo- siempre sobra gente, y quien sobra no tiene por que ser el que
     * peor encajaba. Este paso mira, para cada suelto, si entrando el en algun
     * cruce cercano ese cruce queda mejor, y en ese caso se cambian los papeles:
     * el suelto juega y el otro pasa al banquillo. El numero de partidas no
     * cambia; cambia quien las juega y lo bien que encajan.
     *
     * Hace falta porque el intercambio entre dos cruces no llega aqui. Caso
     * real: cinco Alsius, dos Ignis y un Syrtis, donde solo caben tres
     * partidas. El reparto quedaba en 338 puntos de MMR y el mejor posible era
     * 242; ninguna permuta entre los tres cruces lo arreglaba, porque la mejora
     * pasaba por sacar a un Alsius de 857 y meter al de 1171, que estaba
     * sentado. Con este paso y el de intercambios despues, sale el 242 exacto.
     *
     * @param  array<int, int|null>  $pareja
     */
    public function mejorarConSueltos(array &$pareja, array $teams, callable $puntuar): bool
    {
        $total = count($teams);
        $sueltos = [];
        $cruces = [];

        for ($i = 0; $i < $total; $i++) {
            if ($pareja[$i] === null) {
                $sueltos[] = $i;
            } elseif ($i < $pareja[$i]) {
                $cruces[] = [$i, $pareja[$i]];
            }
        }

        if ($sueltos === [] || $cruces === []) {
            return false;
        }

        usort($cruces, function (array $a, array $b) use ($teams): int {
            return $this->mmrDelCruce($teams, $a) <=> $this->mmrDelCruce($teams, $b);
        });

        $nivelDelCruce = [];

        foreach ($cruces as $posicion => $cruce) {
            $nivelDelCruce[$posicion] = $this->mmrDelCruce($teams, $cruce);
        }

        $numero = count($cruces);
        $hubo = false;

        foreach ($sueltos as $u) {
            $nivel = (int) $teams[$u]['avg_mmr'];

            $bajo = 0;
            $alto = $numero - 1;

            while ($bajo < $alto) {
                $medio = intdiv($bajo + $alto, 2);

                if ($nivelDelCruce[$medio] < $nivel) {
                    $bajo = $medio + 1;
                } else {
                    $alto = $medio;
                }
            }

            $desde = max(0, $bajo - self::PAIRING_SWAP_WINDOW);
            $hasta = min($numero - 1, $bajo + self::PAIRING_SWAP_WINDOW);
            $mejor = null;

            for ($posicion = $desde; $posicion <= $hasta; $posicion++) {
                [$a, $b] = $cruces[$posicion];

                // El cruce pudo cambiar en una vuelta anterior de este mismo
                // bucle; si ya no es el que era, se deja para la siguiente.
                if ($pareja[$a] !== $b) {
                    continue;
                }

                $actual = $puntuar($a, $b)['score'];

                foreach ([[$a, $b], [$b, $a]] as [$sale, $queda]) {
                    $nuevo = $puntuar($u, $queda);

                    if ($nuevo === null || $nuevo['score'] >= $actual) {
                        continue;
                    }

                    if ($mejor === null || $nuevo['score'] - $actual < $mejor['ganancia']) {
                        $mejor = [
                            'ganancia' => $nuevo['score'] - $actual,
                            'posicion' => $posicion,
                            'sale' => $sale,
                            'queda' => $queda,
                        ];
                    }
                }
            }

            if ($mejor === null) {
                continue;
            }

            $pareja[$mejor['sale']] = null;
            $pareja[$u] = $mejor['queda'];
            $pareja[$mejor['queda']] = $u;
            $cruces[$mejor['posicion']] = $u < $mejor['queda'] ? [$u, $mejor['queda']] : [$mejor['queda'], $u];
            $hubo = true;
        }

        return $hubo;
    }

    /**
     * Reparte de nuevo tres cruces a la vez cuando tocando dos no se mejora.
     *
     * Hay repartos que estan atascados: ningun intercambio entre dos cruces los
     * mejora, y sin embargo moviendo tres a la vez salen bastante mejor. Son
     * pocos -dos de cada cien colas- pero cuando pasa el sobrecoste es grande,
     * del orden de cincuenta puntos de MMR por partida, y se lo comen personas
     * concretas.
     *
     * No se prueban todos los tercetos, que serian demasiados: solo los que
     * incluyen alguno de los PEORES cruces del reparto, porque un terceto de
     * cruces ya ajustados no tiene nada que ganar. Con eso el coste no depende
     * del tamaño de la cola.
     *
     * @param  array<int, int|null>  $pareja
     */
    public function mejorarPorRotaciones(array &$pareja, array $teams, callable $puntuar): bool
    {
        $total = count($teams);
        $cruces = [];

        for ($i = 0; $i < $total; $i++) {
            if ($pareja[$i] !== null && $i < $pareja[$i]) {
                $cruces[] = [$i, $pareja[$i]];
            }
        }

        $numero = count($cruces);

        if ($numero < 3) {
            return false;
        }

        // Ordenados por nivel, para que los vecinos de un cruce sean los que de
        // verdad podrian intercambiarse con el.
        usort($cruces, function (array $a, array $b) use ($teams): int {
            return $this->mmrDelCruce($teams, $a) <=> $this->mmrDelCruce($teams, $b);
        });

        // Los peores primero: son los unicos que tienen algo que ganar.
        $porLoMalos = range(0, $numero - 1);

        usort($porLoMalos, function (int $a, int $b) use ($cruces, $puntuar): int {
            return $puntuar($cruces[$b][0], $cruces[$b][1])['score']
                <=> $puntuar($cruces[$a][0], $cruces[$a][1])['score'];
        });

        $hubo = false;

        foreach (array_slice($porLoMalos, 0, self::PAIRING_ROTATION_SEEDS) as $centro) {
            $desde = max(0, $centro - self::PAIRING_ROTATION_WINDOW);
            $hasta = min($numero - 1, $centro + self::PAIRING_ROTATION_WINDOW);

            for ($x = $desde; $x <= $hasta; $x++) {
                for ($y = $x + 1; $y <= $hasta; $y++) {
                    // El terceto es el cruce malo mas otros dos distintos.
                    if ($x === $centro || $y === $centro) {
                        continue;
                    }

                    if ($this->rotarTres($pareja, $cruces, [$centro, $x, $y], $puntuar)) {
                        $hubo = true;
                    }
                }
            }
        }

        return $hubo;
    }

    /**
     * Prueba las quince reparticiones de tres cruces y se queda con la mejor.
     *
     * @param  array<int, int|null>  $pareja
     * @param  list<array{0: int, 1: int}>  $cruces
     * @param  list<int>  $posiciones
     */
    private function rotarTres(array &$pareja, array &$cruces, array $posiciones, callable $puntuar): bool
    {
        $equipos = [];

        foreach ($posiciones as $posicion) {
            $equipos[] = $cruces[$posicion][0];
            $equipos[] = $cruces[$posicion][1];
        }

        // Si alguno de los tres cruces ya se movio en otra rotacion, se deja.
        foreach ($posiciones as $posicion) {
            if ($pareja[$cruces[$posicion][0]] !== $cruces[$posicion][1]) {
                return false;
            }
        }

        $actual = null;
        $mejor = null;

        foreach (self::ROTACIONES_DE_TRES as $reparto) {
            $puntos = [];

            foreach ($reparto as [$uno, $otro]) {
                $valor = $puntuar($equipos[$uno], $equipos[$otro]);

                if ($valor === null) {
                    continue 2;
                }

                $puntos[] = $valor['score'];
            }

            $valor = [array_sum($puntos), max($puntos)];

            // La primera de la lista es el reparto que ya esta puesto.
            if ($reparto === self::ROTACIONES_DE_TRES[0]) {
                $actual = $valor;
            }

            if ($mejor === null || $valor < $mejor['valor']) {
                $mejor = ['valor' => $valor, 'reparto' => $reparto];
            }
        }

        if ($actual === null || $mejor === null || $mejor['valor'] >= $actual) {
            return false;
        }

        foreach ($mejor['reparto'] as $indice => [$uno, $otro]) {
            $a = $equipos[$uno];
            $b = $equipos[$otro];
            $pareja[$a] = $b;
            $pareja[$b] = $a;
            $cruces[$posiciones[$indice]] = $a < $b ? [$a, $b] : [$b, $a];
        }

        return true;
    }

    /**
     * Mejora el reparto intercambiando rivales entre dos cruces ya hechos.
     *
     * Con los cruces (a-b) y (c-d) sobre la mesa, se prueban las otras dos
     * formas de repartir a esos cuatro -(a-c, b-d) y (a-d, b-c)- y se acepta la
     * que deje el conjunto mejor. "Mejor" son dos cosas en este orden: que baje
     * la suma de las puntuaciones, y a igualdad, que baje el peor cruce de los
     * dos. Lo segundo importa porque la suma sola tolera un cruce horrible si
     * el otro compensa, y el cruce horrible se lo come una persona.
     *
     * Se repite mientras algo mejore, con un tope de pasadas para que esto no
     * pueda convertirse en el problema de rendimiento que venia a arreglar.
     *
     * @param  array<int, int|null>  $pareja
     */
    public function mejorarPorIntercambios(array &$pareja, array $teams, callable $puntuar): bool
    {
        $total = count($teams);
        $cruces = [];

        for ($i = 0; $i < $total; $i++) {
            if ($pareja[$i] !== null && $i < $pareja[$i]) {
                $cruces[] = [$i, $pareja[$i]];
            }
        }

        $numero = count($cruces);

        if ($numero < 2) {
            return false;
        }

        // Ordenados por el MMR medio del cruce. Intercambiar rivales entre dos
        // cruces solo puede mejorar algo si los cuatro andan por el mismo nivel:
        // cambiar al de 1900 por el de 800 no arregla nada. Ordenar permite
        // mirar solo los vecinos, que es lo que hace que esto siga siendo barato
        // con la cola llena -mirarlos todos contra todos eran mas de sesenta mil
        // comprobaciones con 360 cruces, y ahi se iban los segundos-.
        usort($cruces, function (array $a, array $b) use ($teams): int {
            return $this->mmrDelCruce($teams, $a) <=> $this->mmrDelCruce($teams, $b);
        });

        $puntos = static function (?array $p): ?int {
            return $p === null ? null : $p['score'];
        };

        for ($pasada = 0; $pasada < self::PAIRING_IMPROVEMENT_PASSES; $pasada++) {
            $cambio = false;

            for ($x = 0; $x < $numero - 1; $x++) {
                $hasta = min($numero - 1, $x + self::PAIRING_SWAP_WINDOW);

                for ($y = $x + 1; $y <= $hasta; $y++) {
                    [$a, $b] = $cruces[$x];
                    [$c, $d] = $cruces[$y];

                    $actualA = $puntos($puntuar($a, $b));
                    $actualB = $puntos($puntuar($c, $d));

                    // Los dos cruces existen, asi que sus puntuaciones tambien.
                    $actual = [$actualA + $actualB, max($actualA, $actualB)];

                    $mejor = null;

                    foreach ([[[$a, $c], [$b, $d]], [[$a, $d], [$b, $c]]] as $opcion) {
                        $uno = $puntos($puntuar($opcion[0][0], $opcion[0][1]));
                        $otro = $puntos($puntuar($opcion[1][0], $opcion[1][1]));

                        // Una de las dos reparticiones puede ser ilegal -mismo
                        // reino, misma cuenta, otra modalidad-. Se descarta.
                        if ($uno === null || $otro === null) {
                            continue;
                        }

                        $valor = [$uno + $otro, max($uno, $otro)];

                        if ($valor < $actual && ($mejor === null || $valor < $mejor['valor'])) {
                            $mejor = ['valor' => $valor, 'opcion' => $opcion];
                        }
                    }

                    if ($mejor === null) {
                        continue;
                    }

                    [[$n1, $n2], [$n3, $n4]] = $mejor['opcion'];

                    $pareja[$n1] = $n2;
                    $pareja[$n2] = $n1;
                    $pareja[$n3] = $n4;
                    $pareja[$n4] = $n3;

                    $cruces[$x] = $n1 < $n2 ? [$n1, $n2] : [$n2, $n1];
                    $cruces[$y] = $n3 < $n4 ? [$n3, $n4] : [$n4, $n3];

                    $cambio = true;
                }
            }

            if (!$cambio) {
                return $pasada > 0;
            }
        }

        return true;
    }

    /** El MMR medio de los dos lados de un cruce. */
    public function mmrDelCruce(array $teams, array $cruce): int
    {
        return (int) (((int) $teams[$cruce[0]]['avg_mmr'] + (int) $teams[$cruce[1]]['avg_mmr']) / 2);
    }
}
