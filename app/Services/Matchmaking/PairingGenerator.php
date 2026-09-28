<?php

namespace App\Services\Matchmaking;

use App\Models\Queue;
use Illuminate\Support\Collection;

/**
 * Genera los cruces de una ronda: barrido ordenado por MMR, aumentar cuantos
 * cruces salen y pulir el reparto con el optimizador.
 *
 * Parte del emparejador (antes todo vivia en ArenaMatchmakingService).
 */
class PairingGenerator
{
    public function __construct(
        private readonly RepeatOpponentPolicy $repeatPolicy,
        private readonly CompositionPolicy $compositionPolicy,
        private readonly PairingOptimizer $optimizer,
    ) {
    }

    /**
     * Cuantos vecinos de MMR mira cada equipo al buscar rival.
     *
     * Puntuar la cola entera contra la cola entera es lo que no escala, y no
     * hace falta: ordenados por MMR, un equipo nunca se empareja con otro que
     * tenga ochenta puestos por medio salvo que no le quede nadie mas. Ochenta
     * es holgado de sobra -con tres reinos repartidos, ahi dentro caben mas de
     * cincuenta rivales de reino contrario-, y si aun asi alguien se queda
     * suelto, emparejarRezagados() le busca pareja sin ventana ninguna.
     */
    private const PAIRING_MMR_WINDOW = 80;

    /** Vueltas de pulido alternando los tipos de mejora. */
    private const PAIRING_POLISH_ROUNDS = 3;

    /**
     * Vueltas de rescate de sueltos.
     *
     * Cada vuelta coloca a todos los que encuentran donante cerca, asi que con
     * dos o tres ya no queda nadie. El tope existe para que esto no pueda
     * quedarse dando vueltas si algun dia el grafo deja de ser el que es.
     */
    private const PAIRING_AUGMENT_ROUNDS = 8;
    private const EXACT_REPEAT_PAIRING_PENALTY = 10000;

    /**
     * Lo que cuesta volver a cruzarte con alguien al que acabas de enfrentarte.
     *
     * Cinco mil puntos de MMR es un numero que no existe: la cola entera cabe
     * en dos mil. Sirve para que la revancha inmediata pierda contra
     * literalmente cualquier otra opcion legal, pero sin ser un veto -si no hay
     * mas nadie, es el unico cruce posible y se hace-. Es un desempate, no una
     * prohibicion.
     */
    private const RECENT_OPPONENT_PENALTY = 5000;

