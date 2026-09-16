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

it('el reparto no mezcla modalidades ni cuando el cruce mezclado seria el mejor', function () {
    foreach (ArenaMode::all() as $mode) {
        AppSetting::setValue(ArenaMode::settingKey($mode), '1', 'modes', 'boolean', true);
    }

    // Los MMR estan puestos para que el cruce ILEGAL sea el mas apetecible:
    // el duelista de 1000 encaja clavado con el equipo de 2v2, y su unico
    // rival legal esta a 400 puntos. Si el veto de modalidad no estuviera, el
    // emparejador elegiria el mezclado, que es uno contra dos.
    //
    // La version anterior de este test ponia a los duelistas empatados y al
    // equipo de 2v2 en el mismo sitio: el cruce mezclado empataba y perdia el
    // desempate, asi que el test salia verde con el veto borrado. Probaba que
    // gana el mejor cruce, no que el ilegal no se puede elegir.
    duelistaCola('mez-a', 'alsius', 1000);
    duelistaCola('mez-b', 'ignis', 1400);

    foreach ([['mez-c', 'syrtis'], ['mez-d', 'syrtis']] as [$sufijo, $realm]) {
        $p = duelistaCola($sufijo, $realm, 1000);
        Queue::query()->where('player_id', $p->id)->update(['arena_mode' => ArenaMode::TWO_V_TWO]);
    }

    app(ArenaMatchmakingService::class)->processQueue(false);

    $partidas = ArenaMatch::query()->get();

    // Sale el duelo malo, no el mezclado bueno.
    expect($partidas)->toHaveCount(1)
        ->and($partidas->first()->arena_mode)->toBe(ArenaMode::ONE_V_ONE)
        ->and($partidas->first()->player_count)->toBe(2);
});

