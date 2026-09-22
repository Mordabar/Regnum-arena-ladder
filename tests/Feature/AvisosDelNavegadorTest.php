<?php

use App\Models\ArenaMatch;
use App\Models\MatchPing;
use App\Models\Player;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\AvisosPendientesService;
use App\Services\WebPushService;
use App\Support\Base64Url;
use App\Support\VapidKeys;
use App\Support\ArenaMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Las pruebas hablan con un servicio de push inventado. En produccion la lista
// de servicios es fija (Google, Mozilla, Apple, Microsoft); aqui se añade este.
beforeEach(function () {
    config(['services.webpush.hosts_extra' => 'push.example']);
});

/**
 * Los avisos que llegan con la pestaña cerrada.
 *
 * Esto existe porque el sondeo de la pagina NO puede resolverlo: el navegador
 * congela las pestañas que no estan delante. La unica via es el push, y el
 * push tiene dos partes que fallan en silencio -la firma y lo que se anuncia-,
 * asi que las dos se comprueban aqui.
 */
function jugadorPush(string $nombre, string $realm = 'ignis'): Player
{
    $user = User::create([
        'discord_id' => 'push-' . $nombre,
        'discord_username' => 'push_' . $nombre,
        'name' => 'Push ' . $nombre,
        'email' => 'push-' . $nombre . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => $nombre,
        'subclass' => 'knight',
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

/** Un cruce con los dos jugadores dentro, en el estado que haga falta. */
function crucePush(Player $a, Player $b, string $estado, array $extra = []): ArenaMatch
{
    static $n = 9000;
    $n++;

    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    return ArenaMatch::create(array_merge([
        'match_code' => 'PU-' . $n,
        'report_token' => 'tokpu' . $n,
        'queue_mode' => 'random',
        'arena_mode' => ArenaMode::ONE_V_ONE,
        'team_a_realm' => $a->realm,
        'team_b_realm' => $b->realm,
        'team_a' => [$pack($a)],
        'team_b' => [$pack($b)],
        'zone' => 'frozen_bridge',
        'status' => $estado,
        'estimated_mmr_avg' => 1000,
        'player_count' => 2,
    ], $extra));
}

function conClavesDePrueba(): array
{
    $par = VapidKeys::generar();

    config([
        'services.webpush.public_key' => $par['publica'],
        'services.webpush.private_key' => $par['privada'],
        'services.webpush.subject' => 'mailto:pruebas@example.com',
    ]);

    return $par;
}

/* ── La firma ───────────────────────────────────────────────────────────── */

it('la clave privada guardada en una linea vuelve a ser una clave usable', function () {
    // El escalar se guarda en base64url porque un PEM son seis lineas y el
    // .env de produccion se edita por FTP. El PEM se rearma al vuelo, y si esa
    // plantilla DER se rompe, openssl no firma nada.
    $par = VapidKeys::generar();

    $clave = openssl_pkey_get_private(VapidKeys::pem($par['privada'], $par['publica']));

    expect($clave)->not->toBeFalse();

    $detalles = openssl_pkey_get_details($clave);

    expect($detalles['ec']['curve_name'])->toBe('prime256v1');

    // Y la publica que sale es la misma que se guardo: si no, el servicio de
    // push rechazaria cada envio con un 401 y nadie recibiria nada.
    $rearmada = Base64Url::encode(
        "\x04"
        . str_pad($detalles['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($detalles['ec']['y'], 32, "\x00", STR_PAD_LEFT)
    );

    expect($rearmada)->toBe($par['publica']);
});

it('la firma del aviso la puede comprobar quien lo recibe', function () {
    // Es LA comprobacion de todo esto. openssl firma en DER, de longitud
    // variable; JWS quiere la r y la s en crudo, 32 bytes cada una. Mandar el
    // DER tal cual da un 401 sin explicacion y sin forma de verlo desde aqui.
    //
    // Asi que se hace el camino entero: se firma como lo hace el servicio y se
    // verifica como lo hara Google o Mozilla, con la clave publica.
    $par = conClavesDePrueba();

    $metodo = new ReflectionMethod(WebPushService::class, 'cabeceraVapid');
    $metodo->setAccessible(true);
    $cabecera = $metodo->invoke(app(WebPushService::class), 'https://fcm.googleapis.com/fcm/send/xyz');

    expect($cabecera)->toMatch('/^vapid t=[\w\-\.]+, k=[\w\-]+$/');

    [$h, $p, $s] = explode('.', explode(' ', str_replace('vapid t=', '', $cabecera))[0]);
    $firma = Base64Url::decode(rtrim($s, ','));

    expect(strlen($firma))->toBe(64);

    // El destinatario es el SERVICIO de push, no el navegador: un token
    // firmado para Google no vale para Mozilla, y ahi esta la mitad de los
    // fallos de esto.
    expect(json_decode(Base64Url::decode($p), true)['aud'])->toBe('https://fcm.googleapis.com');

    // De vuelta a DER para verificar, que es lo que hace el que recibe.
    $entero = function (string $b): string {
        $b = ltrim($b, "\x00");
        if (ord($b[0]) > 0x7f) {
            $b = "\x00" . $b;
        }

        return "\x02" . chr(strlen($b)) . $b;
    };

    $der = $entero(substr($firma, 0, 32)) . $entero(substr($firma, 32, 32));
    $der = "\x30" . chr(strlen($der)) . $der;

    $pem = "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode(
            hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . Base64Url::decode($par['publica'])
        ), 64, "\n")
        . "-----END PUBLIC KEY-----\n";

    expect(openssl_verify("$h.$p", $der, openssl_pkey_get_public($pem), OPENSSL_ALGO_SHA256))->toBe(1);
});

it('sin claves configuradas no se intenta mandar nada', function () {
    // El sitio tiene que funcionar igual sin esto configurado: es una mejora,
    // no un requisito para jugar.
    config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);

    Http::fake();

    $jugador = jugadorPush('SinClaves');
    PushSubscription::create(['user_id' => $jugador->user_id, 'endpoint' => 'https://push.example/1']);

    expect(app(WebPushService::class)->configurado())->toBeFalse()
        ->and(app(WebPushService::class)->avisar([$jugador->user_id]))->toBe(0);

    Http::assertNothingSent();
});

it('una suscripcion que el navegador ya no reconoce se tira', function () {
    // 404 y 410 son como el servicio dice "este navegador ya no existe":
    // desinstalaron, limpiaron los datos o revocaron el permiso. Guardarla
    // para siempre es acumular basura y gastar una peticion por aviso.
    conClavesDePrueba();
    Http::fake(['*' => Http::response('', 410)]);

    $jugador = jugadorPush('Caducado');
    PushSubscription::create(['user_id' => $jugador->user_id, 'endpoint' => 'https://push.example/caducado']);

    app(WebPushService::class)->avisar([$jugador->user_id]);

    expect(PushSubscription::count())->toBe(0);
});

it('un fallo pasajero no tira la suscripcion a la primera', function () {
    conClavesDePrueba();
    Http::fake(['*' => Http::response('', 500)]);

    $jugador = jugadorPush('Intermitente');
    PushSubscription::create(['user_id' => $jugador->user_id, 'endpoint' => 'https://push.example/flojo']);

    app(WebPushService::class)->avisar([$jugador->user_id]);

    expect(PushSubscription::count())->toBe(1)
        ->and(PushSubscription::first()->fallos)->toBe(1);
});

/* ── Lo que se anuncia ──────────────────────────────────────────────────── */

it('no se anuncia un cruce que ya caduco', function () {
    // El toque de push viaja vacio y el worker pregunta que hay. Por eso lo
    // que se enseña es el estado de AHORA: si el cruce caduco por el camino,
    // no puede salir una notificacion diciendo "acepta ahora" sobre algo que
    // ya no existe.
    $jugador = jugadorPush('Tarde');
    $rival = jugadorPush('RivalTarde', 'syrtis');

    crucePush($jugador, $rival, 'pending_acceptance', ['expires_at' => now()->subMinute()]);

    expect(app(AvisosPendientesService::class)->para($jugador->user))->toBe([]);
});

it('un cruce vivo se anuncia con su modalidad', function () {
    $jugador = jugadorPush('APunto');
    $rival = jugadorPush('RivalPunto', 'syrtis');

    crucePush($jugador, $rival, 'pending_acceptance', ['expires_at' => now()->addMinutes(2)]);

    $avisos = app(AvisosPendientesService::class)->para($jugador->user);

    expect($avisos)->toHaveCount(1)
        ->and($avisos[0]['titulo'])->toBe('Rival encontrado')
        // La etiqueta lleva el id: dos toques del mismo cruce se sustituyen en
        // vez de apilar dos globos iguales.
        ->and($avisos[0]['tag'])->toContain('cruce:');
});

it('el chat solo anuncia lo del rival, y solo si es reciente', function () {
    // Anunciar lo que acabo de mandar YO seria absurdo, y sacar un aviso por
    // un "voy de camino" de hace media hora es ruido.
    $yo = jugadorPush('Yo', 'ignis');
    $rival = jugadorPush('Rival', 'syrtis');

    $match = crucePush($yo, $rival, 'in_progress', ['expires_at' => now()->addMinutes(20)]);

    // Uno mio, reciente: no se anuncia.
    MatchPing::create(['match_id' => (string) $match->id, 'player_id' => $yo->id, 'code' => 'voy']);
    // Y uno del rival, viejo: tampoco.
    MatchPing::create(['match_id' => (string) $match->id, 'player_id' => $rival->id, 'code' => 'cerca'])
        ->forceFill(['created_at' => now()->subHour()])->save();

    $tags = collect(app(AvisosPendientesService::class)->para($yo->user))->pluck('tag');

    expect($tags)->not->toContain('chat:' . $match->id);

    // Ahora uno del rival y fresco: ese si.
    MatchPing::create(['match_id' => (string) $match->id, 'player_id' => $rival->id, 'code' => 'llegue']);

    $avisos = collect(app(AvisosPendientesService::class)->para($yo->user));

    expect($avisos->pluck('tag'))->toContain('chat:' . $match->id)
        ->and($avisos->firstWhere('tag', 'chat:' . $match->id)['cuerpo'])->toBe('Estoy en el punto');
});

/* ── Las puertas ────────────────────────────────────────────────────────── */

it('apuntarse dos veces desde el mismo navegador no duplica filas', function () {
    conClavesDePrueba();

    $jugador = jugadorPush('Repetido');
    $suscripcion = ['endpoint' => 'https://push.example/mismo', 'keys' => ['p256dh' => 'abc', 'auth' => 'def']];

    $this->actingAs($jugador->user)->postJson(route('avisos.suscribir'), $suscripcion)->assertOk();
    $this->actingAs($jugador->user)->postJson(route('avisos.suscribir'), $suscripcion)->assertOk();

    expect(PushSubscription::count())->toBe(1);
});

it('nadie puede borrar la suscripcion de otro', function () {
    conClavesDePrueba();

    $mia = jugadorPush('Mia');
    $ajena = jugadorPush('Ajena');

    PushSubscription::create(['user_id' => $mia->user_id, 'endpoint' => 'https://push.example/mia']);

    $this->actingAs($ajena->user)
        ->postJson(route('avisos.desuscribir'), ['endpoint' => 'https://push.example/mia'])
        ->assertOk();

    expect(PushSubscription::count())->toBe(1);
});

it('la puerta sin CSRF solo mueve suscripciones que ya existen', function () {
    // Esta ruta va sin token porque la llama el service worker, donde no hay
    // documento del que sacarlo. Se defiende con la direccion VIEJA, que solo
    // conoce el navegador que ya estaba dado de alta: con ella se puede mover
    // esa fila y nada mas. Dar de alta un destino nuevo, no.
    conClavesDePrueba();

    $jugador = jugadorPush('Rotado');
    PushSubscription::create(['user_id' => $jugador->user_id, 'endpoint' => 'https://push.example/vieja']);

    // Una direccion que no tenemos: no pasa nada y no se filtra que no existe.
    $this->postJson(route('avisos.resuscribir'), [
        'viejo' => 'https://push.example/inventada',
        'nuevo' => 'https://push.example/del-atacante',
    ])->assertOk();

    expect(PushSubscription::count())->toBe(1)
        ->and(PushSubscription::first()->endpoint)->toBe('https://push.example/vieja');

    // Con la buena, se mueve y conserva el dueño.
    $this->postJson(route('avisos.resuscribir'), [
        'viejo' => 'https://push.example/vieja',
        'nuevo' => 'https://push.example/nueva',
    ])->assertOk();

    expect(PushSubscription::count())->toBe(1)
        ->and(PushSubscription::first()->endpoint)->toBe('https://push.example/nueva')
        ->and(PushSubscription::first()->user_id)->toBe($jugador->user_id);
});

it('los avisos pendientes no se sirven a quien no ha entrado', function () {
    $this->getJson(route('avisos.pendientes'))->assertUnauthorized();
});

/* ── El worker ──────────────────────────────────────────────────────────── */

it('el service worker no cachea nada', function () {
    // Un worker que sirve ficheros desde su cache es la forma mas rapida de
    // dejar a la gente con una version vieja del sitio despues de un
    // despliegue por FTP, que es como se despliega esto.
    $sw = File::get(public_path('sw.js'));

    expect($sw)->not->toContain('caches.open')
        ->and($sw)->not->toContain('cache.addAll');

    // Y se pone al mando enseguida: sin esto, un arreglo en este fichero
    // espera a que se cierren todas las pestañas del sitio.
    expect($sw)->toContain('skipWaiting()')
        ->and($sw)->toContain('clients.claim()');

    // Un push SIEMPRE tiene que acabar en algo visible: es la condicion que
    // pone el navegador, y si no la cumplimos enseña el suyo.
    expect($sw)->toContain('showNotification');
});

/* ── El diagnostico ─────────────────────────────────────────────────────── */

/** La salida del comando, en crudo. */
function revisionDePush(array $opciones = []): string
{
    Artisan::call('arena:push-check', $opciones);

    return Artisan::output();
}

it('la revision dice exactamente que falta', function () {
    // Existe porque esto falla en silencio por todos lados: el navegador no
    // cuenta por que no se suscribio y el servidor no sabe si al otro lado
    // habia alguien. Sin un sitio donde mirar, "no me llegan los avisos" se
    // resuelve probando a ciegas, que es justo lo que paso en el primer
    // despliegue.
    config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);

    expect(revisionDePush())->toContain('FALLA')
        ->toContain('Claves VAPID')
        ->toContain('no estan en el .env');

    // Con las claves puestas, esa linea pasa a OK y la queja se mueve a lo
    // siguiente que falta.
    conClavesDePrueba();

    $salida = revisionDePush();

    expect($salida)->toContain('correctas')
        ->toContain('Navegadores suscritos')
        ->toContain('ninguno');
});

it('la revision avisa de un par de claves que no casa', function () {
    // El caso mas dificil de ver: las dos lineas estan en el .env pero son de
    // generaciones distintas -se pego una y luego otra-. El sitio arranca
    // igual, el navegador se suscribe igual, y cada envio muere con un 401
    // que nadie mira.
    $unPar = VapidKeys::generar();
    $otroPar = VapidKeys::generar();

    config([
        'services.webpush.public_key' => $unPar['publica'],
        'services.webpush.private_key' => $otroPar['privada'],
    ]);

    expect(revisionDePush())->toContain('no son un par valido');
});

it('la prueba de envio no se puede lanzar contra quien no esta suscrito', function () {
    conClavesDePrueba();
    $jugador = jugadorPush('SinNavegador');

    expect(revisionDePush(['--user' => $jugador->user_id]))
        ->toContain('no tiene ningun navegador suscrito');
});

it('la prueba de envio ensena lo que responde el servicio de push', function () {
    // Es la respuesta definitiva a "no me llegan": o el servicio lo acepta
    // -y entonces el problema esta en el sistema operativo del jugador- o lo
    // rechaza y dice con que codigo.
    conClavesDePrueba();
    Http::fake(['*' => Http::response('', 201)]);

    $jugador = jugadorPush('ConNavegador');
    PushSubscription::create([
        'user_id' => $jugador->user_id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
    ]);

    expect(revisionDePush(['--user' => $jugador->user_id]))
        ->toContain('fcm.googleapis.com')
        ->toContain('aceptado');
});

it('un rechazo del servicio viene con su explicacion', function () {
    conClavesDePrueba();
    Http::fake(['*' => Http::response('', 401)]);

    $jugador = jugadorPush('Rechazado');
    PushSubscription::create([
        'user_id' => $jugador->user_id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/xyz',
    ]);

    // Un 401 casi siempre es que la clave publica del .env ya no es la que el
    // navegador uso al suscribirse. Decirlo ahorra horas.
    expect(revisionDePush(['--user' => $jugador->user_id]))
        ->toContain('HTTP 401')
        ->toContain('vaciar push_subscriptions');
});

/* ── El controlador del navegador ───────────────────────────────────────── */

function runtimeDeAvisos(): string
{
    return File::get(resource_path('views/partials/arena-push-runtime.blade.php'));
}

it('verde solo cuando el servidor tiene la suscripcion de este navegador', function () {
    // El boton mentia: se pintaba verde por un ajuste guardado aunque no
    // hubiera ni permiso ni suscripcion. Ahora "activo" exige, ademas, que el
    // servidor haya confirmado ESA direccion.
    $js = runtimeDeAvisos();

    expect($js)->toContain("if (!confirmadaPara(suscripcion)) { return 'inactivo'; }")
        // Y confirmada para ESTA cuenta: otra persona en el mismo navegador
        // no hereda el verde de la anterior.
        ->and($js)->toContain("function marca(endpoint) { return endpoint + '|' + USUARIO; }")
        ->and($js)->toContain("if (Notification.permission !== 'granted') { return 'inactivo'; }")
        ->and($js)->toContain("if (!s || !s.isEnabled()) { return 'inactivo'; }");
});

it('un toque nunca apaga lo que la persona ve apagado', function () {
    // La trampa: la barra decia "Activar avisos" y el clic hacia
    // `setEnabled(!enabled)`, que con el ajuste de fabrica APAGABA las
    // alertas. Ahora el clic decide por el estado PINTADO y la barra delega en
    // el controlador.
    $js = runtimeDeAvisos();

    expect($js)->toContain("if (estadoActual === 'activo') { return desactivar(); }");

    $layout = File::get(resource_path('views/layouts/arena.blade.php'));

    expect($layout)->toContain('window.ArenaAvisos.alternar();')
        // Y un solo pintor: el de sonido cede cuando el controlador existe.
        ->and($layout)->toContain("if (window.ArenaAvisos) {\n                    window.ArenaAvisos.repintar();");
});

it('una activacion en marcha no lanza otra', function () {
    // Un toque lanzaba dos suscripciones en paralelo -evento y llamada
    // directa- y se pisaban.
    $js = runtimeDeAvisos();

    expect($js)->toContain('if (enVuelo) { return enVuelo; }');
});

it('el permiso se pide dentro del gesto, antes de cualquier espera', function () {
    // Safari y Firefox rechazan `requestPermission()` si el gesto ya se gasto
    // en un `await` previo: el globo no salia y el boton se quedaba en rojo.
    $js = runtimeDeAvisos();
    $alternar = Str::between($js, 'function alternar() {', "return activar({ permisoPedido");

    expect($alternar)->toContain('Notification.requestPermission()')
        ->and($alternar)->not->toContain('await ');
});

it('al activar se prueba el camino entero con un push de verdad', function () {
    // Pintar la notificacion desde la pagina solo demostraba que el sistema
    // enseña notificaciones. La prueba ahora sale del servidor, pasa por el
    // servicio de push y el worker confirma que le llego.
    $js = runtimeDeAvisos();

    expect($js)->toContain('RUTAS.probar')
        ->and($js)->toContain("'arena:prueba-recibida'");

    expect(File::get(public_path('sw.js')))->toContain("postMessage({ tipo: 'arena:prueba-recibida' })");
});

it('cada fallo del navegador le llega al servidor', function () {
    $js = runtimeDeAvisos();

    expect($js)->toContain('navigator.sendBeacon(RUTAS.fallo')
        ->and($js)->toContain("informar(causa, error);");
});

it('una suscripcion sin clave legible no se tira en cada carga', function () {
    // Antes un navegador que no rellena `options.applicationServerKey` contaba
    // como "clave distinta": se desuscribia y se volvia a suscribir en cada
    // pagina, cambiando de direccion y dejando la vieja muerta en el servidor.
    $js = runtimeDeAvisos();

    expect($js)->toContain('if (!actual) { return null; }')
        ->and($js)->toContain('claveDeLaSuscripcion(suscripcion) === false');
});

/* ── El boton flotante ──────────────────────────────────────────────────── */

function botonDeAvisos(): string
{
    return File::get(resource_path('views/partials/arena-avisos-boton.blade.php'));
}

it('el flotante no decide nada: pinta y delega', function () {
    $js = botonDeAvisos();

    expect($js)->toContain("document.addEventListener('arena:avisos-estado'")
        ->and($js)->toContain('window.ArenaAvisos.alternar();')
        ->and($js)->not->toContain('setEnabled(');
});

it('en movil el interruptor sale del menu y queda flotante', function () {
    $js = botonDeAvisos();

    expect($js)->toContain('.arena-mobile-menu [data-arena-alert-toggle] { display: none; }')
        ->and($js)->toContain('@media (min-width: 1024px)');

    $layout = File::get(resource_path('views/layouts/arena.blade.php'));
    $push = strpos($layout, "@include('partials.arena-push-runtime')");
    $boton = strpos($layout, "@include('partials.arena-avisos-boton')");

    expect($boton)->not->toBeFalse()->and($boton)->toBeGreaterThan($push);
});

it('la pista respira en pequeño y se va a los diez segundos', function () {
    $js = botonDeAvisos();

    // Crece un 4,5 % y vuelve: se nota sin tapar pantalla.
    expect($js)->toContain('50% { transform: scale(1.045); }')
        ->and($js)->toContain('white-space: nowrap;')
        ->and($js)->toContain('var PISTA_MS = 10000;')
        // Una vez por visita, no una en la vida: quien no activo la primera
        // vez sigue necesitando el recordatorio.
        ->and($js)->toContain('sessionStorage');
});

/* ── Servidor: prueba, baliza y sustitucion ─────────────────────────────── */

it('la prueba de ida y vuelta manda un push real y lo anuncia como prueba', function () {
    conClavesDePrueba();
    Http::fake(['*' => Http::response('', 201)]);

    $jugador = jugadorPush('Probador');
    PushSubscription::create(['user_id' => $jugador->user_id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/prueba']);

    $this->actingAs($jugador->user)
        ->postJson(route('avisos.probar'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/prueba'])
        ->assertOk()
        ->assertJson(['ok' => true, 'estado' => 201, 'servicio' => 'fcm.googleapis.com']);

    Http::assertSent(fn ($r) => $r->url() === 'https://fcm.googleapis.com/fcm/send/prueba'
        && str_starts_with($r->header('Authorization')[0] ?? '', 'vapid t='));

    // Y lo que el worker encuentra al preguntar es la prueba, la mas nueva.
    $avisos = collect(app(AvisosPendientesService::class)->para($jugador->user))->sortByDesc('en');

    expect($avisos->first()['tag'])->toBe('arena:prueba')
        ->and($avisos->first()['prueba'])->toBeTrue();
});

it('no se puede lanzar la prueba contra el navegador de otro', function () {
    conClavesDePrueba();
    Http::fake();

    $dueño = jugadorPush('Dueno');
    $otro = jugadorPush('Otro');
    PushSubscription::create(['user_id' => $dueño->user_id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/ajena']);

    $this->actingAs($otro->user)
        ->postJson(route('avisos.probar'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/ajena'])
        ->assertNotFound();

    Http::assertNothingSent();
});

it('la baliza anota el fallo sin sesion ni token', function () {
    // Tiene que llegar aunque el fallo sea justo un token caducado.
    Cache::forget(\App\Http\Controllers\AvisosController::CLAVE_FALLOS);

    $this->call('POST', route('avisos.fallo'), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'causa' => 'suscripcion',
        'detalle' => 'AbortError: Registration failed - ' . str_repeat('x', 900),
        'permiso' => 'granted',
    ]))->assertOk();

    $fallos = Cache::get(\App\Http\Controllers\AvisosController::CLAVE_FALLOS);

    expect($fallos)->toHaveCount(1)
        ->and($fallos[0]['causa'])->toBe('suscripcion')
        // Recortado: nadie llena la cache mandando un libro.
        ->and(mb_strlen($fallos[0]['detalle']))->toBeLessThanOrEqual(300);

    // Y la revision lo enseña.
    expect(revisionDePush())->toContain('Fallos en navegadores')->toContain('suscripcion');
});

it('al cambiar de direccion, el navegador sustituye la suya y no la de otros', function () {
    conClavesDePrueba();

    $yo = jugadorPush('Rotador');
    $otro = jugadorPush('Ajeno');

    PushSubscription::create(['user_id' => $yo->user_id, 'endpoint' => 'https://push.example/vieja-mia']);
    PushSubscription::create(['user_id' => $otro->user_id, 'endpoint' => 'https://push.example/de-otro']);

    // Con la mia: se sustituye.
    $this->actingAs($yo->user)->postJson(route('avisos.suscribir'), [
        'endpoint' => 'https://push.example/nueva-mia',
        'anterior' => 'https://push.example/vieja-mia',
    ])->assertOk();

    // Intentando tirar la de otro: no pasa nada.
    $this->actingAs($yo->user)->postJson(route('avisos.suscribir'), [
        'endpoint' => 'https://push.example/nueva-mia',
        'anterior' => 'https://push.example/de-otro',
    ])->assertOk();

    expect(PushSubscription::pluck('endpoint')->sort()->values()->all())
        ->toBe(['https://push.example/de-otro', 'https://push.example/nueva-mia']);
});

it('el worker enseña solo lo ultimo que ha pasado', function () {
    // Antes enseñaba la lista entera: cada "voy de camino" del rival volvia a
    // sacar tambien el "¡A pelear!" del combate.
    $yo = jugadorPush('Ultimo', 'ignis');
    $rival = jugadorPush('RivalUltimo', 'syrtis');

    $match = crucePush($yo, $rival, 'in_progress', [
        'started_at' => now()->subMinutes(5),
        'expires_at' => now()->addMinutes(20),
    ]);

    MatchPing::create(['match_id' => (string) $match->id, 'player_id' => $rival->id, 'code' => 'voy']);

    $avisos = app(AvisosPendientesService::class)->para($yo->user);
    $ultimo = collect($avisos)->sortByDesc('en')->first();

    expect($ultimo['tag'])->toBe('chat:' . $match->id)
        ->and($ultimo['cuerpo'])->toBe('Voy de camino');

    expect(File::get(public_path('sw.js')))
        ->toContain("avisos.sort((a, b) => String(b.en || '').localeCompare(String(a.en || '')));")
        ->toContain('const aviso = avisos[0] ||');
});

it('el resultado recien confirmado tiene su aviso, con victoria o derrota', function () {
    $yo = jugadorPush('Ganador', 'ignis');
    $rival = jugadorPush('Perdedor', 'syrtis');

    crucePush($yo, $rival, 'completed', [
        'completed_at' => now()->subMinute(),
        'winner_team' => 'team_a',
    ]);

    $mio = collect(app(AvisosPendientesService::class)->para($yo->user))->firstWhere('titulo', 'Resultado confirmado');
    $suyo = collect(app(AvisosPendientesService::class)->para($rival->user))->firstWhere('titulo', 'Resultado confirmado');

    expect($mio['cuerpo'])->toStartWith('Victoria')
        ->and($suyo['cuerpo'])->toStartWith('Derrota');
});

it('el aviso del rival sale en push a los demas y no a quien lo manda', function () {
    conClavesDePrueba();
    Http::fake(['*' => Http::response('', 201)]);

    $yo = jugadorPush('Emisor', 'ignis');
    $rival = jugadorPush('Receptor', 'syrtis');
    $match = crucePush($yo, $rival, 'in_progress', ['expires_at' => now()->addMinutes(20)]);

    PushSubscription::create(['user_id' => $yo->user_id, 'endpoint' => 'https://push.example/emisor']);
    PushSubscription::create(['user_id' => $rival->user_id, 'endpoint' => 'https://push.example/receptor']);

    app(\App\Services\MatchPingService::class)->enviar($match, $yo, 'llegue');

    // En una peticion web sale al terminar, despues de responder.
    app()->terminate();

    Http::assertSent(fn ($r) => $r->url() === 'https://push.example/receptor');
    Http::assertNotSent(fn ($r) => $r->url() === 'https://push.example/emisor');
});

/* ── Los limites de peticiones ──────────────────────────────────────────── */

it('el sondeo del lobby no se come el cupo del chat ni de los avisos', function () {
    // Sin nombre, todos los `throttle` de un usuario comparten contador: el
    // sondeo (20/min en combate) dejaba el chat (20/min) en el limite y la
    // prueba de avisos (6/min) bloqueada. Cada limite lleva su nombre.
    $jugador = jugadorPush('Sondeador');

    for ($i = 0; $i < 25; $i++) {
        $this->actingAs($jugador->user)->getJson(route('queue.state-poll'));
    }

    // El limitador responde ANTES de validar: si deja pasar, sale un 422.
    $this->actingAs($jugador->user)->postJson(route('matches.ping'), [])->assertStatus(422);
    $this->actingAs($jugador->user)->postJson(route('avisos.probar'), [])->assertStatus(422);
});

it('ningun limite de peticiones va sin nombre', function () {
    $rutas = File::get(base_path('routes/web_main.php'));

    preg_match_all("/throttle:(\d+),(\d+)(,[\w-]+)?'/", $rutas, $m, PREG_SET_ORDER);

    expect($m)->not->toBeEmpty();

    foreach ($m as $limite) {
        expect($limite[3] ?? '')->not->toBe('', 'throttle:' . $limite[1] . ',' . $limite[2] . ' sin nombre comparte contador con todo lo demas');
    }
});

/* ── Ronda 2 del arbitro ────────────────────────────────────────────────── */

it('solo se aceptan direcciones de los servicios de push conocidos', function () {
    // La direccion la manda el navegador, o sea, cualquiera. Sin lista, el
    // servidor haria peticiones firmadas a donde le dijeran: a la red interna
    // de Hostinger, por ejemplo.
    conClavesDePrueba();
    config(['services.webpush.hosts_extra' => null]);

    $jugador = jugadorPush('Curioso');

    foreach (['http://127.0.0.1/x', 'https://169.254.169.254/latest', 'https://evil.example/x', 'http://fcm.googleapis.com/fcm/send/x'] as $mala) {
        $this->actingAs($jugador->user)
            ->postJson(route('avisos.suscribir'), ['endpoint' => $mala, 'keys' => ['p256dh' => 'a', 'auth' => 'b']])
            ->assertStatus(422);
    }

    $this->actingAs($jugador->user)
        ->postJson(route('avisos.suscribir'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])
        ->assertOk();

    expect(PushSubscription::count())->toBe(1);
});

it('una fila antigua que apunta fuera de la lista no recibe peticiones', function () {
    conClavesDePrueba();
    config(['services.webpush.hosts_extra' => null]);
    Http::fake(['*' => Http::response('', 201)]);

    $jugador = jugadorPush('Antiguo');
    PushSubscription::create(['user_id' => $jugador->user_id, 'endpoint' => 'https://interno.local/x']);

    app(WebPushService::class)->avisar([$jugador->user_id]);

    Http::assertNothingSent();
});

it('un error del servidor o de firma no borra la suscripcion', function () {
    // Un 401 es culpa NUESTRA (clave mal puesta); un 5xx, del servicio. Tirar
    // la suscripcion por eso dejaba a todo el mundo sin avisos para siempre.
    conClavesDePrueba();

    foreach ([401, 403, 429, 500, 503] as $estado) {
        Http::fake(['*' => Http::response('', $estado)]);
        $jugador = jugadorPush('Estado' . $estado);
        PushSubscription::create(['user_id' => $jugador->user_id, 'endpoint' => 'https://push.example/e' . $estado]);

        app(WebPushService::class)->avisar([$jugador->user_id]);
    }

    expect(PushSubscription::count())->toBe(5);
});

it('un cruce cancelado avisa a quien no lo rechazo, aunque la fila ya no exista', function () {
    conClavesDePrueba();
    Http::fake(['*' => Http::response('', 201)]);

    $yo = jugadorPush('Rechaza', 'ignis');
    $rival = jugadorPush('Esperaba', 'syrtis');
    $match = crucePush($yo, $rival, 'pending_acceptance', ['expires_at' => now()->addMinutes(2)]);

    PushSubscription::create(['user_id' => $yo->user_id, 'endpoint' => 'https://push.example/rechaza']);
    PushSubscription::create(['user_id' => $rival->user_id, 'endpoint' => 'https://push.example/esperaba']);

    app(\App\Services\ArenaMatchmakingService::class)->cancelMatch($match, 'player_rejected', $yo->id, false);
    app()->terminate();

    Http::assertSent(fn ($r) => $r->url() === 'https://push.example/esperaba');
    Http::assertNotSent(fn ($r) => $r->url() === 'https://push.example/rechaza');

    $aviso = collect(app(AvisosPendientesService::class)->para($rival->user))->firstWhere('tag', 'cruce:' . $match->id);

    expect($aviso)->not->toBeNull()
        ->and($aviso['titulo'])->toBe('Cruce cancelado')
        ->and(collect(app(AvisosPendientesService::class)->para($yo->user))->pluck('tag'))->not->toContain('cruce:' . $match->id);
});

it('las direcciones de los avisos son relativas al sitio', function () {
    // Con APP_URL mal puesto en el servidor, una URL absoluta mandaba al
    // jugador a otro dominio al tocar el aviso.
    $yo = jugadorPush('Rel', 'ignis');
    $rival = jugadorPush('Rel2', 'syrtis');
    crucePush($yo, $rival, 'pending_acceptance', ['expires_at' => now()->addMinutes(2)]);

    foreach (app(AvisosPendientesService::class)->para($yo->user) as $aviso) {
        expect($aviso['url'])->toStartWith('/');
    }
});

it('entrar con Discord deja la sesion recordada', function () {
    // El worker pregunta al sitio que ha pasado; con la sesion caducada la
    // respuesta era un 401 y el aviso salia vacio.
    expect(File::get(app_path('Http/Controllers/AuthController.php')))->toContain('Auth::login($user, true)');
});

it('el service worker no se cachea en el servidor', function () {
    $htaccess = File::get(public_path('.htaccess'));

    expect($htaccess)->toContain('<FilesMatch "^(sw\.js|manifest\.webmanifest)$">')
        ->and($htaccess)->toContain('no-cache, must-revalidate');
});

it('los interruptores nacen neutros, ni verdes ni rojos', function () {
    $layout = File::get(resource_path('views/layouts/arena.blade.php'));

    expect($layout)->not->toContain('bg-emerald-400" data-arena-alert-indicator')
        ->and($layout)->toContain('bg-amber-300" data-arena-alert-indicator');
});

/* ── Ronda 3 del arbitro ────────────────────────────────────────────────── */

it('la cancelacion de un cruce no tapa al cruce nuevo del mismo segundo', function () {
    // Rechazar y volver a emparejar pasa en la misma peticion. El hecho lleva
    // microsegundos y el cruce nuevo no, asi que el hecho "ganaba" y el
    // "Rival encontrado" nunca salia.
    $yo = jugadorPush('Tapado', 'ignis');
    $rival = jugadorPush('Nuevo', 'syrtis');

    app(AvisosPendientesService::class)->registrarHecho([$yo->id], 'cruce:999', 'Cruce cancelado', 'x');
    $nuevo = crucePush($yo, $rival, 'pending_acceptance', ['expires_at' => now()->addMinutes(2)]);

    $tags = collect(app(AvisosPendientesService::class)->para($yo->user))->pluck('tag');

    expect($tags)->toContain('cruce:' . $nuevo->id)
        ->and($tags)->not->toContain('cruce:999');
});

it('el lider se entera por push de que su equipo esta listo', function () {
    $layout = File::get(resource_path('views/layouts/arena.blade.php'));
    $hub = File::get(app_path('Http/Controllers/QueueHubController.php'));

    // Solo se calla en la pagina lo que tiene push de verdad.
    expect($layout)->toContain("const CON_PUSH = [")
        ->and($layout)->toContain("CON_PUSH.includes(type)")
        ->and($hub)->toContain("'Tu equipo esta listo'");
});

it('la revision distingue un sw.js viejo por su version', function () {
    expect(File::get(public_path('sw.js')))
        ->toContain("const VERSION = '" . \App\Console\Commands\PushCheckCommand::VERSION_WORKER . "'");
});

it('otra cuenta en el mismo navegador no hereda los avisos en silencio', function () {
    $js = runtimeDeAvisos();

    expect($js)->toContain("&& (!dueno || dueno === USUARIO);")
        ->and($js)->toContain('escribir(DUENO, USUARIO);');
});

it('al salir se borra la suscripcion de este navegador, y solo la suya', function () {
    $jugador = jugadorPush('Saliente');
    $otro = jugadorPush('Otro');
    PushSubscription::create(['user_id' => $jugador->user_id, 'endpoint' => 'https://push.example/saliente']);
    PushSubscription::create(['user_id' => $jugador->user_id, 'endpoint' => 'https://push.example/movil']);
    PushSubscription::create(['user_id' => $otro->user_id, 'endpoint' => 'https://push.example/ajena']);

    $this->actingAs($jugador->user)->post(route('logout'), ['push_endpoint' => 'https://push.example/saliente']);
    $this->actingAs($jugador->user)->post(route('logout'), ['push_endpoint' => 'https://push.example/ajena']);

    expect(PushSubscription::pluck('endpoint')->sort()->values()->all())
        ->toBe(['https://push.example/ajena', 'https://push.example/movil']);
});