    /**
     * Reparte a todos los equipos en cola en los mejores cruces posibles.
     *
     * Antes esto era avido y cubico: buscaba el mejor cruce de TODA la cola,
     * lo sacaba, y volvia a mirarlo todo desde cero. Dos problemas, y los dos
     * se notaban.
     *
     * El primero, el tiempo. Reevaluar la cola entera en cada vuelta sale a
     * unos n^3/12 pares, y esto corre dentro de la peticion HTTP de quien entra
     * a la cola: con 240 en cola eran 47 segundos, o sea un timeout en un
     * hosting compartido, no una espera.
     *
     * El segundo, la calidad. Ser avido no reparte bien: con seis duelistas a
     * 1000/1300/1600 contra 1200/1500/1800 se llevaba primero los dos cruces
     * mas ajustados y dejaba al de 1000 contra el de 1800 -800 puntos de MMR,
     * unas cincuenta partidas de diferencia- porque ya se habia gastado a sus
     * rivales buenos.
     *
     * Ahora son tres pasos:
     *
     *   1. Puntuar cada cruce legal UNA vez (~n^2/2 en vez de n^3/12).
     *   2. Barrerlos en orden, quedandose con los que tengan los dos lados
     *      libres. Es el mismo criterio avido de antes pero sin repetir trabajo.
     *   3. Mejorar el reparto intercambiando rivales entre cruces ya hechos
     *      mientras el resultado mejore. Esto es lo que arregla el caso de los
     *      seis: el paso 2 deja 100+100+800 y los intercambios lo bajan a
     *      200+200+200.
     *
     * Y si tras el barrido queda alguien suelto teniendo rival legal, se le
     * empareja igual en un repaso final. La regla no negociable es que nadie se
     * quede en cola habiendo con quien jugar.
     */
    public function buildMatchPairings(Collection $candidateTeams): array
    {
        $teams = $candidateTeams->values()->all();
        $total = count($teams);

        if ($total < 2) {
            return [];
        }

        $recentPairHistory = $this->repeatPolicy->buildRecentPairHistory();
        $recentMatchSnapshots = $this->repeatPolicy->buildRecentMatchSnapshots();
        $rivalesRecientes = $this->repeatPolicy->buildRecentOpponents();

        // Memoria de puntuaciones: un cruce se puntua una vez y ya. La usan
        // tanto el barrido como los intercambios, que preguntan por cruces que
        // el barrido nunca llego a mirar.
        $cache = [];
        $puntuar = function (int $i, int $j) use (&$cache, $teams, $recentPairHistory, $recentMatchSnapshots, $rivalesRecientes): ?array {
            $clave = $i < $j ? "$i:$j" : "$j:$i";

            if (array_key_exists($clave, $cache)) {
                return $cache[$clave];
            }

            return $cache[$clave] = $this->puntuarCruce(
                $teams[$i], $teams[$j], $recentPairHistory, $recentMatchSnapshots, $rivalesRecientes
            );
        };

        $orden = $this->ordenPorMmr($teams);
        $candidatos = [];

        // Solo se puntuan cruces entre equipos cercanos en MMR. Mirar la cola
        // entera contra la cola entera es lo que no escala, y un equipo no se
        // empareja jamas con otro que tenga ochenta puestos de MMR por medio
        // salvo que no le quede nadie mas: para eso esta el repaso final.
        foreach ($orden as $pos => $i) {
            $hasta = min($total - 1, $pos + self::PAIRING_MMR_WINDOW);

            for ($siguiente = $pos + 1; $siguiente <= $hasta; $siguiente++) {
                $j = $orden[$siguiente];
                $puntos = $puntuar($i, $j);

                if ($puntos !== null) {
                    $candidatos[] = [$puntos['score'], $puntos['diff'], $i, $j];
                }
            }
        }

        // Mejor puntuacion primero y, a igualdad, menor diferencia de MMR: el
        // mismo desempate que aplicaba el bucle avido.
        usort($candidatos, static function (array $a, array $b): int {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });

        $pareja = array_fill(0, $total, null);

        foreach ($candidatos as [, , $i, $j]) {
            if ($pareja[$i] === null && $pareja[$j] === null) {
                $pareja[$i] = $j;
                $pareja[$j] = $i;
            }
        }

        $this->emparejarRezagados($pareja, $total, $puntuar);
        $this->aumentarCardinalidad($pareja, $teams, $puntuar);

        // Los dos pasos de pulido se alternan: cambiar a un emparejado por un
        // suelto abre intercambios nuevos entre cruces, y al reves. Con dos
        // vueltas ya no se mueve nada en ningun tamaño de cola probado.
        for ($pulido = 0; $pulido < self::PAIRING_POLISH_ROUNDS; $pulido++) {
            $cambioConSueltos = $this->optimizer->mejorarConSueltos($pareja, $teams, $puntuar);
            $cambioEntreCruces = $this->optimizer->mejorarPorIntercambios($pareja, $teams, $puntuar);
            $cambioEnTercetos = $this->optimizer->mejorarPorRotaciones($pareja, $teams, $puntuar);

            if (!$cambioConSueltos && !$cambioEntreCruces && !$cambioEnTercetos) {
                break;
            }
        }

        $pairings = [];

        foreach ($pareja as $i => $j) {
            if ($j !== null && $i < $j) {
                $pairings[] = [
                    'team_a' => $teams[$i],
                    'team_b' => $teams[$j],
                ];
            }
        }

        return $pairings;
    }

