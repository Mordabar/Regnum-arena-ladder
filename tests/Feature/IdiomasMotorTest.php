<?php

use App\Support\I18n\Idioma;
use App\Support\I18n\Traductor;
use Illuminate\Http\Request;

/**
 * El motor de traduccion: lo que cambia, lo que NO toca y como elige idioma.
 *
 * Usan un catalogo de prueba en una carpeta temporal, para no depender de las
 * traducciones reales (esas se comprueban en IdiomasCoberturaTest).
 */
beforeEach(function () {
    $this->carpeta = sys_get_temp_dir() . '/arena-lang-' . uniqid();
    mkdir($this->carpeta);
    $this->originalLang = app()->langPath();
    app()->useLangPath($this->carpeta);
    Traductor::olvidar();
});

afterEach(function () {
    foreach (glob($this->carpeta . '/*') as $f) { @unlink($f); }
    @rmdir($this->carpeta);
    app()->useLangPath($this->originalLang);
    Traductor::olvidar();
});

function catalogoDePrueba(array $en): void
{
    file_put_contents(lang_path('en.json'), json_encode($en, JSON_UNESCAPED_UNICODE));
    Traductor::olvidar();
}

function en(): Traductor
{
    return Traductor::para('en');
}

// ------------------------------------------------------------------ idioma

it('normaliza los codigos de idioma', function () {
    expect(Idioma::normalizar('pt-BR'))->toBe('pt')
        ->and(Idioma::normalizar('EN_us'))->toBe('en')
        ->and(Idioma::normalizar('de'))->toBe('de')
        ->and(Idioma::normalizar('ja'))->toBeNull()
        ->and(Idioma::normalizar(['en']))->toBeNull()
        ->and(Idioma::normalizar(null))->toBeNull()
        ->and(Idioma::normalizar(''))->toBeNull();
});

it('elige el idioma: lo pedido, luego la cookie, luego el navegador, luego español', function () {
    config(['arena.i18n_detect_browser' => true]);

    // Request::create pone un Accept-Language de ingles por defecto: se vacia.
    $peticion = fn (array $query = [], array $cookies = [], string $cabecera = '') => Request::create('/', 'GET', $query, $cookies, [], ['HTTP_ACCEPT_LANGUAGE' => $cabecera]);

    expect(Idioma::detectar($peticion(['lang' => 'fr'], [Idioma::COOKIE => 'de'], 'en')))->toBe('fr')
        ->and(Idioma::detectar($peticion([], [Idioma::COOKIE => 'de'], 'en')))->toBe('de')
        ->and(Idioma::detectar($peticion([], [], 'nl-NL,nl;q=0.9,en;q=0.8')))->toBe('nl')
        ->and(Idioma::detectar($peticion([], [], 'ja,zh;q=0.9')))->toBe('es')
        ->and(Idioma::detectar($peticion()))->toBe('es')
        // Un valor raro no rompe: se ignora.
        ->and(Idioma::detectar($peticion(['lang' => 'klingon'], [Idioma::COOKIE => 'xx'])))->toBe('es');
});

it('respeta el orden de preferencia del navegador', function () {
    $r = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'ja;q=1, fr;q=0.4, pt-BR;q=0.8, en;q=0.2']);

    expect(Idioma::delNavegador($r))->toBe('pt');
});

// ------------------------------------------------------------------- texto

it('traduce el texto entre etiquetas y conserva el espaciado', function () {
    catalogoDePrueba(['Entrar al lobby' => 'Enter the lobby']);

    expect(en()->html("<a>\n    Entrar al lobby\n</a>"))->toBe("<a>\n    Enter the lobby\n</a>");
});

it('lo que no esta en el catalogo se queda como esta', function () {
    catalogoDePrueba(['Hola' => 'Hello']);

    expect(en()->html('<p>Esto no esta traducido</p>'))->toBe('<p>Esto no esta traducido</p>');
});

it('el español no se toca ni con catalogo', function () {
    catalogoDePrueba(['Hola' => 'Hello']);

    expect(Traductor::para('es')->html('<p>Hola</p>'))->toBe('<p>Hola</p>');
});

it('las cifras se sacan y se devuelven en su sitio', function () {
    catalogoDePrueba(['Quedan {0} días' => '{0} days left', 'Día {0}' => 'Day {0}', 'Del {0} al {1}' => 'From {1} to {0}']);

    expect(en()->html('<span>Quedan 56 días</span>'))->toBe('<span>56 days left</span>')
        ->and(en()->html('<b>Día 181</b>'))->toBe('<b>Day 181</b>')
        ->and(en()->html('<i>Del 3 al 9</i>'))->toBe('<i>From 9 to 3</i>');
});

it('las modalidades 1v1, 2v2 y 3v3 no cuentan como cifras', function () {
    catalogoDePrueba(['Entrar al duelo 1v1' => 'Join the 1v1 duel', 'Ganas {0} PL en 3v3' => 'You earn {0} PL in 3v3']);

    expect(en()->html('<button>Entrar al duelo 1v1</button>'))->toBe('<button>Join the 1v1 duel</button>')
        ->and(en()->html('<p>Ganas 12 PL en 3v3</p>'))->toBe('<p>You earn 12 PL in 3v3</p>');
});

