<?php

namespace App\Support\I18n;

/**
 * Traduce lo que el sitio ya escribe en español.
 *
 * No hay que tocar las vistas: el HTML sale como siempre y aqui se cambian los
 * textos por su version en el idioma elegido, buscandolos en un catalogo
 * (lang/xx.json) que va de la frase en español a la traducida. Lo que no esta
 * en el catalogo se queda en español, que es lo correcto: nunca se pierde un
 * texto ni se inventa uno.
 *
 * Como funciona:
 *
 * - Texto entre etiquetas: se busca la frase entera, ya sin espacios de mas.
 * - Numeros: "Quedan 56 dias" se busca como "Quedan {0} dias" y el 56 vuelve a
 *   su sitio. Con un solo texto cubre cualquier cifra.
 * - Atributos: title, alt, placeholder, aria-label y los meta de la cabecera.
 * - JSON (los paneles que se repintan, los avisos): sus textos tambien.
 * - Scripts: las frases entre comillas que el navegador enseña al jugador.
 *
 * Lo que NO se toca: lo que lleve translate="no" o la clase notranslate (nombres
 * de jugadores, codigos), <code>, <pre>, <textarea> y los scripts de datos.
 */
class Traductor
{
    /** Atributos con texto que lee una persona. */
    private const ATRIBUTOS = ['title', 'alt', 'placeholder', 'aria-label', 'aria-description', 'aria-roledescription', 'label'];

    /** Etiquetas dentro de las cuales no se traduce nada. */
    private const OPACAS = ['code', 'pre', 'textarea', 'kbd', 'samp'];

    /** @var array<string, array<string, string>> */
    private static array $catalogos = [];

    /** @var array<string, true> Frases que se pidieron y no estaban (solo si se graba). */
    private static array $ausentes = [];

    private static bool $registradoCierre = false;

    public function __construct(private readonly string $idioma)
    {
    }

    public static function para(?string $idioma = null): self
    {
        return new self($idioma ?? Idioma::actual());
    }

    /** Si hay algo que traducir: el español es el origen y no tiene catalogo. */
    public function activo(): bool
    {
        return $this->idioma !== Idioma::FUENTE && Idioma::esValido($this->idioma);
    }

    public static function olvidar(): void
    {
        self::$catalogos = [];
        self::$ausentes = [];
        self::$registradoCierre = false;
    }

    /** @return array<string, string> */
    public function catalogo(): array
    {
        if (!isset(self::$catalogos[$this->idioma])) {
            $ruta = lang_path($this->idioma . '.json');
            $datos = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;

            self::$catalogos[$this->idioma] = is_array($datos) ? $datos : [];
        }

        return self::$catalogos[$this->idioma];
    }

    // ------------------------------------------------------------------ frases

    /**
     * Una frase suelta (ya sin entidades HTML). Null si no hay traduccion.
     */
    public function frase(string $texto): ?string
    {
        $catalogo = $this->catalogo();
        $clave = $this->normalizar($texto);

        if ($clave === '') {
            return null;
        }

        if (isset($catalogo[$clave]) && $catalogo[$clave] !== '') {
            return $catalogo[$clave];
        }

        // Con cifras: la plantilla "Quedan {0} dias".
        $plantilla = $clave;
        $valores = [];

        if (preg_match('/\d/', $clave)) {
            // Las modalidades (1v1, 2v2, 3v3) son parte del texto, no cifras: sus
            // digitos se esconden detras de caracteres privados mientras se
            // sacan los numeros de verdad.
            $protegido = preg_replace_callback('/(?<![\w])(\d)v(\d)(?![\w])/u', fn (array $m) => mb_chr(0xE100 + (int) $m[1]) . 'v' . mb_chr(0xE100 + (int) $m[2]), $clave);

            $plantilla = preg_replace_callback('/\d+(?:[.,]\d+)?/u', function (array $m) use (&$valores) {
                $valores[] = $m[0];

                return '{' . (count($valores) - 1) . '}';
            }, $protegido);

            $plantilla = preg_replace_callback('/[\x{E100}-\x{E109}]/u', fn (array $m) => (string) (mb_ord($m[0]) - 0xE100), $plantilla);

            if (isset($catalogo[$plantilla]) && $catalogo[$plantilla] !== '') {
                return preg_replace_callback('/\{(\d+)\}/', fn (array $m) => $valores[(int) $m[1]] ?? $m[0], $catalogo[$plantilla]);
            }
        }

        $this->anotar($plantilla);

        return null;
    }

