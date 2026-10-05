<?php

use App\Models\User;
use App\Support\I18n\Idioma;
use App\Support\I18n\Traductor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El idioma de punta a punta: elegir, recordar, traducir y no tocar lo que no se
 * debe (el panel de administracion, las redirecciones).
 */
beforeEach(function () {
    $this->carpeta = sys_get_temp_dir() . '/arena-lang-sitio-' . uniqid();
    mkdir($this->carpeta);
    $this->originalLang = app()->langPath();
    app()->useLangPath($this->carpeta);

    file_put_contents($this->carpeta . '/en.json', json_encode([
        'Entrar con Discord' => 'Log in with Discord',
        'Ver ladder' => 'View ladder',
    ], JSON_UNESCAPED_UNICODE));
    Traductor::olvidar();
});

afterEach(function () {
    foreach (glob($this->carpeta . '/*') as $f) { is_file($f) && @unlink($f); }
    @rmdir($this->carpeta);
    app()->useLangPath($this->originalLang);
    Traductor::olvidar();
    app()->setLocale('es');
});

it('sin pedir nada la web sale en español, tal cual', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Entrar con Discord')
        ->assertSee('<html lang="es"', false)
        ->assertHeader('Content-Language', 'es');
});

it('?lang=en traduce la pagina, cambia el html lang y recuerda la eleccion', function () {
    $this->get(route('home', ['lang' => 'en']))
        ->assertOk()
        ->assertSee('Log in with Discord')
        ->assertDontSee('Entrar con Discord')
        ->assertSee('<html lang="en"', false)
        ->assertHeader('Content-Language', 'en')
        ->assertCookie(Idioma::COOKIE, 'en');
});

it('con la cookie sigue en ingles sin ?lang', function () {
    $this->withCookie(Idioma::COOKIE, 'en')->get(route('home'))
        ->assertOk()
        ->assertSee('Log in with Discord');
});

it('la primera vez usa el idioma del navegador, y no lo guarda', function () {
    config(['arena.i18n_detect_browser' => true]);

    $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')->get(route('home'))
        ->assertOk()
        ->assertSee('Log in with Discord')
        ->assertCookieMissing(Idioma::COOKIE);
});

it('un idioma que no existe se ignora y no rompe', function () {
    $this->get(route('home', ['lang' => 'klingon']))
        ->assertOk()
        ->assertSee('Entrar con Discord')
        ->assertCookieMissing(Idioma::COOKIE);
});

it('un navegador en un idioma que no tenemos recibe español', function () {
    config(['arena.i18n_detect_browser' => true]);

    $this->withHeader('Accept-Language', 'ja,zh;q=0.8')->get(route('home'))
        ->assertOk()
        ->assertSee('Entrar con Discord');
});

it('el panel de administracion se queda en español aunque se pida otro idioma', function () {
    $this->withCookie(Idioma::COOKIE, 'en')->get('/panel-local')->assertOk();

    $this->withSession(sesionDeAdmin())->withCookie(Idioma::COOKIE, 'en')
        ->get(route('admin.settings', ['lang' => 'en']))
        ->assertOk()
        ->assertSee('Modalidades abiertas')
        ->assertDontSee('arena-lang-fab', false);
});

it('las redirecciones no se tocan', function () {
    $this->withCookie(Idioma::COOKIE, 'en')->get(route('lobby'))->assertRedirect();
});

it('las respuestas JSON tambien se traducen', function () {
    file_put_contents($this->carpeta . '/en.json', json_encode(['Todavia no hay nada.' => 'Nothing yet.'], JSON_UNESCAPED_UNICODE));
    Traductor::olvidar();

    $usuario = User::create(['discord_id' => 'idi-1', 'discord_username' => 'idi', 'name' => 'Idi', 'email' => 'idi@example.com']);

    Route::middleware('web')->get('/__prueba-json', fn () => response()->json(['mensaje' => 'Todavia no hay nada.', 'nombre' => 'Ashka']));

    $this->getJson('/__prueba-json?lang=en')
        ->assertOk()
        ->assertJson(['mensaje' => 'Nothing yet.', 'nombre' => 'Ashka']);

    $this->getJson('/__prueba-json')->assertJson(['mensaje' => 'Todavia no hay nada.']);
});

it('la respuesta avisa de que varia segun el idioma', function () {
    $r = $this->get(route('home'));

    expect($r->headers->get('Vary'))->toContain('Cookie')->toContain('Accept-Language');
});

// ------------------------------------------------------------ el selector

it('el selector lista los seis idiomas con enlaces de verdad y marca el actual', function () {
    $r = $this->get(route('ladder.index', ['lang' => 'en']))->assertOk();

    foreach (Idioma::IDIOMAS as $codigo => $datos) {
        $r->assertSee('hreflang="' . $datos['html'] . '"', false)
            ->assertSee($datos['nombre']);
        $r->assertSee('lang=' . $codigo, false);
    }

    $r->assertSee('arena-lang-fab', false)
        ->assertSee('aria-current="true"', false)
        ->assertSee('flag-pt', false);
});

it('los enlaces del selector conservan el resto de la direccion', function () {
    $this->get('/ladder?pagina=2&lang=en')->assertOk()
        ->assertSee('pagina=2&amp;lang=de', false);
});

it('las paginas publicas anuncian sus versiones a los buscadores', function () {
    $r = $this->get(route('home'))->assertOk();

    foreach (['es', 'en', 'pt-BR', 'de', 'fr', 'nl'] as $html) {
        $r->assertSee('hreflang="' . $html . '" href=', false);
    }

    $r->assertSee('hreflang="x-default"', false);
});

it('cada idioma tiene su canonical y su og:locale', function () {
    $this->get(route('home', ['lang' => 'pt']))->assertOk()
        ->assertSee('<link rel="canonical" href="' . url('/') . '?lang=pt">', false)
        ->assertSee('content="pt_BR"', false)
        ->assertSee('<html lang="pt-BR"', false);
});

it('las paginas privadas no ofrecen versiones a los buscadores', function () {
    $this->actingAs(User::create(['discord_id' => 'idi-2', 'discord_username' => 'idi2', 'name' => 'Idi2', 'email' => 'idi2@example.com']))
        ->get(route('lobby'))->assertOk()
        ->assertDontSee('hreflang="x-default"', false);
});