    /**
     * Puntua un cruce, o devuelve null si no se puede jugar.
     *
     * Cuanto mas bajo, mejor. La base es la diferencia de MMR y encima se
     * suman los recargos: repetir un cruce reciente, solaparse con una partida
     * de hace poco, y lo que separa a los dos equipos en composicion.
     *
     * @return array{score: int, diff: int}|null
     */
    private function puntuarCruce(
        array $teamA,
        array $teamB,
        array $recentPairHistory,
        Collection $recentMatchSnapshots,
        array $rivalesRecientes = []
    ): ?array {
        // Nunca se enfrenta un equipo de 2v2 contra uno de 3v3.
        if ($teamA['arena_mode'] !== $teamB['arena_mode']) {
            return null;
        }

        if ($teamA['realm'] === $teamB['realm']) {
            return null;
        }

        // Ni una cuenta contra si misma. evaluateQueueTeam ya lo impide DENTRO
        // de un equipo, pero entre los dos bandos no lo miraba nadie: una cuenta
        // con un personaje en cada reino -se permiten cinco- podia acabar
        // peleando contra ella misma y regalarse victorias, PL y MMR.
        //
        // Por la pantalla no se llega: join() bloquea todos los personajes de la
        // cuenta y rechaza la segunda cola. Pero el emparejador no puede
        // depender de que el controlador se acuerde, y el laboratorio de bots si
        // encola por personaje.
        if ($this->compartenCuenta($teamA, $teamB)) {
            return null;
        }

        $diff = abs($teamA['avg_mmr'] - $teamB['avg_mmr']);

        $score = $diff
            + $this->repeatPolicy->getRepeatPairCount($teamA, $teamB, $recentPairHistory) * self::EXACT_REPEAT_PAIRING_PENALTY
            + $this->repeatPolicy->contarRivalesRepetidos($teamA, $teamB, $rivalesRecientes) * self::RECENT_OPPONENT_PENALTY
            + $this->repeatPolicy->calculateRepeatOverlapPenalty($teamA, $teamB, $recentMatchSnapshots)
            + $this->compositionPolicy->calculatePairCompositionPenalty($teamA, $teamB);

        return ['score' => $score, 'diff' => $diff];
    }

    /**
     * Indices de los equipos ordenados por su MMR medio, con los reinos
     * mezclados cuando hay empate.
     *
     * Lo del empate no es un detalle: es el dia del lanzamiento. Todo el mundo
     * arranca con 1000 de MMR, asi que durante las primeras semanas la cola
     * entera empata. Y los equipos llegan aqui agrupados por reino -asi los
     * arma processQueue-, de modo que desempatar por su posicion en el array
     * ordenaba por reino: alsius, alsius, alsius... La ventana de vecinos que
     * mira cada equipo solo veia gente de su propio reino, que es justo con
     * quien no puede jugar.
     *
     * Medido con 900 en cola y todos a 1000: de los 68.760 pares que miraba la
     * ventana, solo 6.480 eran legales -un 9%-, el barrido armaba 160 cruces de
     * los 450 posibles y dejaba 580 personas sueltas, que luego habia que
     * rescatar con un repaso completo de cuarenta segundos. Con los reinos
     * mezclados, los mismos 900 salen en 449 cruces y 2 sueltos.
     *
     * El desempate es el puesto que ocupa cada equipo DENTRO de su reino, asi
     * que los empatados salen intercalados -alsius, ignis, syrtis, alsius...- y
     * la ventana ve rivales de verdad.
     *
     * @param  array<int, array<string, mixed>>  $teams
     * @return list<int>
     */
    private function ordenPorMmr(array $teams): array
    {
        $puestoEnSuReino = [];
        $cuantosLlevaElReino = [];

        foreach ($teams as $indice => $team) {
            $realm = (string) ($team['realm'] ?? '');
            $puestoEnSuReino[$indice] = $cuantosLlevaElReino[$realm] ?? 0;
            $cuantosLlevaElReino[$realm] = $puestoEnSuReino[$indice] + 1;
        }

        $orden = array_keys($teams);

        usort($orden, static function (int $a, int $b) use ($teams, $puestoEnSuReino): int {
            return [(int) $teams[$a]['avg_mmr'], $puestoEnSuReino[$a], $a]
                <=> [(int) $teams[$b]['avg_mmr'], $puestoEnSuReino[$b], $b];
        });

        return $orden;
    }

