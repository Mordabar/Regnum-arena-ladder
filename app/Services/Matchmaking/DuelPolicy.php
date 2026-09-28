<?php

namespace App\Services\Matchmaking;

use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Lo propio del duelo 1v1: prefiere el espejo (misma clase o subclase) sin
 * que esa preferencia pueda imponer un rival de MMR peor.
 *
 * Parte del emparejador (antes todo vivia en ArenaMatchmakingService).
 */
class DuelPolicy
{
    /**
     * Cuanto MMR tiene que encajar mejor un rival de otro estilo para ganarle
     * al espejo.
     *
     * El duelo prefiere el espejo -cazador contra cazador-, pero el MMR manda.
     * La forma es deliberadamente aditiva y continua: la cifra se suma a la
     * diferencia de MMR del cruce, asi que un rival de otra clase sale elegido
     * en cuanto encaje mejor A PARTIR de estos puntos -los empates exactos se
     * los lleva el MMR por el desempate de buildMatchPairings-. Leido al reves,
     * que es lo que importa: la preferencia NUNCA puede imponer un rival que
     * encaje peor por 20 puntos de MMR o mas. El techo real, medido barriendo
     * distancias, es 19 al cruzar clase y 7 al cambiar de subclase.
     *
     * De donde sale el 20: una partida mueve el MMR unos 16 puntos. O sea que
     * el techo del capricho es, como mucho, lo que se gana o se pierde en un
     * combate. Por debajo de eso los dos rivales son el mismo rival a efectos
     * practicos y se elige el que hace la pelea mas limpia de leer.
     *
     * Dos formas descartadas, y por que:
     *
     * - 70 y 25, los primeros valores. Demasiado: un espejo a 69 de MMR le
     *   ganaba a un rival de otra clase con el MMR clavado.
     * - Agrupar la diferencia de MMR en escalones de 50 y dejar el estilo como
     *   calderilla. Parecia mas limpio y era peor: el techo seguia siendo 49,
     *   pero ademas saltaba de golpe en cada borde de escalon -un punto de MMR
     *   invertia la decision- y, sobre todo, este ladder arranca con todo el
     *   mundo en 1000, asi que durante las primeras semanas la gente entera
     *   cabia en el primer escalon y el estilo decidia TODOS los duelos.
     */
    private const DUEL_ARCHETYPE_MISMATCH_PENALTY = 20;
    private const DUEL_SUBCLASS_MISMATCH_PENALTY = 8;

    /**
     * Lo lejos que queda un duelo de ser un espejo.
     *
     * Tres escalones: misma subclase no paga nada, misma clase con otra
     * subclase paga 8, y clase distinta paga 20. La cifra esta en puntos de MMR
     * y se suma a la diferencia de MMR del cruce, asi que dice exactamente
     * cuanto tiene que encajar mejor un rival de otro estilo para ganarle al
     * espejo -y, al reves, cuanto puede como mucho torcer la preferencia una
     * eleccion que el MMR habria hecho de otra forma.
     *
     * Los demas recargos -repetir cruce, solaparse con una partida reciente-
     * viven en la misma escala y conservan su significado: 900 sigue queriendo
     * decir "vale 900 de MMR evitar esta repeticion".
     *
     * Las clases son las del juego -guerrero, arquero, mago-, no los roles de
     * combate que usa resolveSubclassArchetype() para equilibrar equipos: un
     * jugador reconoce "mago contra mago", no "utilidad contra dano".
     */
    public function calculateDuelStylePenalty(array $teamA, array $teamB): int
    {
        $subclassA = $this->duelSubclass($teamA);
        $subclassB = $this->duelSubclass($teamB);

        if ($subclassA === null || $subclassB === null) {
            return 0;
        }

        if ($subclassA === $subclassB) {
            return 0;
        }

        $claseA = Player::SUBCLASS_ARCHETYPES[$subclassA] ?? null;
        $claseB = Player::SUBCLASS_ARCHETYPES[$subclassB] ?? null;

        // Una subclase desconocida -un dato viejo o un personaje raro- no puede
        // salir premiada con 0: se trata como el cruce mas lejano.
        if ($claseA === null || $claseB === null || $claseA !== $claseB) {
            return self::DUEL_ARCHETYPE_MISMATCH_PENALTY;
        }

        return self::DUEL_SUBCLASS_MISMATCH_PENALTY;
    }

    /** La subclase del unico jugador de un lado del duelo. */
    private function duelSubclass(array $team): ?string
    {
        $entries = $team['entries'] ?? null;

        if (!$entries instanceof Collection || $entries->count() !== 1) {
            return null;
        }

        $subclass = (string) ($entries->first()->player->subclass ?? '');

        return $subclass === '' ? null : $subclass;
    }
}
