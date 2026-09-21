<?php

use App\Models\ArenaMatch;
use App\Models\ArenaZone;
use App\Models\AppSetting;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchmakingService;
use App\Services\ArenaZoneService;
use App\Support\ArenaMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * El punto de encuentro: uno solo, el mismo para los dos, y escrito en piedra.
 *
 * El fallo que esto arregla se vio jugando: dos rivales del mismo cruce veian
 * puntos distintos en el mapa, y el que iba al bueno se quedaba esperando a
 * alguien que estaba a medio mapa. La causa eran dos, y las dos se prueban:
 *
 *   1. Las zonas vivian en un fichero de public/ que el navegador cacheaba sin
 *      caducidad. El panel lo pedia con ?v=time() -por eso el admin siempre
 *      veia lo ultimo- y el mapa del jugador no.
 *   2. El punto se calculaba en el navegador al abrir el mapa, asi que mover
 *      una zona cambiaba el sitio de los cruces que ya estaban en marcha.
 */
beforeEach(function () {
    foreach (ArenaMode::all() as $mode) {
        AppSetting::setValue(
            ArenaMode::settingKey($mode),
            $mode === ArenaMode::ONE_V_ONE ? '1' : '0',
            'modes',
            'boolean',
            true
        );
    }

    AppSetting::setValue('matchmaking_hold_seconds', 0, 'runtime', 'integer', false);
});

function zonaCuadrada(string $key, array $meeting = null, array $meetingB = null): ArenaZone
{
    return ArenaZone::updateOrCreate(['key' => $key], [
        'number' => 99,
        'name' => 'Zona de prueba',
        // Un cuadrado de 100x100 en el centro del mapa: el punto automatico cae
        // justo en su centro y es facil de razonar.
        'coords' => [[400, 400], [400, 500], [500, 500], [500, 400]],
        'meeting' => $meeting,
        'meeting_b' => $meetingB,
    ]);
}

function duelistaZona(string $sufijo, string $realm): Player
{
    $user = User::create([
        'discord_id' => 'pz-' . $sufijo,
        'discord_username' => 'pz_' . $sufijo,
        'name' => 'Pz ' . $sufijo,
        'email' => 'pz-' . $sufijo . '@example.com',
    ]);

    $player = Player::create([
        'user_id' => $user->id,
        'character_name' => 'Pz' . ucfirst($sufijo),
        'subclass' => 'hunter',
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);

    Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => ArenaMode::ONE_V_ONE,
        'status' => 'waiting',
        'estimated_mmr' => 1000,
        'joined_at' => now()->subMinutes(3),
        'expires_at' => now()->addMinutes(30),
    ]);

    return $player;
}

// ------------------------------------------------------------------ el calculo

it('el punto automatico cae dentro de su zona', function () {
    $zona = zonaCuadrada('central_ruins');
    $servicio = app(ArenaZoneService::class);
    $punto = $servicio->puntoAutomatico($zona->coords);

    expect($punto)->not->toBeNull()
        // En un cuadrado, el sitio mas interior es el centro.
        ->and(round($punto[0]))->toBe(450.0)
        ->and(round($punto[1]))->toBe(450.0)
        ->and($servicio->distanciaAlBorde($punto, $zona->coords))->toBeGreaterThan(0);
});

it('una zona degenerada no cuelga el calculo', function () {
    // Todos los vertices en el mismo sitio deja el paso a cero, y un bucle que
    // avanza de cero en cero no termina nunca: colgaria la peticion de quien
    // abre el mapa, y publicado, la de todos los jugadores.
    $punto = app(ArenaZoneService::class)->puntoAutomatico([[10, 10], [10, 10], [10, 10]]);

    expect($punto)->toBe([10.0, 10.0]);
});

it('sin contorno no hay punto', function () {
    expect(app(ArenaZoneService::class)->puntoAutomatico([[1, 1], [2, 2]]))->toBeNull()
        ->and(app(ArenaZoneService::class)->puntoAutomatico(null))->toBeNull();
});

// ------------------------------------------------------- los dos puntos y el cruce

it('con un solo punto fijado, todos los cruces van a ese', function () {
    zonaCuadrada('central_ruins', meeting: [420, 420]);

    $servicio = app(ArenaZoneService::class);

    foreach (range(1, 8) as $vuelta) {
        $servicio->olvidar();
        $elegido = $servicio->elegirPuntoDeEncuentro('central_ruins');

        expect($elegido['slot'])->toBe(1)
            ->and($elegido['punto'])->toBe([420.0, 420.0]);
    }
});

it('con dos puntos fijados, los cruces se reparten entre los dos', function () {
    // La gracia de tener dos es que la misma zona no se juegue siempre en el
    // mismo claro. Si saliera siempre el mismo, el segundo punto no serviria
    // para nada.
    zonaCuadrada('central_ruins', meeting: [420, 420], meetingB: [480, 480]);

    $servicio = app(ArenaZoneService::class);
    $salieron = [];

    foreach (range(1, 60) as $vuelta) {
        $salieron[] = $servicio->elegirPuntoDeEncuentro('central_ruins')['slot'];
    }

    expect(array_unique($salieron))->toHaveCount(2);
});