    /**
     * Empareja a quien quedo suelto tras el barrido.
     *
     * La ventana de MMR del barrido deja fuera cruces muy separados, y en una
     * cola pequeña o con los reinos descompensados eso puede dejar a alguien
     * sin pareja teniendo rival legal. Aqui se miran todos contra todos, sin
     * ventana: son pocos y la regla es que nadie espere habiendo con quien
     * jugar.
     *
     * @param  array<int, int|null>  $pareja
     */
    private function emparejarRezagados(array &$pareja, int $total, callable $puntuar): void
    {
        $sueltos = [];

        for ($i = 0; $i < $total; $i++) {
            if ($pareja[$i] === null) {
                $sueltos[] = $i;
            }
        }

        if (count($sueltos) < 2) {
            return;
        }

        $extra = [];

        foreach ($sueltos as $posA => $i) {
            foreach (array_slice($sueltos, $posA + 1) as $j) {
                $puntos = $puntuar($i, $j);

                if ($puntos !== null) {
                    $extra[] = [$puntos['score'], $puntos['diff'], $i, $j];
                }
            }
        }

        usort($extra, static function (array $a, array $b): int {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });

        foreach ($extra as [, , $i, $j]) {
            if ($pareja[$i] === null && $pareja[$j] === null) {
                $pareja[$i] = $j;
                $pareja[$j] = $i;
            }
        }
    }

