<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Si una tabla o una columna existe, sin preguntarselo a la base de datos en
 * cada tick del mantenimiento ni en cada render.
 *
 * `Schema::hasColumn` es una consulta al catalogo de MySQL. Hacia falta para
 * que la web siguiera viva en el rato entre subir el codigo y correr las
 * migraciones, pero repetirla en cada pagina es gasto puro. Por eso:
 *
 * - un "si" se recuerda un dia en la cache y en la memoria del proceso;
 * - un "no" se recuerda solo unos segundos, y SOLO en la memoria del proceso:
 *   en cuanto se migre tiene que dejar de ser "no", tambien en un worker que
 *   lleve horas vivo, y no puede quedarse pegado.
 */
class Esquema
{
    /** @var array<string, array{0: bool, 1: int}> clave => [existe, hasta cuando vale] */
    private static array $memo = [];

    private const TTL_SI = 86400;

    private const TTL_NO = 30;

    public static function tabla(string $tabla): bool
    {
        return self::comprobar('t:' . $tabla, fn () => Schema::hasTable($tabla));
    }

    public static function columna(string $tabla, string $columna): bool
    {
        return self::comprobar('c:' . $tabla . '.' . $columna, fn () => Schema::hasColumn($tabla, $columna));
    }

    /**
     * Olvida lo recordado: la memoria del proceso y SOLO las claves de esquema
     * de la cache. Nunca vacia la cache entera: ahi viven los candados y los
     * limites de frecuencia.
     */
    public static function olvidar(): void
    {
        foreach (array_keys(self::$memo) as $clave) {
            try {
                Cache::forget('esquema:' . $clave);
            } catch (\Throwable) {
            }
        }

        self::$memo = [];
    }

    private static function comprobar(string $clave, \Closure $consulta): bool
    {
        $ahora = now()->getTimestamp();

        if (isset(self::$memo[$clave]) && self::$memo[$clave][1] > $ahora) {
            return self::$memo[$clave][0];
        }

        $claveCache = 'esquema:' . $clave;

        try {
            if (Cache::get($claveCache) === true) {
                self::$memo[$clave] = [true, $ahora + self::TTL_SI];

                return true;
            }
        } catch (\Throwable) {
            // La cache es una ayuda: sin ella se consulta.
        }

        $existe = (bool) $consulta();

        if ($existe) {
            try {
                Cache::put($claveCache, true, self::TTL_SI);
            } catch (\Throwable) {
            }
        }

        self::$memo[$clave] = [$existe, $ahora + ($existe ? self::TTL_SI : self::TTL_NO)];

        return $existe;
    }
}
