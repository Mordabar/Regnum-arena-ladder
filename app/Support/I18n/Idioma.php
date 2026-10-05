<?php

namespace App\Support\I18n;

use Illuminate\Http\Request;

/**
 * Los idiomas del sitio: los mismos que ofrece Regnum Online.
 *
 * El español es el idioma de origen: todo el texto del sitio esta escrito en
 * español y los demas idiomas son catalogos (lang/xx.json) que traducen esas
 * mismas frases. Por eso el español no tiene catalogo.
 */
final class Idioma
{
    public const FUENTE = 'es';

    public const COOKIE = 'arena_lang';

    /**
     * Idioma => datos. El orden es el del selector.
     *
     * 'portugues' es el de Brasil, que es el que ofrece Regnum.
     *
     * @var array<string, array{nombre: string, corto: string, html: string, og: string}>
     */
    public const IDIOMAS = [
        'es' => ['nombre' => 'Español', 'corto' => 'ES', 'html' => 'es', 'og' => 'es_ES'],
        'en' => ['nombre' => 'English', 'corto' => 'EN', 'html' => 'en', 'og' => 'en_US'],
        'pt' => ['nombre' => 'Português (Brasil)', 'corto' => 'PT', 'html' => 'pt-BR', 'og' => 'pt_BR'],
        'de' => ['nombre' => 'Deutsch', 'corto' => 'DE', 'html' => 'de', 'og' => 'de_DE'],
        'fr' => ['nombre' => 'Français', 'corto' => 'FR', 'html' => 'fr', 'og' => 'fr_FR'],
        'nl' => ['nombre' => 'Nederlands', 'corto' => 'NL', 'html' => 'nl', 'og' => 'nl_NL'],
    ];

    /** @return list<string> */
    public static function codigos(): array
    {
        return array_keys(self::IDIOMAS);
    }

    public static function esValido(mixed $codigo): bool
    {
        return is_string($codigo) && isset(self::IDIOMAS[$codigo]);
    }

    public static function normalizar(mixed $codigo): ?string
    {
        if (!is_string($codigo)) {
            return null;
        }

        // "pt-BR", "en_US", "EN" -> pt, en, en
        $base = strtolower(substr(preg_split('/[-_]/', trim($codigo))[0] ?? '', 0, 5));

        return self::esValido($base) ? $base : null;
    }

    public static function actual(): string
    {
        $locale = app()->getLocale();

        return self::esValido($locale) ? $locale : self::FUENTE;
    }

    public static function esFuente(): bool
    {
        return self::actual() === self::FUENTE;
    }

    public static function htmlLang(?string $codigo = null): string
    {
        return self::IDIOMAS[$codigo ?? self::actual()]['html'];
    }

    /**
     * Que idioma le toca a esta peticion.
     *
     * 1. ?lang=xx: lo que acaba de elegir.
     * 2. La cookie: lo que eligio la ultima vez.
     * 3. El del navegador (Accept-Language), la primera vez.
     * 4. Español.
     */
    public static function detectar(Request $request): string
    {
        $pedido = self::normalizar($request->query('lang'));

        if ($pedido !== null) {
            return $pedido;
        }

        $recordado = self::normalizar($request->cookie(self::COOKIE));

        if ($recordado !== null) {
            return $recordado;
        }

        // En los tests se apaga: el cliente de pruebas manda un Accept-Language de
        // ingles por defecto, y la suite entera saldria traducida.
        return (config('arena.i18n_detect_browser', true) ? self::delNavegador($request) : null) ?? self::FUENTE;
    }

    /** El primer idioma de Accept-Language que el sitio sabe hablar, por orden de preferencia. */
    public static function delNavegador(Request $request): ?string
    {
        $cabecera = (string) $request->header('Accept-Language', '');

        if ($cabecera === '') {
            return null;
        }

        $candidatos = [];

        foreach (explode(',', $cabecera) as $posicion => $parte) {
            $trozos = explode(';', trim($parte));
            $codigo = self::normalizar($trozos[0] ?? '');

            if ($codigo === null) {
                continue;
            }

            $q = 1.0;
            foreach (array_slice($trozos, 1) as $parametro) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)/i', $parametro, $m)) {
                    $q = (float) $m[1];
                }
            }

            if ($q > 0) {
                $candidatos[] = [$q, -$posicion, $codigo];
            }
        }

        if ($candidatos === []) {
            return null;
        }

        rsort($candidatos);

        return $candidatos[0][2];
    }
}