it('las entidades HTML se leen y se escapan bien', function () {
    catalogoDePrueba(['Tú & yo' => 'You & me', 'Rápido <ya>' => 'Fast <now>']);

    expect(en()->html('<p>T&uacute; &amp; yo</p>'))->toBe('<p>You &amp; me</p>')
        // Una traduccion nunca puede abrir una etiqueta.
        ->and(en()->html('<p>R&aacute;pido &lt;ya&gt;</p>'))->toBe('<p>Fast &lt;now&gt;</p>');
});

it('traduce frases con negritas enteras para poder reordenarlas', function () {
    catalogoDePrueba([
        'Si lo <b>confirma</b>, el resultado se cierra.' => 'If they <b>confirm</b> it, the result is closed.',
        'Si lo' => 'WRONG', 'confirma' => 'WRONG', ', el resultado se cierra.' => 'WRONG',
    ]);

    expect(en()->html('<li class="x">Si lo <b>confirma</b>, el resultado se cierra.</li>'))
        ->toBe('<li class="x">If they <b>confirm</b> it, the result is closed.</li>');
});

it('sin la frase entera, cae al trozo a trozo', function () {
    catalogoDePrueba(['Aceptar' => 'Accept']);

    expect(en()->html('<p><b>Aceptar</b></p>'))->toBe('<p><b>Accept</b></p>');
});

// ---------------------------------------------------------------- atributos

it('traduce los atributos que lee una persona', function () {
    catalogoDePrueba(['Cerrar' => 'Close', 'Buscar' => 'Search', 'Escudo' => 'Shield', 'Descripción' => 'Description']);

    $html = '<button title="Cerrar" aria-label="Cerrar" data-x="no">x</button><input placeholder="Buscar"><img alt="Escudo" src="Cerrar.png"><meta name="description" content="Descripción">';
    $esperado = '<button title="Close" aria-label="Close" data-x="no">x</button><input placeholder="Search"><img alt="Shield" src="Cerrar.png"><meta name="description" content="Description">';

    expect(en()->html($html))->toBe($esperado);
});

it('los atributos que no son texto no se tocan', function () {
    catalogoDePrueba(['Cerrar' => 'Close']);

    expect(en()->html('<a href="Cerrar" class="Cerrar" id="Cerrar" name="Cerrar" value="Cerrar">x</a>'))
        ->toBe('<a href="Cerrar" class="Cerrar" id="Cerrar" name="Cerrar" value="Cerrar">x</a>');
});

// ------------------------------------------------------------ zonas opacas

it('no traduce dentro de code, pre, textarea ni translate="no"', function () {
    catalogoDePrueba(['Hola' => 'Hello']);

    $html = '<code>Hola</code><pre>Hola</pre><textarea>Hola</textarea><span translate="no">Hola</span><div class="a notranslate">Hola <b>Hola</b></div><p>Hola</p>';

    expect(en()->html($html))->toBe('<code>Hola</code><pre>Hola</pre><textarea>Hola</textarea><span translate="no">Hola</span><div class="a notranslate">Hola <b>Hola</b></div><p>Hello</p>');
});

it('una zona translate="no" anidada cierra donde debe', function () {
    catalogoDePrueba(['Hola' => 'Hello']);

    expect(en()->html('<div translate="no"><div>Hola</div>Hola</div><p>Hola</p>'))
        ->toBe('<div translate="no"><div>Hola</div>Hola</div><p>Hello</p>');
});

it('los comentarios y los estilos no se tocan', function () {
    catalogoDePrueba(['Hola' => 'Hello']);

    expect(en()->html('<!-- Hola --><style>.a:after{content:"Hola"}</style><p>Hola</p>'))
        ->toBe('<!-- Hola --><style>.a:after{content:"Hola"}</style><p>Hello</p>');
});

// ------------------------------------------------------------------ scripts

it('traduce las frases entre comillas de un script, y solo las frases', function () {
    catalogoDePrueba(['Vas muy rápido. Espera.' => 'You are too fast. Wait.', 'camino' => 'WRONG', 'tarde' => 'WRONG']);

    $js = "<script>var a = 'Vas muy rápido. Espera.'; var b = 'camino'; x.tono = \"tarde\"; // 'Vas muy rápido. Espera.' en un comentario\n</script>";
    $salida = en()->html($js);

    expect($salida)->toContain("var a = 'You are too fast. Wait.';")
        ->and($salida)->toContain("var b = 'camino';")
        ->and($salida)->toContain('x.tono = "tarde";');
});

it('escapa bien las comillas de la traduccion en un script', function () {
    catalogoDePrueba(['No se pudo mandar el aviso.' => "Couldn't send the notice."]);

    expect(en()->html("<script>t('No se pudo mandar el aviso.');</script>"))
        ->toBe("<script>t('Couldn\\'t send the notice.');</script>");
});

