<?php

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchmakingService;
use App\Support\ArenaMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * Como reparte la cola el emparejador.
 *
 * Dos promesas, y las dos se prueban aqui con numeros concretos:
 *
 *   1. NADIE se queda en cola habiendo alguien con quien jugar. Esta va
 *      primero: mas vale un cruce regular que quedarse mirando.
 *   2. Dentro de eso, los cruces son los mejores que se pueden armar. "Los
 *      mejores" no es una opinion: para colas pequeñas se puede calcular el
 *      reparto optimo probandolos todos, y es contra eso que se compara.
 */
function duelistaCola(string $sufijo, string $realm, int $mmr, string $subclass = 'hunter'): Player
{
    $user = User::create([
        'discord_id' => 'emp-' . $sufijo,
        'discord_username' => 'emp_' . $sufijo,
        'name' => 'Emp ' . $sufijo,
        'email' => 'emp-' . $sufijo . '@example.com',
    ]);

    $player = Player::create([
        'user_id' => $user->id,
        'character_name' => 'Emp' . ucfirst($sufijo),
        'subclass' => $subclass,
        'realm' => $realm,
        'pl_points' => 30,
        'mmr' => $mmr,
        'trust_score' => 100,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'is_active' => true,
    ]);

    Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => ArenaMode::ONE_V_ONE,
        'status' => 'waiting',
        'conjurer_role' => $subclass === 'conjurer' ? 'offensive' : null,
        'estimated_mmr' => $mmr,
        'joined_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    return $player;
}

/**
 * Mete en cola la lista dada de [reino, mmr] y empareja.
 *
 * @param  list<array{0: string, 1: int}>  $cola
 * @return array{partidas: int, suma: int, peor: int, sueltos: int}
 */
function repartir(array $cola): array
{
    foreach ($cola as $i => [$realm, $mmr]) {
        duelistaCola('c' . $i, $realm, $mmr);
    }

    app(ArenaMatchmakingService::class)->processQueue(false);

    $mmrDe = fn (array $lado) => (int) Player::find($lado[0]['player_id'])->mmr;

    $difs = ArenaMatch::query()
        ->where('arena_mode', ArenaMode::ONE_V_ONE)
        ->get()
        ->map(fn (ArenaMatch $m) => abs($mmrDe($m->team_a) - $mmrDe($m->team_b)));

    return [
        'partidas' => $difs->count(),
        'suma' => (int) $difs->sum(),
        'peor' => (int) ($difs->max() ?? 0),
        'sueltos' => Queue::query()->where('status', 'waiting')->whereNull('match_id')->count(),
    ];
}

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
});

// --------------------------------------------------------- calidad del reparto

it('con seis en cola reparte los tres cruces de la mejor forma', function () {
    // El caso que delato que el emparejador viejo estaba mal. Era avido: cogia
    // el cruce mas ajustado, lo sacaba, y repetia. Aqui se llevaba primero
    // 1300-1200 y 1600-1500, y dejaba al de 1000 contra el de 1800: 800 puntos
    // de MMR, unas cincuenta partidas de diferencia, porque ya se habia gastado
    // a sus dos rivales buenos.
    //
    // El reparto bueno da 200 a cada uno. Es el optimo, comprobado a mano:
    // 1000-1200, 1300-1500 y 1600-1800.
    $r = repartir([
        ['alsius', 1000], ['alsius', 1300], ['alsius', 1600],
        ['ignis', 1200], ['ignis', 1500], ['ignis', 1800],
    ]);

    expect($r['partidas'])->toBe(3)
        ->and($r['suma'])->toBe(600)
        ->and($r['peor'])->toBe(200);
});

it('cambia a un emparejado por uno del banquillo si el cruce queda mejor', function () {
    // Cinco Alsius, dos Ignis y un Syrtis: solo caben tres partidas, asi que
    // dos Alsius se quedan fuera si o si. Quienes se quedan fuera no da igual.
    //
    // El reparto optimo es 242 -comprobado probandolos todos- y para llegar hay
    // que sacar del cruce al Alsius de 857 y meter al de 1171, que estaba
    // sentado. Sin ese movimiento el reparto se quedaba en 338.
    $r = repartir([
        ['alsius', 920], ['ignis', 1035], ['alsius', 857], ['ignis', 1100],
        ['syrtis', 1062], ['alsius', 1171], ['alsius', 1118], ['alsius', 1346],
    ]);

    expect($r['partidas'])->toBe(3)
        ->and($r['suma'])->toBe(242);
});