    private function normalizar(string $texto): string
    {
        return trim(preg_replace('/[ \t\r\n\f]+/u', ' ', $texto) ?? $texto);
    }

    // -------------------------------------------------------------------- HTML

    public function html(string $html): string
    {
        if (!$this->activo() || $html === '') {
            return $html;
        }

        $partes = preg_split(
            '/(<!--.*?-->|<script\b[^>]*>.*?<\/script\s*>|<style\b[^>]*>.*?<\/style\s*>|<[^>]+>)/is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($partes === false) {
            return $html;
        }

        $salida = '';
        // La zona sin traducir en la que estamos, si estamos en una.
        $opaca = null;
        $profundidad = 0;
        $total = count($partes);

        for ($i = 0; $i < $total; $i++) {
            $parte = $partes[$i];

            if ($parte === '') {
                continue;
            }

            // Los trozos impares son los delimitadores (etiquetas, scripts, comentarios).
            if ($i % 2 === 1) {
                if (str_starts_with($parte, '<!--')) {
                    $salida .= $parte;
                } elseif (preg_match('/^<script\b/i', $parte)) {
                    $salida .= $opaca === null ? $this->script($parte) : $parte;
                } elseif (preg_match('/^<style\b/i', $parte)) {
                    $salida .= $parte;
                } else {
                    [$etiqueta, $nombre, $cierra, $autocierra] = $this->leerEtiqueta($parte);

                    if ($opaca !== null) {
                        if ($nombre === $opaca['nombre'] && !$autocierra) {
                            $profundidad += $cierra ? -1 : 1;

                            if ($profundidad <= 0) {
                                $opaca = null;
                                $profundidad = 0;
                            }
                        }

                        $salida .= $parte;

                        continue;
                    }

                    if (!$cierra && !$autocierra && ($this->esOpaca($nombre) || $this->noTraducir($parte))) {
                        $opaca = ['nombre' => $nombre];
                        $profundidad = 1;
                        $salida .= $parte;

                        continue;
                    }

                    // Una frase con negritas dentro ("Si lo <b>confirma</b>, ...")
                    // se traduce ENTERA: partida en trozos no se puede reordenar
                    // en otro idioma.
                    if (!$cierra && !$autocierra && ($frase = $this->fraseConMarcas($partes, $i, $nombre)) !== null) {
                        $salida .= $this->atributos($parte, $nombre) . $frase['html'] . $partes[$frase['cierre']];
                        $i = $frase['cierre'];

                        continue;
                    }

                    $salida .= $this->atributos($parte, $nombre);
                }

                continue;
            }

            // Un trozo par es texto. Dentro de una zona opaca se deja tal cual.
            $salida .= $opaca !== null ? $parte : $this->texto($parte);
        }

        return $salida;
    }

    /** Etiquetas de enfasis que pueden ir dentro de una frase sin atributos. */
    private const MARCAS = ['b', 'strong', 'em', 'i', 'u', 'small', 'br'];

