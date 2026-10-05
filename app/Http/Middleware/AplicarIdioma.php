<?php

namespace App\Http\Middleware;

use App\Support\I18n\Idioma;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decide el idioma de la peticion y lo recuerda.
 *
 * ?lang=xx manda y se guarda un año; sin el, la cookie; y la primera vez, el
 * idioma del navegador. Es solo la decision: lo que traduce el texto es
 * TraducirRespuesta.
 */
class AplicarIdioma
{
    public function handle(Request $request, Closure $next): Response
    {
        $idioma = Idioma::detectar($request);
        app()->setLocale($idioma);

        $response = $next($request);

        // Se guarda lo que eligio con ?lang. El idioma deducido del navegador no
        // se guarda: si cambia el del sistema, el sitio lo sigue.
        if (Idioma::normalizar($request->query('lang')) !== null) {
            $response->headers->setCookie(Cookie::make(Idioma::COOKIE, $idioma, 60 * 24 * 365));
        }

        // La respuesta depende de la cookie y del idioma del navegador: que
        // ninguna cache intermedia sirva el idioma de otro.
        $response->headers->set('Vary', trim(($response->headers->get('Vary') ? $response->headers->get('Vary') . ', ' : '') . 'Cookie, Accept-Language'));
        $response->headers->set('Content-Language', Idioma::htmlLang($idioma));

        return $response;
    }
}