it('empareja a los mas parecidos cuando la cola esta equilibrada', function () {
    $r = repartir([
        ['alsius', 800], ['alsius', 950], ['alsius', 1100], ['alsius', 1250], ['alsius', 1400],
        ['ignis', 860], ['ignis', 1010], ['ignis', 1160], ['ignis', 1310], ['ignis', 1460],
    ]);

    // Cinco cruces de 60 puntos cada uno: el optimo exacto.
    expect($r['partidas'])->toBe(5)
        ->and($r['suma'])->toBe(300)
        ->and($r['peor'])->toBe(60);
});

// ------------------------------------------------- nadie se queda mirando

it('no deja a nadie en cola cuando el cruce mas ajustado bloquea a los demas', function () {
    // Cuatro en cola: Alsius 1070, Alsius 1151, Ignis 893, Syrtis 778.
    //
    // El cruce mas ajustado es Syrtis 778 contra Ignis 893 -115 puntos-, y en
    // cuanto se lo lleva, los dos Alsius se quedan mirandose sin poder jugar
    // entre ellos. Una partida donde caben dos.
    //
    // Deshacer ese cruce cuesta MMR y sale a cuenta igual: dos personas jugando
    // un cruce regular valen mas que una jugando uno perfecto y dos esperando.
    $r = repartir([
        ['alsius', 1070], ['alsius', 1151], ['ignis', 893], ['syrtis', 778],
    ]);

    expect($r['partidas'])->toBe(2)
        ->and($r['sueltos'])->toBe(0);
});

it('con un reino desbordado coloca a todos los que caben', function () {
    // Seis Alsius contra tres Ignis: caben tres partidas y sobran tres Alsius,
    // que no pueden jugar entre ellos. Lo que no puede pasar es que salgan
    // menos de tres.
    $cola = [];
    foreach ([900, 1000, 1100, 1200, 1300, 1400] as $mmr) {
        $cola[] = ['alsius', $mmr];
    }
    foreach ([950, 1150, 1350] as $mmr) {
        $cola[] = ['ignis', $mmr];
    }

    $r = repartir($cola);

    expect($r['partidas'])->toBe(3)
        ->and($r['sueltos'])->toBe(3);
});

it('con la cola llena no deja a nadie fuera', function () {
    // Sesenta en cola, veinte por reino: treinta partidas y cero esperando.
    // Con el emparejador viejo, una cola asi de grande dejaba fuera a uno de
    // cada cinco, porque los sueltos acababan siendo todos del mismo reino y
    // entre ellos no hay partida posible.
    $cola = [];
    $reinos = ['alsius', 'ignis', 'syrtis'];

    for ($i = 0; $i < 60; $i++) {
        $cola[] = [$reinos[$i % 3], 700 + (($i * 137) % 700)];
    }

    $r = repartir($cola);

    expect($r['partidas'])->toBe(30)
        ->and($r['sueltos'])->toBe(0);
});

it('no empareja a quien no tiene rival posible', function () {
    // Tres del mismo reino: cero partidas y los tres siguen esperando. No es un
    // fallo, es que no hay con quien.
    $r = repartir([['alsius', 1000], ['alsius', 1010], ['alsius', 1020]]);

    expect($r['partidas'])->toBe(0)
        ->and($r['sueltos'])->toBe(3);
});

// --------------------------------------------------------------- el turno

it('dos barridos a la vez no crean la partida dos veces', function () {
    // El barrido corre dentro de la peticion de quien entra a la cola, asi que
    // con varias entradas seguidas se lanzan varios. El turno unico hace que
    // barra uno y los demas se encuentren el trabajo hecho.
    //
    // Aqui se llama dos veces seguidas, que es lo que se puede reproducir en un
    // solo proceso: el segundo no puede duplicar nada.
    $r = repartir([
        ['alsius', 1000], ['ignis', 1010], ['alsius', 1200], ['ignis', 1210],
    ]);

    expect($r['partidas'])->toBe(2);

    app(ArenaMatchmakingService::class)->processQueue(false);

    expect(ArenaMatch::query()->where('arena_mode', ArenaMode::ONE_V_ONE)->count())->toBe(2);

    // Y nadie aparece en dos partidas.
    $ids = ArenaMatch::query()->get()
        ->flatMap(fn (ArenaMatch $m) => $m->getAllPlayers()->pluck('player_id'))
        ->map(fn ($id) => (int) $id);

    expect($ids->count())->toBe($ids->unique()->count());
});