    /**
     * Una frase con enfasis dentro: <p>Si lo <b>confirma</b>, ...</p>.
     *
     * Mira lo que hay dentro del elemento que se abre en $inicio. Si es solo
     * texto y marcas de enfasis sin atributos, y el catalogo trae esa frase
     * entera -con sus marcas-, devuelve la traduccion y donde cierra el
     * elemento. Si no, null y se traduce trozo a trozo como siempre.
     *
     * @param  array<int, string>  $partes
     * @return array{html: string, cierre: int}|null
     */
    private function fraseConMarcas(array $partes, int $inicio, string $nombre): ?array
    {
        if (in_array($nombre, self::MARCAS, true) || $nombre === '') {
            return null;
        }

        $dentro = '';
        $marcas = 0;
        $nivel = 0;
        $total = count($partes);

        for ($j = $inicio + 1; $j < $total; $j++) {
            $parte = $partes[$j];

            if ($parte === '') {
                continue;
            }

            if ($j % 2 === 0) {
                $dentro .= $parte;

                continue;
            }

            if (!preg_match('/^<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)\s*(\/?)>$/', $parte, $m)) {
                // Una etiqueta con atributos, un script, un comentario: no es una frase simple.
                if (preg_match('/^<\s*\/\s*' . preg_quote($nombre, '/') . '\s*>$/i', $parte) && $nivel === 0) {
                    break;
                }

                return null;
            }

            $etiqueta = strtolower($m[2]);

            if ($m[1] === '/' && $etiqueta === $nombre && $nivel === 0) {
                break;
            }

            if (!in_array($etiqueta, self::MARCAS, true)) {
                return null;
            }

            $marcas++;
            $dentro .= '<' . $m[1] . $etiqueta . ($m[3] === '/' ? '/' : '') . '>';
        }

        if ($j >= $total || $marcas === 0) {
            return null;
        }

        $clave = trim(preg_replace('/\s+/u', ' ', html_entity_decode($dentro, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $dentro);

        if (!preg_match('/\p{L}{2}/u', strip_tags($clave))) {
            return null;
        }

        $traduccion = $this->frase($clave);

        if ($traduccion === null) {
            $this->anotar($clave, true);

            return null;
        }

        // Los espacios de los bordes se conservan: el HTML de alrededor los usa.
        preg_match('/^(\s*)/u', $dentro, $antes);
        preg_match('/(\s*)$/u', $dentro, $despues);

        return [
            'html' => ($antes[1] ?? '') . $this->escaparConMarcas($traduccion) . ($despues[1] ?? ''),
            'cierre' => $j,
        ];
    }

    /** Escapa el texto de una traduccion respetando solo las marcas de enfasis. */
    private function escaparConMarcas(string $traduccion): string
    {
        $trozos = preg_split('/(<\/?(?:b|strong|em|i|u|small)>|<br\s*\/?>)/i', $traduccion, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$traduccion];

        return implode('', array_map(
            fn (int $k, string $t) => $k % 2 === 1 ? $t : htmlspecialchars($t, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', false),
            array_keys($trozos),
            $trozos
        ));
    }

    /** @return array{0: string, 1: string, 2: bool, 3: bool} */
    private function leerEtiqueta(string $etiqueta): array
    {
        if (!preg_match('/^<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9:-]*)/', $etiqueta, $m)) {
            return [$etiqueta, '', false, true];
        }

        $nombre = strtolower($m[2]);
        $autocierra = str_ends_with(rtrim($etiqueta, " \t\r\n>"), '/')
            || in_array($nombre, ['br', 'hr', 'img', 'input', 'meta', 'link', 'source', 'wbr', 'path', 'circle', 'rect', 'line', 'use', 'stop', 'polygon', 'polyline', 'ellipse'], true);

        return [$etiqueta, $nombre, $m[1] === '/', $autocierra];
    }

    private function esOpaca(string $nombre): bool
    {
        return in_array($nombre, self::OPACAS, true);
    }

    private function noTraducir(string $etiqueta): bool
    {
        return (bool) preg_match('/\stranslate\s*=\s*["\']?no\b|\sclass\s*=\s*["\'][^"\']*\bnotranslate\b/i', $etiqueta);
    }

    private function texto(string $crudo): string
    {
        if (!preg_match('/\p{L}/u', $crudo)) {
            return $crudo;
        }

        preg_match('/^(\s*)(.*?)(\s*)$/su', $crudo, $m);
        [, $antes, $nucleo, $despues] = $m + [1 => '', 2 => $crudo, 3 => ''];

        if ($nucleo === '') {
            return $crudo;
        }

        $traduccion = $this->frase(html_entity_decode($nucleo, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($traduccion === null) {
            return $crudo;
        }

        return $antes . htmlspecialchars($traduccion, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', false) . $despues;
    }

    private function atributos(string $etiqueta, string $nombre): string
    {
        if (!str_contains($etiqueta, '=')) {
            return $etiqueta;
        }

        return preg_replace_callback(
            '/(\s)([a-zA-Z_:][-a-zA-Z0-9_:.]*)(\s*=\s*)(?:"([^"]*)"|\'([^\']*)\')/',
            function (array $m) use ($etiqueta, $nombre) {
                $atributo = strtolower($m[2]);
                $comilla = isset($m[5]) && $m[5] !== '' && !isset($m[4]) ? "'" : '"';
                $valor = $m[4] ?? ($m[5] ?? '');

                if (!$this->atributoTraducible($atributo, $valor, $etiqueta, $nombre)) {
                    return $m[0];
                }

                $traduccion = $this->frase(html_entity_decode($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($traduccion === null) {
                    return $m[0];
                }

                return $m[1] . $m[2] . $m[3] . $comilla . htmlspecialchars($traduccion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false) . $comilla;
            },
            $etiqueta
        ) ?? $etiqueta;
    }

    private function atributoTraducible(string $atributo, string $valor, string $etiqueta, string $nombre): bool
    {
        if (!preg_match('/\p{L}/u', $valor)) {
            return false;
        }

        if (in_array($atributo, self::ATRIBUTOS, true)) {
            return true;
        }

        // Los meta de la cabecera: descripcion y lo que se ve al compartir.
        if ($atributo === 'content' && $nombre === 'meta') {
            return (bool) preg_match('/(?:name|property)\s*=\s*["\'](?:description|og:title|og:description|og:image:alt|twitter:title|twitter:description|twitter:image:alt)["\']/i', $etiqueta);
        }

        // value de un boton.
        if ($atributo === 'value' && $nombre === 'input') {
            return (bool) preg_match('/type\s*=\s*["\'](?:submit|button|reset)["\']/i', $etiqueta);
        }

        // Los data-* con una frase: el JavaScript los muestra tal cual.
        return str_starts_with($atributo, 'data-') && preg_match('/[\s¿¡]/u', $valor) === 1 && mb_strlen($valor) > 8;
    }

    // ----------------------------------------------------------------- scripts

    private function script(string $bloque): string
    {
        if (!preg_match('/^(<script\b[^>]*>)(.*)(<\/script\s*>)$/is', $bloque, $m)) {
            return $bloque;
        }

        [, $abre, $cuerpo, $cierra] = $m;

        if (preg_match('/\bsrc\s*=/i', $abre) && trim($cuerpo) === '') {
            return $bloque;
        }

        if (preg_match('/type\s*=\s*["\']application\/(?:ld\+)?json["\']/i', $abre)) {
            // Datos: solo los JSON de la propia pagina; el ld+json es para buscadores.
            if (preg_match('/ld\+json/i', $abre)) {
                return $bloque;
            }

            $datos = json_decode($cuerpo, true);

            if (!is_array($datos)) {
                return $bloque;
            }

            return $abre . json_encode($this->datos($datos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . $cierra;
        }

        if (preg_match('/type\s*=\s*["\'](?!module|text\/javascript)/i', $abre)) {
            return $bloque;
        }

        return $abre . $this->literales($cuerpo) . $cierra;
    }

    /**
     * Las frases entre comillas dentro de un script.
     *
     * Solo las que parecen texto para una persona -con espacios, tildes o
     * signos- y estan en el catalogo palabra por palabra: una clave de objeto o
     * una clase CSS no se parece a ninguna frase.
     */
    private function literales(string $js): string
    {
        return preg_replace_callback(
            '/(["\'])((?:\\\\.|(?!\1)[^\\\\\n])*)\1/u',
            function (array $m) {
                [$completo, $comilla, $interior] = $m;

                if (!preg_match('/[\s¿¡áéíóúñÁÉÍÓÚÑ]/u', $interior) || !preg_match('/\p{L}{2}/u', $interior)) {
                    return $completo;
                }

                // \u00f3 -> ó, y despues el resto de escapes (\n, \', \").
                $texto = stripcslashes(preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn (array $u) => mb_chr(hexdec($u[1])) ?: $u[0], $interior));

                // HTML que el script arma en el navegador: se traduce como HTML.
                $traduccion = str_contains($texto, '<') && str_contains($texto, '>')
                    ? $this->html($texto)
                    : $this->frase($texto);

                if ($traduccion === null || $traduccion === $texto) {
                    return $completo;
                }

                // El espacio de los bordes ('No se pudo cargar ' + url) es del codigo.
                preg_match('/^(\s*)/u', $texto, $antes);
                preg_match('/(\s*)$/u', $texto, $despues);
                $traduccion = ($antes[1] ?? '') . trim($traduccion) . ($despues[1] ?? '');

                return $comilla . addcslashes($traduccion, "\\" . $comilla . "\n\r") . $comilla;
            },
            $js
        ) ?? $js;
    }

    // -------------------------------------------------------------------- JSON

    /**
     * Los textos de una respuesta JSON. Si un valor es HTML se traduce como HTML.
     */
    public function datos(mixed $valor, ?string $clave = null): mixed
    {
        if (is_array($valor)) {
            foreach ($valor as $k => $contenido) {
                $valor[$k] = $this->datos($contenido, is_string($k) ? $k : $clave);
            }

            return $valor;
        }

        // Un objeto JSON ({}): se recorre sin convertirlo en lista.
        if (is_object($valor)) {
            foreach (get_object_vars($valor) as $k => $contenido) {
                $valor->{$k} = $this->datos($contenido, (string) $k);
            }

            return $valor;
        }

        // Nombres y notas que escribio una persona no son texto de la interfaz:
        // un personaje llamado "Rival" no puede salir como "Opponent".
        if ($clave !== null && preg_match('/^(character_?name|player_?name|display_?name|leader_?name|username|name|nombre|note|nota)$/i', $clave)) {
            return $valor;
        }

        if (!is_string($valor) || $valor === '' || !preg_match('/\p{L}/u', $valor)) {
            return $valor;
        }

        if (str_contains($valor, '<') && str_contains($valor, '>')) {
            return $this->html($valor);
        }

        return $this->frase($valor) ?? $valor;
    }

    // --------------------------------------------------------------- grabacion

    /**
     * Anota lo que no esta en el catalogo, para saber que falta traducir.
     * Solo si se activa ARENA_I18N_RECORD: en produccion no escribe nada.
     */
    private function anotar(string $clave, bool $conMarcas = false): void
    {
        if (!config('arena.i18n_record') || mb_strlen($clave) > 700) {
            return;
        }

        // Lo que no es una frase: sin tres letras seguidas, identificadores,
        // codigos y trozos de codigo. Solo estorbarian en la lista.
        $limpia = preg_replace('/\{\d+\}/', '', $conMarcas ? strip_tags($clave) : $clave);

        if (!preg_match('/\p{L}{3}/u', $limpia)
            || preg_match('/^[0-9a-fA-F]{10,}$/', $clave)
            || preg_match('/^[0-9a-fA-F{}]+$/', $clave)
            || preg_match('/[\[\];={}<>]/', $limpia) && !$conMarcas
            || preg_match('/^[A-Za-z0-9_\-\.#:\/\[\]\(\)]+$/', $clave) && !preg_match('/\s/', $clave) && !preg_match('/^[A-ZÁÉÍÓÚ][a-záéíóúñ]+$/u', $clave)) {
            return;
        }

        self::$ausentes[$clave] = true;

        if (!self::$registradoCierre) {
            self::$registradoCierre = true;
            app()->terminating(fn () => self::volcarAusentes());
        }
    }

    private static function volcarAusentes(): void
    {
        if (self::$ausentes === []) {
            return;
        }

        $ruta = (string) config('arena.i18n_record_file', storage_path('app/i18n-pendientes.json'));
        $actual = is_file($ruta) ? (json_decode((string) file_get_contents($ruta), true) ?: []) : [];

        foreach (array_keys(self::$ausentes) as $clave) {
            $actual[$clave] = $actual[$clave] ?? true;
        }

        @file_put_contents($ruta, json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        self::$ausentes = [];
    }
}