    /**
     * Saca mas partidas deshaciendo cruces ya hechos.
     *
     * Quedarse corto de partidas teniendo gente con rival legal es lo unico que
     * este emparejador no puede hacer, y el barrido por puntuacion lo hace solo.
     * El caso tipico, medido con 900 en cola y 300 por reino: el barrido se
     * lleva 355 cruces y deja 190 sueltos, y los 190 son TODOS del mismo reino,
     * asi que entre ellos no pueden jugar. Cabian 450 partidas.
     *
     * Ejemplo pequeño del mismo fallo, con cuatro en cola: Alsius 1070, Alsius
     * 1151, Ignis 893 y Syrtis 778. El cruce mas ajustado es Syrtis contra
     * Ignis -115 puntos-, y en cuanto se lo lleva, los dos Alsius se quedan
     * mirandose. Una partida donde caben dos.
     *
     * La salida es deshacer ese cruce y rehacerlo con los sueltos: Ignis 893
     * contra Alsius 1070, y Syrtis 778 contra Alsius 1151. Dos partidas. Cuesta
     * mas MMR en total, y da igual: mas vale un cruce regular que quedarse en
     * cola mirando.
     *
     * Por que basta con esto: el grafo de cruces posibles es "todos contra
     * todos menos los de tu reino". Si quedan dos sueltos de reinos distintos,
     * emparejarRezagados ya los caso. Si todos los sueltos son del mismo reino y
     * aun cabe otra partida, forzosamente existe un cruce hecho con sus DOS
     * lados fuera de ese reino, y deshacerlo da sitio a dos sueltos. Cuando no
     * existe ese cruce es que ya no caben mas partidas. -La excepcion teorica es
     * el veto de "una cuenta no juega contra si misma", que quita alguna arista
     * suelta; en la practica no se llega porque entrar a la cola bloquea la
     * cuenta entera.-
     *
     * El reparto se hace por cercania de MMR y no probandolo todo: emparejar a
     * cada pareja de sueltos con el donante mas proximo en nivel coloca a los
     * 190 de una pasada. Buscar el mejor donante para cada pareja mirandolos
     * todos costaba cuarenta segundos y solo colocaba a doce.
     *
     * @param  array<int, int|null>  $pareja
     */
    private function aumentarCardinalidad(array &$pareja, array $teams, callable $puntuar): void
    {
        $total = count($teams);

        for ($vuelta = 0; $vuelta < self::PAIRING_AUGMENT_ROUNDS; $vuelta++) {
            $sueltos = [];
            $cruces = [];

            for ($i = 0; $i < $total; $i++) {
                if ($pareja[$i] === null) {
                    $sueltos[] = $i;
                } elseif ($i < $pareja[$i]) {
                    $cruces[] = [$i, $pareja[$i]];
                }
            }

            // Con menos de dos sueltos no hay ninguna partida que ganar: hace
            // falta uno para cada lado del cruce que se deshace.
            if (count($sueltos) < 2 || $cruces === []) {
                return;
            }

            // Los sueltos se agrupan por MODALIDAD y reino, y se atiende
            // primero al grupo con mas gente esperando.
            //
            // La modalidad es tan importante como el reino, y olvidarla costaba
            // partidas: las tres colas se reparten a la vez, asi que agrupando
            // solo por reino se juntaban un suelto de 1v1 con uno de 3v3 para
            // meterlos en el mismo cruce, cosa imposible, y se les ofrecian
            // donantes de una modalidad que no era la suya. Medido con 226 en
            // cola y las tres modalidades encendidas: cuatro donantes validos y
            // solo tres aprovechados.
            $porGrupo = [];

            foreach ($sueltos as $suelto) {
                $clave = (string) $teams[$suelto]['arena_mode'] . '|' . (string) $teams[$suelto]['realm'];
                $porGrupo[$clave][] = $suelto;
            }

            uasort($porGrupo, static fn (array $a, array $b): int => count($b) <=> count($a));

            $gastados = [];
            $colocados = 0;

            foreach ($porGrupo as $clave => $delReino) {
                [$modo, $realm] = explode('|', $clave, 2);

                if (count($delReino) < 2) {
                    continue;
                }

                // Solo sirven los cruces que NO tienen ningun lado de ese reino:
                // son los unicos que al deshacerse dejan dos huecos que estos
                // sueltos pueden ocupar. Antes se buscaba sobre la lista entera
                // de cruces y se miraba una ventana de veinte a cada lado, y los
                // que servian eran una minoria diminuta ahi dentro: con 162 en
                // cola y 30 sueltos, habia 6 cruces validos entre 66 y la
                // ventana solo alcanzaba a 5. Gente en cola con rival esperando.
                $donantes = [];

                foreach ($cruces as $posicion => [$v, $w]) {
                    if (isset($gastados[$posicion])) {
                        continue;
                    }

                    // De la misma modalidad que los sueltos: un cruce de 2v2 no
                    // deja hueco a nadie que espere un duelo.
                    if ((string) $teams[$v]['arena_mode'] !== $modo) {
                        continue;
                    }

                    if ((string) $teams[$v]['realm'] === $realm || (string) $teams[$w]['realm'] === $realm) {
                        continue;
                    }

                    $donantes[] = [
                        'cruce' => [$v, $w],
                        'nivel' => $this->optimizer->mmrDelCruce($teams, [$v, $w]),
                        'origen' => $posicion,
                    ];
                }

                if ($donantes === []) {
                    continue;
                }

                usort($donantes, static fn (array $a, array $b): int => $a['nivel'] <=> $b['nivel']);

                usort($delReino, static function (int $a, int $b) use ($teams): int {
                    return [(int) $teams[$a]['avg_mmr'], $a] <=> [(int) $teams[$b]['avg_mmr'], $b];
                });

                $usados = [];

                // Los sueltos van de dos en dos y por nivel, asi que cada pareja
                // que entra a un cruce deshecho son los dos mas parecidos que
                // quedaban.
                for ($t = 0; $t + 1 < count($delReino); $t += 2) {
                    $u = $delReino[$t];
                    $u2 = $delReino[$t + 1];
                    $nivel = (int) (((int) $teams[$u]['avg_mmr'] + (int) $teams[$u2]['avg_mmr']) / 2);

                    $mejor = $this->donanteMasCercano($donantes, $usados, $nivel, $u, $u2, $puntuar);

                    if ($mejor === null) {
                        continue;
                    }

                    $usados[$mejor['posicion']] = true;

                    foreach ($mejor['reparto'] as [$a, $b]) {
                        $pareja[$a] = $b;
                        $pareja[$b] = $a;
                    }

                    $colocados++;
                }

                // Los cruces que se han deshecho ya no valen para otro reino.
                foreach (array_keys($usados) as $posicion) {
                    $gastados[$donantes[$posicion]['origen']] = true;
                }
            }

            if ($colocados === 0) {
                return;
            }
        }
    }