it('el segundo punto no se usa si no esta puesto', function () {
    zonaCuadrada('central_ruins', meeting: [420, 420]);

    expect(app(ArenaZoneService::class)->puntoDelPuesto('central_ruins', 2))->toBeNull();
});

it('el enfrentamiento deja escrito el punto al crearse', function () {
    // La zona la elige el emparejador segun que reinos se cruzan, asi que se
    // le pone punto a todas y se comprueba contra la que acabe saliendo.
    ArenaZone::query()->get()->each(function (ArenaZone $zona) {
        $zona->update([
            'coords' => [[400, 400], [400, 500], [500, 500], [500, 400]],
            'meeting' => [430, 470],
            'meeting_b' => null,
        ]);
    });

    duelistaZona('a', 'ignis');
    duelistaZona('b', 'alsius');

    expect(app(ArenaMatchmakingService::class)->processQueue(false))->toBe(1);

    $match = ArenaMatch::query()->firstOrFail();

    // El punto viaja a la base como JSON, asi que vuelve entero: lo que importa
    // es que sea EL punto de la zona, no su tipo.
    expect($match->meeting_point)->toEqual([430, 470])
        ->and($match->meeting_slot)->toBe(1);
});

it('mover la zona despues no cambia el sitio de un cruce en marcha', function () {
    // Es el caso exacto que se reporto: el admin movio el punto y el rival
    // seguia viendo el viejo. Ahora el cruce manda y el mapa no calcula nada.
    ArenaZone::query()->get()->each(function (ArenaZone $zona) {
        $zona->update([
            'coords' => [[400, 400], [400, 500], [500, 500], [500, 400]],
            'meeting' => [430, 470],
            'meeting_b' => null,
        ]);
    });

    duelistaZona('c', 'ignis');
    duelistaZona('d', 'alsius');
    app(ArenaMatchmakingService::class)->processQueue(false);

    $match = ArenaMatch::query()->firstOrFail();
    $antes = $match->meeting_point;

    // El admin lo mueve a la otra punta.
    app(ArenaZoneService::class)->publicar([[
        'key' => $match->zone,
        'name' => 'Zona de prueba',
        'coords' => [[400, 400], [400, 500], [500, 500], [500, 400]],
        'meeting' => [495, 495],
    ]]);

    expect($match->refresh()->meeting_point)->toEqual($antes)
        ->and($antes)->toEqual([430, 470]);
});

// ----------------------------------------------------------------- el JS servido

it('el sello cambia al publicar y no antes', function () {
    $servicio = app(ArenaZoneService::class);
    $antes = $servicio->sello();

    $servicio->olvidar();
    expect($servicio->sello())->toBe($antes);

    $servicio->publicar([[
        'key' => 'central_ruins',
        'name' => 'Zona de prueba',
        'coords' => [[400, 400], [400, 500], [500, 500], [500, 400]],
        'meeting' => [460, 460],
    ]]);

    expect($servicio->sello())->not->toBe($antes);
});

it('dos publicaciones en el mismo segundo dan sellos distintos', function () {
    // Es el fallo original, otra vez: con el sello sacado del reloj, arrastrar
    // el punto, ver que quedo mal y volver a arrastrarlo caia en el mismo
    // segundo. Misma URL, y como la respuesta va con `immutable`, el navegador
    // del jugador se quedaba el mapa viejo un año entero.
    $servicio = app(ArenaZoneService::class);

    $publicar = function (array $punto) use ($servicio) {
        $servicio->publicar([[
            'key' => 'central_ruins',
            'name' => 'Zona de prueba',
            'coords' => [[400, 400], [400, 500], [500, 500], [500, 400]],
            'meeting' => $punto,
        ]]);

        return $servicio->sello();
    };

    // Sin viajar en el tiempo: los dos guardados caen en el mismo segundo.
    $primero = $publicar([420, 420]);
    $segundo = $publicar([495, 495]);

    expect($segundo)->not->toBe($primero);
});

it('el sello no cambia si se publica lo mismo', function () {
    // Al reves que el anterior: republicar sin tocar nada no puede invalidar la
    // copia de todo el mundo. El sello es del contenido, no del momento.
    $servicio = app(ArenaZoneService::class);

    $zonas = [[
        'key' => 'central_ruins',
        'name' => 'Zona de prueba',
        'coords' => [[400, 400], [400, 500], [500, 500], [500, 400]],
        'meeting' => [420, 420],
    ]];

    $servicio->publicar($zonas);
    $primero = $servicio->sello();

    $this->travel(30)->seconds();
    $servicio->publicar($zonas);

    expect($servicio->sello())->toBe($primero);
});

