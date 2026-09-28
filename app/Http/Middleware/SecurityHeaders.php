<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en todas las respuestas del sitio.
 *
 * La CSP permite 'unsafe-inline' en scripts y estilos porque el sitio los usa
 * por todas partes (los paneles se repintan con HTML que trae sus propios
 * scripts). Aun asi cierra lo que mas importa: ningun script de un dominio
 * ajeno, nada de plugins, ningun formulario que mande a otro sitio y ninguna
 * pagina ajena que nos meta en un iframe (clickjacking sobre el panel).
 *
 * 'wasm-unsafe-eval' es para el decodificador Draco de los modelos 3D, que
 * compila WebAssembly. No habilita eval() de JavaScript.
 */
class SecurityHeaders
{
    /** Origenes de terceros que el sitio usa de verdad. */
    private const FONTS_CSS = 'https://fonts.googleapis.com';

    private const FONTS = 'https://fonts.gstatic.com';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Content-Security-Policy' => $this->contentSecurityPolicy($request),
        ];

        // HSTS solo por HTTPS: por HTTP el navegador la ignora y en local
        // obligaria a HTTPS a un 127.0.0.1 que no lo tiene. Sin
        // includeSubDomains: no sabemos que todos los subdominios lo tengan.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $name => $value) {
            // Una respuesta que ya trae la suya (las capturas llevan una CSP
            // con sandbox mas estricta) se respeta.
            if (!$response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'",
            "style-src 'self' 'unsafe-inline' " . self::FONTS_CSS,
            "font-src 'self' data: " . self::FONTS,
            "img-src 'self' data: blob:",
            "connect-src 'self' data: blob:",
            "worker-src 'self' blob:",
            "media-src 'self' data: blob:",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            // discord.com: un formulario enviado con la sesion caducada
            // redirige al login de Discord, y Chrome aplica form-action
            // tambien a esa redireccion.
            "form-action 'self' https://discord.com",
            "frame-ancestors 'none'",
        ];

        if ($request->isSecure()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
