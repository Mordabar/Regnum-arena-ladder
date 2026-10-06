<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        if (!$request->headers->has('X-Arena-Sin-Recarga')) {
            return $next($request);
        }

        // Un envio cuyo resultado no se llego a ver -la respuesta tardo mas de
        // la cuenta o la red se cayo- se puede repetir con la misma clave: si el
        // servidor ya lo hizo, contesta lo mismo en vez de volver a hacerlo.
        $clave = (string) $request->headers->get('X-Arena-Idempotencia', '');
        $usuario = $request->user()?->getAuthIdentifier();

        if ($usuario === null || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $clave)) {
            return $this->convertir($request, $next($request));
        }

        // La clave va unida a lo que se envia: si el jugador cambia los datos
        // antes de reintentar, ya no es el mismo envio y se hace lo nuevo.
        $huella = hash('sha256', $request->method() . '|' . $request->path() . '|' . json_encode($request->except(['_token'])) . '|' . json_encode(
            collect($request->allFiles())->flatten()->map(fn ($f) => $f->getClientOriginalName() . ':' . $f->getSize())->all()
        ));
        $llave = 'sin-recarga:' . $usuario . ':' . $clave . ':' . substr($huella, 0, 16);

        if (is_array($hecho = Cache::get($llave))) {
            return response()->json($hecho);
        }

        // Una cache sin cerrojos (algunos drivers) no frena el envio: se hace
        // sin la espera, que solo es una proteccion extra.
        try {
            $cerrojo = Cache::lock($llave . ':en-curso', 90);
        } catch (\Throwable) {
            return $this->convertir($request, $next($request));
        }

        try {
            // Si el primero sigue trabajando, se espera su resultado.
            $cerrojo->block(30);
        } catch (LockTimeoutException) {
            return response()->json(['ocupado' => true], 409);
        }

        try {
            if (is_array($hecho = Cache::get($llave))) {
                return response()->json($hecho);
            }

            $respuesta = $this->convertir($request, $next($request));

            if ($respuesta->getStatusCode() === 200 && $respuesta instanceof \Illuminate\Http\JsonResponse) {
                Cache::put($llave, $respuesta->getData(true), 120);
            }

            return $respuesta;
        } finally {
            $cerrojo->release();
        }
    }

    private function convertir(Request $request, Response $response): Response
    {
        if (!$response instanceof RedirectResponse) {
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