it('traduce el HTML que un script arma en el navegador', function () {
    catalogoDePrueba(['Error en la búsqueda.' => 'Search error.']);

    expect(en()->html("<script>r.innerHTML = '<div class=\"e\">Error en la búsqueda.</div>';</script>"))
        ->toBe("<script>r.innerHTML = '<div class=\"e\">Search error.</div>';</script>");
});

it('los scripts externos y el ld+json no se tocan, el json de datos si', function () {
    catalogoDePrueba(['Voy en camino' => 'On my way']);

    $ext = '<script src="/a.js"></script>';
    $ld = '<script type="application/ld+json">{"name":"Voy en camino"}</script>';
    $datos = '<script type="application/json" data-pings-seed>[{"texto":"Voy en camino","icono":"🏃"}]</script>';

    expect(en()->html($ext . $ld))->toBe($ext . $ld)
        ->and(en()->html($datos))->toContain('"texto":"On my way"')
        ->and(en()->html($datos))->toContain('🏃');
});

// --------------------------------------------------------------------- JSON

it('traduce los textos de una respuesta JSON, y el HTML que lleve dentro', function () {
    catalogoDePrueba(['Todo listo' => 'All set', 'Aceptar' => 'Accept']);

    $salida = en()->datos(['mensaje' => 'Todo listo', 'html' => '<button>Aceptar</button>', 'n' => 5, 'lista' => ['Todo listo', 'otro'], 'nombre' => 'Ashka']);

    expect($salida)->toBe(['mensaje' => 'All set', 'html' => '<button>Accept</button>', 'n' => 5, 'lista' => ['All set', 'otro'], 'nombre' => 'Ashka']);
});

// ------------------------------------------------------------- grabacion

it('anota lo que falta solo cuando se pide, y sin ruido', function () {
    $archivo = $this->carpeta . '/pendientes.json';
    config(['arena.i18n_record' => true, 'arena.i18n_record_file' => $archivo]);
    catalogoDePrueba([]);

    en()->html('<p>Una frase sin traducir</p><p>1017 MMR</p><p>a3f9c1e2b4d5f6a7</p><p>ARENA-9999</p><span>12</span>');
    app()->terminate();

    $anotadas = array_keys(json_decode((string) file_get_contents($archivo), true));

    expect($anotadas)->toContain('Una frase sin traducir')
        ->and($anotadas)->not->toContain('a3f9c1e2b4d5f6a7')
        ->and($anotadas)->not->toContain('12');
});

// ------------------------------------------------------------ arbitraje

it('un objeto JSON vacio sigue siendo un objeto', function () {
    catalogoDePrueba(['Hola' => 'Hello']);

    $datos = json_decode('{"obj":{},"lista":[],"mapa":{"0":"Hola","1":"x"},"texto":"Hola"}', false);
    $fuera = json_encode(en()->datos($datos));

    expect($fuera)->toContain('"obj":{}')
        ->and($fuera)->toContain('"lista":[]')
        ->and($fuera)->toContain('"mapa":{"0":"Hello"')
        ->and($fuera)->toContain('"texto":"Hello"');
});

it('los nombres que escribio una persona no se traducen en JSON', function () {
    catalogoDePrueba(['Rival' => 'Opponent']);

    $fuera = en()->datos(['character_name' => 'Rival', 'etiqueta' => 'Rival', 'zone_name' => 'Rival']);

    expect($fuera['character_name'])->toBe('Rival')
        ->and($fuera['etiqueta'])->toBe('Opponent')
        ->and($fuera['zone_name'])->toBe('Opponent');
});

it('un nombre marcado translate=no no se traduce en el HTML', function () {
    catalogoDePrueba(['Cazador' => 'Hunter']);

    expect(en()->html('<span translate="no">Cazador</span> <i>Cazador</i>'))
        ->toBe('<span translate="no">Cazador</span> <i>Hunter</i>');
});

it('los literales de script conservan el espacio de los bordes', function () {
    catalogoDePrueba(['No se pudo cargar' => 'Could not load']);

    expect(en()->html("<script>x('No se pudo cargar ' + u)</script>"))
        ->toBe("<script>x('Could not load ' + u)</script>");
});

it('los mensajes del servidor bajo motivo se traducen y los nombres no', function () {
    catalogoDePrueba(['Vas muy rapido.' => 'Too fast.', 'Rival' => 'Opponent']);

    $fuera = en()->datos(['motivo' => 'Vas muy rapido.', 'players' => [['character_name' => 'Rival', 'rol' => 'Rival']]]);

    expect($fuera['motivo'])->toBe('Too fast.')
        ->and($fuera['players'][0]['character_name'])->toBe('Rival')
        ->and($fuera['players'][0]['rol'])->toBe('Opponent');
});

it('el placeholder de un textarea se traduce y su contenido no', function () {
    catalogoDePrueba(['Comentario opcional' => 'Optional comment', 'Hola' => 'Hello']);

    expect(en()->html('<textarea placeholder="Comentario opcional">Hola</textarea>'))
        ->toBe('<textarea placeholder="Optional comment">Hola</textarea>');
});