it('si otro proceso tiene el turno, la peticion no se queda colgada', function () {
    // Esto es lo que evita que la pagina se caiga con varias entradas a la vez.
    // Quien llega y encuentra el turno ocupado espera un rato corto y, si no le
    // toca, se va sin emparejar: su fila esta guardada y la coge el barrido
    // siguiente o el reloj del minuto. Lo que NO puede hacer es quedarse
    // esperando hasta que el servidor corte la peticion.
    duelistaCola('turno-a', 'alsius', 1000);
    duelistaCola('turno-b', 'ignis', 1005);

    $candado = Cache::lock('arena:emparejamiento', 60);

    expect($candado->get())->toBeTrue();

    try {
        $inicio = microtime(true);
        $creadas = app(ArenaMatchmakingService::class)->processQueue(false);
        $tardanza = microtime(true) - $inicio;
    } finally {
        $candado->release();
    }

    // Se rinde sin crear nada, y en un plazo acotado.
    expect($creadas)->toBe(0)
        ->and($tardanza)->toBeLessThan(20.0)
        ->and(ArenaMatch::query()->count())->toBe(0);

    // Y la cola sigue intacta, asi que el siguiente barrido la empareja.
    expect(app(ArenaMatchmakingService::class)->processQueue(false))->toBe(1);
});

it('un barrido que ya tiene el turno no vuelve a pedirselo a si mismo', function () {
    // Salvaguarda preventiva: cancelar un cruce puede volver a emparejar, y si
    // esa llamada de dentro pidiera el turno otra vez se encontraria ocupada
    // por ella misma y esperaria hasta agotar el plazo, parando la cola.
    //
    // Hoy ningun camino anida -las llamadas de dentro pasan siempre con el
    // reencolado apagado-, asi que esto se prueba poniendo la marca a mano en
    // vez de fingir un camino que no existe.
    duelistaCola('anid-a', 'alsius', 1000);
    duelistaCola('anid-b', 'ignis', 1005);

    $servicio = app(ArenaMatchmakingService::class);
    $marca = new ReflectionProperty($servicio, 'barriendo');
    $marca->setAccessible(true);
    $marca->setValue($servicio, true);

    // Se simula el anidamiento entero: la marca dice "este barrido es mio" Y el
    // turno esta cogido, que es justo lo que pasaria dentro de un barrido de
    // verdad. Sin la salvaguarda, esto espera el plazo completo y no empareja.
    $candado = Cache::lock('arena:emparejamiento', 60);

    expect($candado->get())->toBeTrue();

    try {
        $inicio = microtime(true);
        $creadas = $servicio->processQueue(false);
        $tardanza = microtime(true) - $inicio;
    } finally {
        $candado->release();
    }

    expect($creadas)->toBe(1)
        ->and($tardanza)->toBeLessThan(3.0);
});

// ------------------------------------------------------------ modalidades

it('el reparto no mezcla modalidades', function () {
    foreach (ArenaMode::all() as $mode) {
        AppSetting::setValue(ArenaMode::settingKey($mode), '1', 'modes', 'boolean', true);
    }

    // Dos duelistas que encajan perfecto, y dos de 2v2 del mismo reino que no
    // llegan a formar equipo. Solo puede salir el duelo.
    duelistaCola('mez-a', 'alsius', 1000);
    duelistaCola('mez-b', 'ignis', 1000);

    foreach ([['mez-c', 'syrtis'], ['mez-d', 'syrtis']] as [$sufijo, $realm]) {
        $p = duelistaCola($sufijo, $realm, 1000);
        Queue::query()->where('player_id', $p->id)->update(['arena_mode' => ArenaMode::TWO_V_TWO]);
    }

    app(ArenaMatchmakingService::class)->processQueue(false);

    $partidas = ArenaMatch::query()->get();

    expect($partidas)->toHaveCount(1)
        ->and($partidas->first()->arena_mode)->toBe(ArenaMode::ONE_V_ONE);
});
