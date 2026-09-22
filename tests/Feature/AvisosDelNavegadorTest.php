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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

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
