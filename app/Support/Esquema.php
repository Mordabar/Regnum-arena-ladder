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
 * - un "si" se recuerda un dia (una tabla o una columna no desaparece sola);
 * - un "no" se recuerda solo durante la peticion, porque lo normal es que deje
 *   de serlo en cuanto se migre, y no puede quedarse pegado.
 */
class Esquema
{
    /** @var array<string, bool> */
    private static array $memo = [];

    private const TTL_SEGUNDOS = 86400;

    public static function tabla(string $tabla): bool
    {
        return self::comprobar('t:' . $tabla, fn () => Schema::hasTable($tabla));
    }

    public static function columna(string $tabla, string $columna): bool
    {
        return self::comprobar('c:' . $tabla . '.' . $columna, fn () => Schema::hasColumn($tabla, $columna));
    }

    /** Para los tests y para despues de migrar en el mismo proceso. */
    public static function olvidar(): void
    {
        self::$memo = [];

        try {
            Cache::flush();
        } catch (\Throwable) {
            // Sin cache disponible no hay nada que olvidar.
        }
    }

    private static function comprobar(string $clave, \Closure $consulta): bool
    {
        if (array_key_exists($clave, self::$memo)) {
            return self::$memo[$clave];
        }

        $clave_cache = 'esquema:' . $clave;

        try {
            if (Cache::get($clave_cache) === true) {
                return self::$memo[$clave] = true;
            }
        } catch (\Throwable) {
            // La cache es una ayuda: sin ella se consulta.
        }

        $existe = (bool) $consulta();

        if ($existe) {
            try {
                Cache::put($clave_cache, true, self::TTL_SEGUNDOS);
            } catch (\Throwable) {
            }
        }

        return self::$memo[$clave] = $existe;
    }
}
