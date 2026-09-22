<?php

namespace App\Support;

/**
 * Base64 de la variante que usa la web: sin `+`, sin `/` y sin el relleno de
 * `=`, porque todo esto viaja dentro de URLs y de cabeceras HTTP.
 *
 * Es lo que hablan las claves VAPID, los JWT y las suscripciones de push, asi
 * que conviene tenerlo en un solo sitio: cada vez que alguien lo escribe a
 * mano se olvida de un lado de la conversion y el fallo aparece tres capas
 * mas abajo como un 401 sin explicacion.
 */
class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $texto): string
    {
        $texto = strtr(trim($texto), '-_', '+/');

        // El relleno que se quito al codificar. Sin esto, base64_decode
        // estricto devuelve false y el permisivo devuelve basura.
        $sobra = strlen($texto) % 4;
        if ($sobra !== 0) {
            $texto .= str_repeat('=', 4 - $sobra);
        }

        return (string) base64_decode($texto, true);
    }
}
