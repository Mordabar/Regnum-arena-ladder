<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja que un formulario se envie sin recargar la pagina.
 *
 * El navegador manda el formulario con la cabecera `X-Arena-Sin-Recarga` y la
 * ruta en la que esta (`X-Arena-Aqui`). Si el servidor contesta con una
 * redireccion -que es lo que hacen todas las acciones del lobby-, aqui se
 * convierte en JSON: adonde iba y, si vuelve a la misma pagina, los avisos que
 * habria pintado (exito, error, validacion). La pagina los muestra como avisos
 * flotantes y repinta solo el panel; si iba a otra pagina, navega alli y el aviso
 * se queda en la sesion para que lo pinte la pagina de destino.
 */
class ResponderSinRecarga
{
    private const AVISOS = ['success', 'warning', 'error', 'info'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->headers->has('X-Arena-Sin-Recarga') || !$response instanceof RedirectResponse) {
            return $response;
        }

        $destino = $response->getTargetUrl();
        $aqui = parse_url((string) $request->headers->get('X-Arena-Aqui', ''), PHP_URL_PATH);
        $mismaRuta = is_string($aqui) && $aqui !== '' && parse_url($destino, PHP_URL_PATH) === $aqui;

        $cuerpo = ['redirect' => $destino, 'misma_ruta' => $mismaRuta, 'avisos' => []];

        if ($mismaRuta && $request->hasSession()) {
            $sesion = $request->session();

            foreach (self::AVISOS as $tipo) {
                if ($sesion->has($tipo)) {
                    $cuerpo['avisos'][] = ['tipo' => $tipo, 'texto' => (string) $sesion->get($tipo)];
                }
            }

            // `withErrors` guarda una bolsa de bolsas: se recorren todas.
            $errores = $sesion->get('errors');
            $bolsas = $errores instanceof ViewErrorBag
                ? $errores->getBags()
                : ($errores instanceof MessageBag ? [$errores] : []);
            foreach ($bolsas as $bolsa) {
                foreach ($bolsa->all() as $texto) {
                    $cuerpo['avisos'][] = ['tipo' => 'error', 'texto' => (string) $texto];
                }
            }

            // Lo pintara la pagina, no la siguiente peticion: se quita de la
            // sesion, tambien de la lista de lo recien guardado.
            $sesion->forget([...self::AVISOS, 'errors']);
            $sesion->put('_flash.new', array_values(array_diff(
                (array) $sesion->get('_flash.new', []),
                [...self::AVISOS, 'errors'],
            )));
        }

        return response()->json($cuerpo);
    }
}
