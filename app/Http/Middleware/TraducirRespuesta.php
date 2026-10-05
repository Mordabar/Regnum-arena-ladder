<?php

namespace App\Http\Middleware;

use App\Support\I18n\Idioma;
use App\Support\I18n\Traductor;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cambia el texto de la respuesta al idioma elegido.
 *
 * El panel de administracion se queda en español: es una herramienta de trabajo
 * de quien lo lleva, no del jugador.
 */
class TraducirRespuesta
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $traductor = Traductor::para();

        if (!$traductor->activo() || $this->esAdmin($request) || !$this->traducible($response)) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            $response->setData($traductor->datos($response->getData(false)));

            return $response;
        }

        $tipo = (string) $response->headers->get('Content-Type', 'text/html');

        if (str_contains($tipo, 'text/html')) {
            $response->setContent($traductor->html((string) $response->getContent()));
        }

        return $response;
    }

    private function traducible(Response $response): bool
    {
        return !($response instanceof RedirectResponse
            || $response instanceof BinaryFileResponse
            || $response instanceof StreamedResponse)
            && $response->getStatusCode() < 500;
    }

    private function esAdmin(Request $request): bool
    {
        return $request->routeIs('admin.*');
    }
}