it('un punto que llega como texto se guarda en numeros', function () {
    // Del formulario del editor los puntos llegan como cadenas. PHP acepta
    // "430" como numero y el emparejador lo usaba tan tranquilo, pero el
    // navegador lo descarta con Number.isFinite: el mapa dejaba de dibujar el
    // punto mientras los cruces seguian mandando a la gente ahi. Es la misma
    // clase de desincronizacion que el fallo de cache.
    $servicio = app(ArenaZoneService::class);

    $servicio->publicar([[
        'key' => 'central_ruins',
        'name' => 'Zona de prueba',
        'coords' => [[400, 400], [400, 500], [500, 500], [500, 400]],
        'meeting' => ['430', '470'],
        'meeting_b' => ['460', '440'],
    ]]);

    $zona = ArenaZone::query()->where('key', 'central_ruins')->firstOrFail();

    // Lo que se comprueba es que NO son cadenas. Un 430.0 se escribe "430" en
    // JSON y vuelve como entero, y eso al navegador le vale: lo que no le vale
    // es el texto.
    expect($zona->meeting[0])->not->toBeString()
        ->and($zona->meeting_b[0])->not->toBeString()
        ->and($zona->meeting)->toEqual([430, 470])
        ->and($zona->meeting_b)->toEqual([460, 440]);

    // Y lo que sale hacia el navegador son numeros de coma flotante.
    $paraElMapa = $zona->paraElMapa();

    expect($paraElMapa['meeting'][0])->toBeFloat()
        ->and($paraElMapa['meeting_b'][1])->toBeFloat();
});

it('los respaldos de zonas no se acumulan sin fin', function () {
    // Uno por publicacion. Una tarde de retoques son doscientos ficheros en un
    // disco compartido que se paga por gigabyte.
    Storage::disk('local')->delete(
        collect(Storage::disk('local')->files())
            ->filter(fn (string $f) => str_starts_with(basename($f), 'arena-zones-backup-'))
            ->all()
    );

    $servicio = app(ArenaZoneService::class);

    for ($i = 0; $i < 25; $i++) {
        $servicio->publicar([[
            'key' => 'central_ruins',
            'name' => 'Zona de prueba',
            'coords' => [[400, 400], [400, 500], [500, 500], [500, 400]],
            'meeting' => [400 + $i, 460],
        ]]);
    }

    $respaldos = collect(Storage::disk('local')->files())
        ->filter(fn (string $f) => str_starts_with(basename($f), 'arena-zones-backup-'));

    expect($respaldos->count())->toBeLessThanOrEqual(20)
        // Y el que queda es el ultimo, no uno cualquiera.
        ->and((string) Storage::disk('local')->get($respaldos->sort()->last()))
        ->toContain('424');
});

it('el javascript de zonas se sirve desde la base de datos', function () {
    zonaCuadrada('central_ruins', meeting: [420, 420], meetingB: [480, 480]);

    $sello = app(ArenaZoneService::class)->sello();

    $respuesta = $this->get(route('arena.zones.asset', ['v' => $sello]));

    $respuesta->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        // Con el sello en la URL el navegador puede quedarse la copia: es lo
        // que evita que dos rivales vean mapas de dias distintos.
        // Symfony reordena la cabecera alfabeticamente al enviarla.
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

    expect($respuesta->getContent())
        ->toContain('window.ARENA_ZONES_CONFIG')
        ->toContain('"meeting":[420,420]')
        ->toContain('"meeting_b":[480,480]');
});

it('sin sello en la url el navegador tiene que revalidar', function () {
    $this->get(route('arena.zones.asset'))
        ->assertOk()
        // "private" lo añade Laravel a toda respuesta sin caché declarada.
        ->assertHeader('Cache-Control', 'no-cache, private');
});

it('el navegador que ya tiene la version no se la descarga otra vez', function () {
    $sello = app(ArenaZoneService::class)->sello();

    $this->withHeaders(['If-None-Match' => '"' . $sello . '"'])
        ->get(route('arena.zones.asset', ['v' => $sello]))
        ->assertStatus(304);
});

// --------------------------------------------------------------------- publicar

it('publicar guarda los dos puntos y descarta la basura', function () {
    $guardadas = app(ArenaZoneService::class)->publicar([
        [
            'key' => 'central_ruins',
            'name' => 'Zona de prueba',
            'coords' => [[400, 400], [400, 500], [500, 500], ['roto'], [500, 400]],
            'meeting' => [420, 420],
            'meeting_b' => ['no', 'es', 'un', 'punto'],
        ],
        ['key' => 'zona_que_no_existe', 'name' => 'Nada', 'coords' => []],
    ]);

    $zona = ArenaZone::query()->where('key', 'central_ruins')->firstOrFail();

    expect($guardadas)->toBe(1)
        ->and($zona->meeting)->toBe([420, 420])
        // Un punto a medias no falla al guardarlo: falla al dibujarlo, y para
        // entonces ya esta publicado para todo el mundo.
        ->and($zona->meeting_b)->toBeNull()
        ->and($zona->coords)->toHaveCount(4);
});
