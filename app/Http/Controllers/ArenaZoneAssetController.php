<?php

namespace App\Http\Controllers;

use App\Services\ArenaZoneService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sirve las zonas del mapa como JavaScript.
 *
 * Antes esto era un fichero suelto en public/. Tenia dos formas de mentir:
 *
 *   - El navegador se lo quedaba cacheado sin fecha de caducidad, asi que el
 *     admin movia un punto de encuentro y los jugadores seguian viendo el
 *     anterior durante dias. El panel lo pedia con `?v=time()` y por eso el
 *     admin era el unico que siempre veia lo ultimo.
 *   - Estaba versionado en git, asi que cada despliegue lo devolvia al estado
 *     del repositorio y se llevaba por delante lo que el admin hubiera puesto.
 *
 * Ahora sale de la base de datos y la URL lleva el sello de la configuracion.
 * Mientras nadie publique zonas, el navegador puede quedarse con su copia para
 * siempre -es lo que dice `immutable`-; en cuanto se publica, la URL cambia y
 * todo el mundo recibe lo nuevo a la vez. Que sea "a la vez" es justo lo que
 * hacia falta: los dos bandos de un cruce no pueden ver mapas distintos.
 */
class ArenaZoneAssetController extends Controller
{
    /** Un año. Lo que dura una URL con sello: hasta que el sello cambia. */
    private const CACHE_SEGUNDOS = 31536000;

    public function __invoke(Request $request, ArenaZoneService $zonas): Response
    {
        $sello = $zonas->sello();
        $cuerpo = 'window.ARENA_ZONES_CONFIG = '
            . json_encode($zonas->configuracionParaElMapa(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ";\n";

        $etag = '"' . $sello . '"';

        // Si el navegador ya tiene esta version, no hace falta mandarsela otra
        // vez. En un hosting compartido cada kilobyte ahorrado cuenta.
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response($cuerpo, 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('ETag', $etag)
            ->header(
                'Cache-Control',
                // Sin sello en la URL no se puede prometer que el contenido no
                // cambie, asi que se revalida siempre. Con sello, un año.
                //
                // Salvo que no haya zonas. Eso no es una configuracion: es un
                // despliegue a medias, entre subir el codigo y correr las
                // migraciones. Prometer un año sobre un mapa vacio deja al
                // navegador que pase por ahi sin mapa hasta el año que viene.
                $request->query('v') === $sello && $sello !== ArenaZoneService::SIN_ZONAS
                    ? 'public, max-age=' . self::CACHE_SEGUNDOS . ', immutable'
                    : 'no-cache'
            );
    }
}
