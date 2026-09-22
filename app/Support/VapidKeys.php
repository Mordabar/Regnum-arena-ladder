<?php

namespace App\Support;

use RuntimeException;

/**
 * Las claves VAPID: generarlas, guardarlas en dos lineas de texto y volver a
 * armar una clave utilizable por openssl.
 *
 * VAPID es como el servicio de push del navegador -el de Google, el de
 * Mozilla, el de Apple- sabe que el aviso lo manda este sitio y no cualquiera
 * que haya copiado la direccion de suscripcion de alguien. Son un par de
 * claves de curva eliptica P-256: la publica viaja al navegador al
 * suscribirse, y con la privada se firma cada envio.
 *
 * Lo unico peculiar de este fichero es que la clave privada NO se guarda en
 * PEM. Un PEM son seis lineas y en un `.env` eso es un problema: hay que
 * escaparlo, se rompe al copiarlo y el fichero de produccion se edita por
 * FTP. Se guarda el escalar de 32 bytes en base64url -una linea- y el PEM se
 * reconstruye al vuelo, que para P-256 es meter esos 32 bytes y los 65 del
 * punto publico en una plantilla DER de tamaño fijo.
 */
class VapidKeys
{
    /**
     * La plantilla DER de una clave privada EC de P-256 (SEC1, RFC 5915).
     *
     * Es fija byte a byte porque la curva, y por tanto el tamaño de todos los
     * campos, tambien lo es:
     *
     *   30 77            SEQUENCE de 119 bytes
     *     02 01 01         INTEGER 1 (version)
     *     04 20 <32>       OCTET STRING con el escalar privado
     *     a0 0a 06 08 ..   [0] OID prime256v1
     *     a1 44 03 42 00   [1] BIT STRING con el punto publico
     */
    private const DER_CABECERA = "\x30\x77\x02\x01\x01\x04\x20";
    private const DER_MEDIO = "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\xa1\x44\x03\x42\x00";

    /**
     * Un par nuevo, ya en base64url y listo para pegar en el `.env`.
     *
     * @return array{publica: string, privada: string}
     */
    public static function generar(): array
    {
        $recurso = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($recurso === false) {
            throw new RuntimeException('No se pudo generar el par de claves. Falta soporte de curva eliptica en openssl.');
        }

        $detalles = openssl_pkey_get_details($recurso);

        if (!is_array($detalles) || !isset($detalles['ec']['d'], $detalles['ec']['x'], $detalles['ec']['y'])) {
            throw new RuntimeException('openssl no devolvio los componentes de la clave.');
        }

        return [
            // El punto publico sin comprimir: 0x04 y las dos coordenadas. Es
            // el formato exacto que espera `applicationServerKey` en el
            // navegador.
            'publica' => Base64Url::encode("\x04" . self::a32($detalles['ec']['x']) . self::a32($detalles['ec']['y'])),
            'privada' => Base64Url::encode(self::a32($detalles['ec']['d'])),
        ];
    }

    /**
     * El PEM que openssl necesita para firmar, armado desde las dos lineas
     * guardadas.
     */
    public static function pem(string $privadaBase64Url, string $publicaBase64Url): string
    {
        $privada = Base64Url::decode($privadaBase64Url);
        $publica = Base64Url::decode($publicaBase64Url);

        if (strlen($privada) !== 32) {
            throw new RuntimeException('La clave privada VAPID no mide 32 bytes. Vuelve a generarla con `php artisan arena:push-keys`.');
        }

        if (strlen($publica) !== 65 || $publica[0] !== "\x04") {
            throw new RuntimeException('La clave publica VAPID no es un punto P-256 sin comprimir. Vuelve a generarla con `php artisan arena:push-keys`.');
        }

        $der = self::DER_CABECERA . $privada . self::DER_MEDIO . $publica;

        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
    }

    /**
     * ¿La privada y la publica son de verdad el mismo par?
     *
     * Hace falta preguntarlo porque openssl NO lo comprueba: se le puede dar
     * un PEM con un escalar privado de una generacion y un punto publico de
     * otra y lo carga tan contento. Es el error mas facil de cometer -se pega
     * una linea en el .env, luego se regenera y se pega la otra- y el mas
     * dificil de ver: el sitio arranca igual, el navegador se suscribe igual,
     * y cada envio muere con un 401 que nadie mira.
     *
     * La unica forma barata de saberlo es firmar algo con la privada e
     * intentar verificarlo con la publica. Si no son pareja, no verifica.
     */
    public static function parCoincide(string $privadaBase64Url, string $publicaBase64Url): bool
    {
        try {
            $privada = openssl_pkey_get_private(self::pem($privadaBase64Url, $publicaBase64Url));

            if ($privada === false) {
                return false;
            }

            $prueba = 'regnum-arena-ladder';

            if (!openssl_sign($prueba, $firma, $privada, OPENSSL_ALGO_SHA256)) {
                return false;
            }

            $publica = openssl_pkey_get_public(self::pemPublica($publicaBase64Url));

            return $publica !== false && openssl_verify($prueba, $firma, $publica, OPENSSL_ALGO_SHA256) === 1;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * El PEM de solo la parte publica, para verificar.
     *
     * La cabecera es fija igual que la de la privada: es el identificador de
     * "clave EC sobre prime256v1" seguido del punto.
     */
    public static function pemPublica(string $publicaBase64Url): string
    {
        $publica = Base64Url::decode($publicaBase64Url);

        if (strlen($publica) !== 65 || $publica[0] !== "\x04") {
            throw new RuntimeException('La clave publica VAPID no es un punto P-256 sin comprimir.');
        }

        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $publica;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * La firma ECDSA en el formato que pide JWS: la r y la s en crudo, una
     * detras de otra, 32 bytes cada una.
     *
     * openssl firma en DER, que es de longitud variable -un componente que
     * empiece por un byte alto lleva un 0x00 delante para no leerse como
     * negativo-. Pasarle el DER tal cual al servicio de push da un 401 sin
     * mas explicacion, que es de los fallos mas dificiles de ver.
     */
    public static function firmaEnCrudo(string $der): string
    {
        $pos = 0;
        $leer = function () use ($der, &$pos): string {
            if (($der[$pos] ?? '') !== "\x02") {
                throw new RuntimeException('La firma de openssl no tiene la forma esperada.');
            }

            $largo = ord($der[$pos + 1]);
            $valor = substr($der, $pos + 2, $largo);
            $pos += 2 + $largo;

            // Fuera el 0x00 de signo y a la izquierda los ceros que falten.
            return str_pad(ltrim($valor, "\x00"), 32, "\x00", STR_PAD_LEFT);
        };

        if (($der[0] ?? '') !== "\x30") {
            throw new RuntimeException('La firma de openssl no empieza por una secuencia DER.');
        }

        // Un byte para el tag y otro para la longitud: con P-256 la secuencia
        // nunca pasa de 127 bytes, asi que la longitud siempre es corta.
        $pos = 2;

        return $leer() . $leer();
    }

    /** Los componentes de openssl vienen sin ceros a la izquierda. */
    private static function a32(string $bytes): string
    {
        return str_pad($bytes, 32, "\x00", STR_PAD_LEFT);
    }
}