it('una cuenta no se empareja contra si misma aunque sea el mejor cruce', function () {
    // Se permiten cinco personajes por cuenta y pueden ser de reinos distintos.
    // Aqui los dos de la misma cuenta encajan clavados y el rival de verdad
    // esta a 500 puntos: sin el veto, el emparejador elegiria el espejo y esa
    // persona se regalaria una victoria, PL y MMR.
    $mio = duelistaCola('cuenta-a', 'alsius', 1000);

    $gemelo = Player::create([
        'user_id' => $mio->user_id,
        'character_name' => 'EmpGemelo',
        'subclass' => 'hunter',
        'realm' => 'ignis',
        'pl_points' => 30,
        'mmr' => 1000,
        'trust_score' => 100,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'is_active' => true,
    ]);

    Queue::create([
        'player_id' => $gemelo->id,
        'queue_type' => 'random',
        'arena_mode' => ArenaMode::ONE_V_ONE,
        'status' => 'waiting',
        'estimated_mmr' => 1000,
        'joined_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    duelistaCola('cuenta-b', 'syrtis', 1500);

    app(ArenaMatchmakingService::class)->processQueue(false);

    // Sale un cruce feo contra el de 1500, no el espejo contra uno mismo.
    $partidas = ArenaMatch::query()->get();

    expect($partidas)->toHaveCount(1);

    $cuentas = $partidas->first()->getAllPlayers()
        ->map(fn ($p) => (int) Player::find($p['player_id'])->user_id);

    expect($cuentas->unique())->toHaveCount(2);
});

it('el orden del barrido importa: los cruces buenos se cogen primero', function () {
    // Ocho en cola, cuatro por reino, emparejados de dos en dos con 10 puntos
    // de diferencia y separados 400 entre parejas. Solo hay un reparto de 40
    // puntos totales, y para dar con el hay que ir cogiendo los cruces buenos
    // en orden. Barriendo del peor al mejor sale muchisimo peor.
    $r = repartir([
        ['alsius', 800], ['ignis', 810],
        ['alsius', 1200], ['ignis', 1210],
        ['alsius', 1600], ['ignis', 1610],
        ['alsius', 2000], ['ignis', 2010],
    ]);

    expect($r['partidas'])->toBe(4)
        ->and($r['suma'])->toBe(40)
        ->and($r['peor'])->toBe(10);
});

it('la ventana de vecinos no puede dejar a nadie sin mirar', function () {
    // Mas de ochenta del mismo reino seguidos -que es el tamaño de la ventana-
    // y un puñado del reino contrario al final de la tabla de MMR. Si la
    // ventana mirase solo a los ochenta vecinos inmediatos, los del principio
    // no verian jamas a un rival legal.
    $cola = [];

    for ($i = 0; $i < 100; $i++) {
        $cola[] = ['alsius', 800 + $i];
    }

    for ($i = 0; $i < 10; $i++) {
        $cola[] = ['ignis', 1500 + $i];
    }

    $r = repartir($cola);

    // Caben diez partidas -hay diez Ignis- y salen las diez.
    expect($r['partidas'])->toBe(10)
        ->and($r['sueltos'])->toBe(90);
});

it('rescatar a los sueltos hace falta de verdad con la cola grande', function () {
    // Ciento veinte en cola repartidos a partes iguales. El barrido por
    // puntuacion, el solo, deja fuera a decenas: los sueltos acaban siendo
    // todos del mismo reino. Tienen que salir las sesenta partidas.
    $cola = [];
    $reinos = ['alsius', 'ignis', 'syrtis'];

    for ($i = 0; $i < 120; $i++) {
        $cola[] = [$reinos[$i % 3], 1000];
    }

    $r = repartir($cola);

    expect($r['partidas'])->toBe(60)
        ->and($r['sueltos'])->toBe(0);
});

// ---------------------------------------------------- el orden de la tabla

it('con el MMR empatado, la tabla de niveles intercala los reinos', function () {
    // El dia del lanzamiento todo el mundo tiene 1000 de MMR, asi que la cola
    // entera empata. Los equipos llegan al emparejador agrupados por reino, de
    // modo que desempatar por su posicion en la lista los ordenaba por reino:
    // alsius, alsius, alsius... y la ventana de vecinos que mira cada equipo
    // solo veia gente de su propio reino, que es justo con quien no puede
    // jugar.
    //
    // Con 900 en cola y todos a 1000, eso costaba 13 segundos y 123 MB en vez
    // de 8 y 70. Ya no rompe el reparto -el rescate de sueltos lo arregla
    // igual-, pero lo hace cuesta arriba, asi que la propiedad se fija aqui.
    $teams = [];

    foreach (['alsius', 'ignis', 'syrtis'] as $realm) {
        for ($i = 0; $i < 5; $i++) {
            $teams[] = ['realm' => $realm, 'avg_mmr' => 1000];
        }
    }

    $metodo = new ReflectionMethod(ArenaMatchmakingService::class, 'ordenPorMmr');
    $metodo->setAccessible(true);
    $orden = $metodo->invoke(app(ArenaMatchmakingService::class), $teams);

    $reinos = array_map(fn (int $i) => $teams[$i]['realm'], $orden);

    // Cada tres puestos consecutivos salen los tres reinos, uno de cada.
    foreach (array_chunk($reinos, 3) as $tramo) {
        expect(array_unique($tramo))->toHaveCount(3);
    }
});

it('con el MMR distinto, la tabla sigue ordenada por nivel', function () {
    // Mezclar reinos al empatar no puede alterar el orden cuando no hay empate.
    $teams = [
        ['realm' => 'alsius', 'avg_mmr' => 1400],
        ['realm' => 'alsius', 'avg_mmr' => 900],
        ['realm' => 'ignis', 'avg_mmr' => 1100],
        ['realm' => 'syrtis', 'avg_mmr' => 1000],
    ];

    $metodo = new ReflectionMethod(ArenaMatchmakingService::class, 'ordenPorMmr');
    $metodo->setAccessible(true);
    $orden = $metodo->invoke(app(ArenaMatchmakingService::class), $teams);

    expect(array_map(fn (int $i) => $teams[$i]['avg_mmr'], $orden))
        ->toBe([900, 1000, 1100, 1400]);
});

// ----------------------------------------------- mover tres cruces a la vez

it('desatasca un reparto que no mejora tocando dos cruces pero si tocando tres', function () {
    // Hay repartos atascados: ningun intercambio entre dos cruces los mejora y
    // aun asi, moviendo tres a la vez, salen bastante mejor. Son pocos -uno de
    // cada cien- pero cuando pasan el sobrecoste es grande y se lo comen
    // personas concretas.
    //
    // Esta cola sale en 1026 puntos sin las rotaciones de tres y en 766 con
    // ellas, que es el optimo comprobado probandolos todos.
    $r = repartir([
        ['ignis', 1112], ['alsius', 996], ['ignis', 973], ['ignis', 1051],
        ['alsius', 864], ['alsius', 856], ['alsius', 751], ['alsius', 1375],
        ['ignis', 1350], ['syrtis', 1283], ['ignis', 964], ['syrtis', 1309],
    ]);

    expect($r['partidas'])->toBe(6)
        ->and($r['suma'])->toBe(766);
});

// ------------------------------------------- repartir en la peticion o no

it('con la cola enorme, una peticion web no reparte: lo deja al reloj', function () {
    // Repartir una cola de cientos mientras alguien mira una pagina en blanco
    // es la forma de que el servidor corte la peticion a medias, y peor, de que
    // la corte con el turno cogido. Pasada la raya, quien entra a la cola entra
    // y ya.
    $cola = [];
    $reinos = ['alsius', 'ignis', 'syrtis'];

    for ($i = 0; $i < 260; $i++) {
        $cola[] = [$reinos[$i % 3], 1000 + $i];
    }

    foreach ($cola as $i => [$realm, $mmr]) {
        duelistaCola('gr' . $i, $realm, $mmr);
    }

    // El reloj ha pasado hace nada, asi que la web puede fiarse de el.
    Cache::put('arena:ultimo_barrido_del_reloj', now()->timestamp, 600);

    $servicio = app(ArenaMatchmakingService::class);
    $enConsola = new ReflectionProperty(app(), 'isRunningInConsole');
    $enConsola->setAccessible(true);
    $enConsola->setValue(app(), false);

    try {
        expect($servicio->processQueue(false))->toBe(0)
            ->and(ArenaMatch::query()->count())->toBe(0);
    } finally {
        $enConsola->setValue(app(), true);
    }

    // Y por linea de comandos -que es como corre el cron- si reparte.
    expect($servicio->processQueue(false))->toBe(130);
});

it('si el reloj no da señales, la peticion reparte aunque la cola sea enorme', function () {
    // Sin esta condicion el limite era una trampa que se cerraba sola: si el
    // cron no esta configurado, la cola crece, pasa de la raya, las peticiones
    // dejan de repartir por respeto a un reloj que no existe, y la cola no se
    // vacia nunca. Mejor una peticion lenta que un ladder muerto en silencio.
    $reinos = ['alsius', 'ignis', 'syrtis'];

    for ($i = 0; $i < 260; $i++) {
        duelistaCola('sr' . $i, $reinos[$i % 3], 1000 + $i);
    }

    Cache::forget('arena:ultimo_barrido_del_reloj');

    $enConsola = new ReflectionProperty(app(), 'isRunningInConsole');
    $enConsola->setAccessible(true);
    $enConsola->setValue(app(), false);

    try {
        expect(app(ArenaMatchmakingService::class)->processQueue(false))->toBe(130);
    } finally {
        $enConsola->setValue(app(), true);
    }
});

it('rescata a los sueltos buscando donante entre los cruces que de verdad sirven', function () {
    // Noventa en cola con el MMR TODOS a 1000 y algo mas de la mitad de un solo
    // reino. Caben 40 partidas. El barrido deja sueltos a decenas de Alsius, y
    // para recolocarlos hay que deshacer cruces que NO tengan ningun Alsius
    // dentro: esos son una minoria diminuta entre todos los cruces hechos.
    //
    // Buscarlos por cercania de MMR sobre la lista entera, sin filtrar, no daba
    // con ellos: salian 31 partidas de 40, o sea dieciocho personas en cola con
    // rival esperando. Es el caso mas pequeño que lo destapa; con 300 en cola la
    // misma forma perdia 33 partidas.
    $cola = [];
    $mayoritario = 50;

    for ($i = 0; $i < $mayoritario; $i++) {
        $cola[] = ['alsius', 1000];
    }

    for ($i = 0; $i < 90 - $mayoritario; $i++) {
        $cola[] = [['ignis', 'syrtis'][$i % 2], 1000];
    }

    $r = repartir($cola);

    expect($r['partidas'])->toBe(40)
        ->and($r['sueltos'])->toBe(10);
});

it('con un reino muy desbordado y el MMR pegado, coloca a todos los que caben', function () {
    // El caso que encontro la auditoria, y el que mas cuesta acertar: noventa
    // Alsius contra treinta y seis de cada uno de los otros dos, todos con el
    // MMR pegado entre 990 y 1010.
    //
    // Caben 72 partidas -las que permiten los 72 no-Alsius-. El barrido deja
    // sueltos a decenas de Alsius, y para recolocarlos hay que deshacer cruces
    // que NO tengan ningun Alsius dentro. Esos son una minoria diminuta: seis
    // entre sesenta y seis en la cola original. Buscarlos por cercania de MMR
    // sobre la lista entera de cruces no daba con ellos, y dos personas se
    // quedaban en cola con rival esperando.
    $cola = [];

    for ($i = 0; $i < 90; $i++) {
        $cola[] = ['alsius', 990 + ($i % 21)];
    }

    foreach (['ignis', 'syrtis'] as $realm) {
        for ($i = 0; $i < 36; $i++) {
            $cola[] = [$realm, 990 + ($i % 21)];
        }
    }

    $r = repartir($cola);

    expect($r['partidas'])->toBe(72)
        ->and($r['sueltos'])->toBe(18);
});