    /**
     * El donante mas cercano en nivel que admite a estos dos sueltos.
     *
     * $donantes llega YA filtrado: solo cruces que, al deshacerse, dejan dos
     * huecos que estos sueltos pueden ocupar. Ese filtro es lo que hace que la
     * busqueda por ventana valga: buscar sobre la lista entera de cruces y
     * quedarse con veinte a cada lado miraba sobre todo cruces que no servian,
     * y dejaba gente en cola teniendo rival.
     *
     * Dentro de la lista filtrada si se busca por biseccion y ventana, porque
     * el donante que mejor encaja siempre esta cerca en nivel y recorrerlos
     * todos por cada pareja de sueltos vuelve a costar segundos.
     *
     * @param  list<array{cruce: array{0: int, 1: int}, nivel: int, origen: int}>  $donantes
     * @param  array<int, true>  $usados
     * @return array{posicion: int, reparto: list<array{0: int, 1: int}>}|null
     */
    private function donanteMasCercano(
        array $donantes,
        array $usados,
        int $nivel,
        int $u,
        int $u2,
        callable $puntuar
    ): ?array {
        $numero = count($donantes);

        if ($numero === 0) {
            return null;
        }

        $bajo = 0;
        $alto = $numero - 1;

        while ($bajo < $alto) {
            $medio = intdiv($bajo + $alto, 2);

            if ($donantes[$medio]['nivel'] < $nivel) {
                $bajo = $medio + 1;
            } else {
                $alto = $medio;
            }
        }

        $desde = max(0, $bajo - PairingOptimizer::PAIRING_SWAP_WINDOW);
        $hasta = min($numero - 1, $bajo + PairingOptimizer::PAIRING_SWAP_WINDOW);

        $mejor = $this->mejorDonanteEntre($donantes, $usados, $desde, $hasta, $u, $u2, $puntuar);

        if ($mejor !== null) {
            return $mejor;
        }

        // Si en la ventana no habia ninguno libre, se mira la lista entera
        // antes de rendirse. Es el caso raro -muchos sueltos del mismo reino
        // agotando donantes-, y rendirse ahi significaria dejar a dos personas
        // en cola teniendo con quien jugar, que es lo unico que no vale.
        return $this->mejorDonanteEntre($donantes, $usados, 0, $numero - 1, $u, $u2, $puntuar);
    }

    /**
     * El mejor donante libre dentro de un tramo de la lista.
     *
     * @return array{posicion: int, reparto: list<array{0: int, 1: int}>}|null
     */
    private function mejorDonanteEntre(
        array $donantes,
        array $usados,
        int $desde,
        int $hasta,
        int $u,
        int $u2,
        callable $puntuar
    ): ?array {
        $mejor = null;

        for ($posicion = $desde; $posicion <= $hasta; $posicion++) {
            if (isset($usados[$posicion])) {
                continue;
            }

            [$v, $w] = $donantes[$posicion]['cruce'];
            $costeActual = $puntuar($v, $w)['score'];

            foreach ([[$u, $v, $u2, $w], [$u, $w, $u2, $v]] as [$p1, $p2, $p3, $p4]) {
                $uno = $puntuar($p1, $p2);
                $otro = $puntuar($p3, $p4);

                if ($uno === null || $otro === null) {
                    continue;
                }

                $sobrecoste = $uno['score'] + $otro['score'] - $costeActual;

                if ($mejor === null || $sobrecoste < $mejor['sobrecoste']) {
                    $mejor = [
                        'sobrecoste' => $sobrecoste,
                        'posicion' => $posicion,
                        'reparto' => [[$p1, $p2], [$p3, $p4]],
                    ];
                }
            }
        }

        return $mejor;
    }

    /** Si los dos bandos comparten alguna cuenta de usuario. */
    private function compartenCuenta(array $teamA, array $teamB): bool
    {
        $cuentas = fn (array $team) => collect($team['entries'] ?? [])
            ->map(fn (Queue $queue) => (int) ($queue->player->user_id ?? 0))
            ->filter()
            ->all();

        return array_intersect($cuentas($teamA), $cuentas($teamB)) !== [];
    }
}
