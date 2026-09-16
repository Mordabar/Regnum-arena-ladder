<?php

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchmakingService;
use App\Services\ArenaMatchResultService;
use App\Services\MatchLineupService;
use App\Support\ArenaMode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El duelo 1v1.
 *
 * Tres cosas lo separan del 2v2 y el 3v3, y son las tres que se prueban aqui:
 * no hay party, el nombre del rival es publico desde el cruce, y el
 * emparejamiento prefiere el espejo -arquero contra arquero- sin quitarle la
 * ultima palabra al MMR.
 *
 * Todo lo demas -PL, MMR, reportes, disputas, sanciones- es el mismo sistema de
 * siempre, y por eso NO se duplica aqui: si el duelo se saliera de la tabla
 * comun seria un ladder aparte, que es justo lo que no se pidio.
 */
function duelistaEn(string $sufijo, string $realm, string $subclass, int $mmr = 1000): Player
{
    $user = User::create([
        'discord_id' => 'duel-' . $sufijo,
        'discord_username' => 'duel_' . $sufijo,
        'name' => 'Duel ' . $sufijo,
        'email' => 'duel-' . $sufijo . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Duel' . ucfirst($sufijo),
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
}

function encolarDuelista(Player $player, ?string $conjurerRole = null): Queue
{
    return Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => ArenaMode::ONE_V_ONE,
        'status' => 'waiting',
        'conjurer_role' => $conjurerRole ?? ($player->subclass === 'conjurer' ? 'offensive' : null),
        'estimated_mmr' => $player->mmr,
        'joined_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
}

/** Enciende solo el duelo, para que la cola no se mezcle con otras modalidades. */
function soloDuelo(): void
{
    foreach (ArenaMode::all() as $mode) {
        AppSetting::setValue(
            ArenaMode::settingKey($mode),
            $mode === ArenaMode::ONE_V_ONE ? '1' : '0',
            'modes',
            'boolean',
            true
        );
    }
}

/** Contra quien acabo emparejado un jugador en el ultimo barrido. */
function rivalDe(Player $player): ?Player
{
    $match = ArenaMatch::query()
        ->where('arena_mode', ArenaMode::ONE_V_ONE)
        ->get()
        ->first(fn (ArenaMatch $m) => in_array($player->id, $m->getAllPlayers()->pluck('player_id')->map(fn ($id) => (int) $id)->all(), true));

    if ($match === null) {
        return null;
    }

    $rivalId = $match->getAllPlayers()
        ->pluck('player_id')
        ->map(fn ($id) => (int) $id)
        ->first(fn (int $id) => $id !== $player->id);

    return $rivalId === null ? null : Player::find($rivalId);
}

beforeEach(function () {
    soloDuelo();
});

// ---------------------------------------------------------------- modalidad

it('el duelo nace apagado', function () {
    // Se enciende a mano desde el panel, como 3v3. Estrenar una modalidad no
    // puede ser un efecto colateral de desplegar.
    AppSetting::query()->where('key', ArenaMode::settingKey(ArenaMode::ONE_V_ONE))->delete();
    AppSetting::flushSettingsCache();

    expect(ArenaMode::isEnabled(ArenaMode::ONE_V_ONE))->toBeFalse();
});

it('encender el duelo no mueve de pantalla a quien juega 2v2', function () {
    // MODES lista 1v1 primero para que las pestanas salgan en orden natural. Si
    // default() devolviera "la primera encendida", abrir el duelo habria
    // cambiado la pantalla de entrada de todo el mundo de golpe.
    foreach (ArenaMode::all() as $mode) {
        AppSetting::setValue(ArenaMode::settingKey($mode), '1', 'modes', 'boolean', true);
    }

    expect(ArenaMode::default())->toBe(ArenaMode::TWO_V_TWO);
});

it('con 2v2 apagada el duelo si puede ser la pantalla de entrada', function () {
    expect(ArenaMode::default())->toBe(ArenaMode::ONE_V_ONE);
});

it('el duelo es un equipo de uno y no admite party', function () {
    expect(ArenaMode::teamSize(ArenaMode::ONE_V_ONE))->toBe(1)
        ->and(ArenaMode::supportsPremade(ArenaMode::ONE_V_ONE))->toBeFalse()
        ->and(ArenaMode::supportsPremade(ArenaMode::TWO_V_TWO))->toBeTrue()
        ->and(ArenaMode::supportsPremade(ArenaMode::THREE_V_THREE))->toBeTrue();
});

// ------------------------------------------------------------ emparejamiento

it('empareja dos duelistas de reinos distintos', function () {
    $a = duelistaEn('base-a', 'alsius', 'hunter');
    $b = duelistaEn('base-b', 'ignis', 'hunter');
    encolarDuelista($a);
    encolarDuelista($b);

    expect(app(ArenaMatchmakingService::class)->processQueue())->toBe(1);

    $match = ArenaMatch::query()->where('arena_mode', ArenaMode::ONE_V_ONE)->firstOrFail();

    expect($match->player_count)->toBe(2)
        ->and(count($match->team_a))->toBe(1)
        ->and(count($match->team_b))->toBe(1)
        ->and($match->status)->toBe('pending_acceptance');
});

it('nunca cruza un duelo contra un equipo de 2v2', function () {
    // El guard vive en buildMatchPairings y ya cubria 2v2 contra 3v3. Con una
    // modalidad de un jugador el error seria mucho mas visible: uno contra dos.
    foreach (ArenaMode::all() as $mode) {
        AppSetting::setValue(ArenaMode::settingKey($mode), '1', 'modes', 'boolean', true);
    }

    encolarDuelista(duelistaEn('mix-a', 'alsius', 'hunter'));

    foreach ([['mix-b', 'ignis'], ['mix-c', 'ignis']] as [$sufijo, $realm]) {
        $p = duelistaEn($sufijo, $realm, 'knight');
        Queue::create([
            'player_id' => $p->id,
            'queue_type' => 'random',
            'arena_mode' => ArenaMode::TWO_V_TWO,
            'status' => 'waiting',
            'estimated_mmr' => $p->mmr,
            'joined_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    // El duelista se queda solo -no hay otro 1v1- y el par de 2v2 tampoco tiene
    // rival de su reino contrario: cero partidas, ninguna mezclada.
    expect(app(ArenaMatchmakingService::class)->processQueue())->toBe(0);
});

it('a igual MMR prefiere el espejo de subclase', function () {
    // El rival CORRECTO se crea el ultimo a proposito. Con todos a 1000 de MMR
    // los tres cruces empatan si la preferencia no existe, y el emparejador
    // -que es avido- se queda con el primer par que evalua, o sea con el orden
    // de creacion. Poniendo el espejo al final, un test que solo midiera ese
    // orden saldria verde apuntando al rival equivocado.
    $yo = duelistaEn('esp-yo', 'alsius', 'marksman', 1000);
    $otro = duelistaEn('esp-no', 'syrtis', 'knight', 1000);
    $espejo = duelistaEn('esp-si', 'ignis', 'marksman', 1000);

    encolarDuelista($yo);
    encolarDuelista($otro);
    encolarDuelista($espejo);

    app(ArenaMatchmakingService::class)->processQueue();

    expect(rivalDe($yo)?->id)->toBe($espejo->id);
});

it('a igual MMR prefiere la misma clase antes que otra', function () {
    // Caballero y barbaro son los dos guerreros: no es el espejo exacto, pero
    // esta mas cerca que un mago.
    // Mismo cuidado que arriba: el rival correcto va el ultimo, para que el
    // test no pueda salir verde por el orden de creacion.
    $yo = duelistaEn('cls-yo', 'alsius', 'knight', 1000);
    $otraClase = duelistaEn('cls-no', 'syrtis', 'warlock', 1000);
    $mismaClase = duelistaEn('cls-si', 'ignis', 'barbarian', 1000);

    encolarDuelista($yo);
    encolarDuelista($otraClase);
    encolarDuelista($mismaClase);

    app(ArenaMatchmakingService::class)->processQueue();

    expect(rivalDe($yo)?->id)->toBe($mismaClase->id);
});

it('el MMR manda: un rival de otra clase que encaja mejor gana al espejo lejano', function () {
    // Esto es lo que el usuario pidio con todas las letras: la preferencia
    // ordena, no bloquea. El espejo esta a 300 de MMR y el mago a 10, y el
    // recargo por cruzar clases son 70 puntos, asi que el mago sale.
    $yo = duelistaEn('mmr-yo', 'alsius', 'hunter', 1000);
    $espejoLejano = duelistaEn('mmr-esp', 'ignis', 'hunter', 1300);
    $cercano = duelistaEn('mmr-cer', 'syrtis', 'warlock', 1010);

    encolarDuelista($yo);
    encolarDuelista($espejoLejano);
    encolarDuelista($cercano);

    app(ArenaMatchmakingService::class)->processQueue();

    expect(rivalDe($yo)?->id)->toBe($cercano->id);
});

/**
 * Con quien acaba emparejado un cazador de 1000 cuando en cola hay un espejo
 * -otro cazador- a `$distancia` puntos de MMR y un brujo con el MMR clavado.
 *
 * Devuelve 'espejo' o 'clavado'. Sirve para medir el umbral de la preferencia
 * en vez de afirmarlo con un numero elegido a dedo.
 */
function aQuienElijeElDuelo(int $distancia): string
{
    Queue::query()->delete();
    ArenaMatch::query()->delete();
    Player::query()->delete();
    User::query()->delete();

    $yo = duelistaEn('um-yo', 'alsius', 'hunter', 1000);
    $espejo = duelistaEn('um-esp', 'ignis', 'hunter', 1000 + $distancia);
    $clavado = duelistaEn('um-cla', 'syrtis', 'warlock', 1000);

    encolarDuelista($yo);
    encolarDuelista($espejo);
    encolarDuelista($clavado);

    app(ArenaMatchmakingService::class)->processQueue();

    return rivalDe($yo)?->id === $espejo->id ? 'espejo' : 'clavado';
}

it('la preferencia nunca impone un rival que encaje mucho peor de MMR', function () {
    // Esto NO fija un numero a dedo: mide el umbral barriendo distancias y
    // comprueba dos propiedades del resultado.
    $eleccion = collect(range(0, 60))->mapWithKeys(fn (int $d) => [$d => aQuienElijeElDuelo($d)]);

    // 1) Con el MMR empatado gana el espejo: la preferencia existe.
    expect($eleccion[0])->toBe('espejo');

    // 2) El umbral esta por debajo de lo que mueve una sola partida de MMR
    //    (unos 16 puntos), con holgura. Con los 70 de la primera version este
    //    limite se pasaba por 50.
    $ultimoEspejo = $eleccion->filter(fn (string $quien) => $quien === 'espejo')->keys()->max();
    expect($ultimoEspejo)->toBeLessThan(25);

    // 3) Y una vez que el MMR gana, ya no vuelve a perder. Esta es la que mata
    //    la version de los escalones: alli la eleccion saltaba de vuelta al
    //    espejo en el techo de cada escalon -a 51 puntos ganaba el MMR y a 99
    //    volvia a ganar el espejo-, asi que un punto de MMR invertia la
    //    decision en cualquier punto de la escala.
    $traseUmbral = $eleccion->filter(fn (string $quien, int $d) => $d > $ultimoEspejo);
    expect($traseUmbral->unique()->values()->all())->toBe(['clavado']);
});

it('entre dos guerreros, el que encaja de MMR le gana al espejo exacto', function () {
    // Un caballero mira primero a otro caballero, pero un barbaro con el MMR
    // clavado esta mas cerca que un caballero a 30 puntos. La preferencia de
    // subclase pesa menos que la de clase, y tambien tiene techo.
    $yo = duelistaEn('gue-yo', 'alsius', 'knight', 1000);
    $espejoLejano = duelistaEn('gue-esp', 'ignis', 'knight', 1030);
    $barbaroClavado = duelistaEn('gue-bar', 'syrtis', 'barbarian', 1000);

    encolarDuelista($yo);
    encolarDuelista($espejoLejano);
    encolarDuelista($barbaroClavado);

    app(ArenaMatchmakingService::class)->processQueue();

    expect(rivalDe($yo)?->id)->toBe($barbaroClavado->id);
});

it('con el MMR casi igualado la preferencia decide', function () {
    // La otra cara de la moneda, con un ejemplo legible: 10 puntos de MMR es
    // menos de lo que mueve una partida, asi que los dos rivales encajan
    // practicamente igual y sale el espejo. El umbral exacto lo mide el test de
    // arriba barriendo distancias; este solo deja un caso concreto a la vista.
    $yo = duelistaEn('pref-yo', 'alsius', 'hunter', 1000);
    $espejo = duelistaEn('pref-esp', 'ignis', 'hunter', 1010);
    $cercano = duelistaEn('pref-cer', 'syrtis', 'warlock', 1000);

    encolarDuelista($yo);
    encolarDuelista($espejo);
    encolarDuelista($cercano);

    app(ArenaMatchmakingService::class)->processQueue();

    expect(rivalDe($yo)?->id)->toBe($espejo->id);
});

it('sin espejo disponible empareja igual, no deja a nadie esperando', function () {
    $a = duelistaEn('solo-a', 'alsius', 'conjurer');
    $b = duelistaEn('solo-b', 'ignis', 'barbarian');
    encolarDuelista($a);
    encolarDuelista($b);

    expect(app(ArenaMatchmakingService::class)->processQueue())->toBe(1);
});

it('un conjurador puede entrar al duelo', function () {
    // En equipos, un conjurador sin rol declarado tumba al equipo entero
    // (invalid_conjurer_roles). Con un jugador por lado esa regla sigue viva y
    // podria haber dejado a los conjuradores fuera del duelo sin avisar.
    $a = duelistaEn('conj-a', 'alsius', 'conjurer');
    $b = duelistaEn('conj-b', 'ignis', 'conjurer');
    encolarDuelista($a, 'support');
    encolarDuelista($b, 'offensive');

    expect(app(ArenaMatchmakingService::class)->processQueue())->toBe(1);
});

// ------------------------------------------------------------- vuelta entera

it('un duelo se juega de punta a punta y mueve el mismo ladder', function () {
    // La prueba que de verdad importa: el duelo no es un ladder aparte. Se
    // empareja, se acepta, se reporta, se confirma, y el PL sale y entra de la
    // misma tabla que en 2v2. Si alguna cuenta asumiera equipos de dos, aqui
    // reventaria o repartiria de mas.
    $ganador = duelistaEn('e2e-a', 'alsius', 'marksman');
    $perdedor = duelistaEn('e2e-b', 'ignis', 'marksman');
    encolarDuelista($ganador);
    encolarDuelista($perdedor);

    $resultados = app(ArenaMatchResultService::class);

    app(ArenaMatchmakingService::class)->processQueue();
    $match = ArenaMatch::query()->where('arena_mode', ArenaMode::ONE_V_ONE)->firstOrFail();

    Queue::query()->where('match_id', (string) $match->id)->update(['status' => 'accepted']);

    expect($resultados->promoteMatchToInProgressIfReady($match->fresh()))->toBeTrue();

    $match = $match->fresh();
    $ladoGanador = $match->getTeamSideForPlayer($ganador->id);

    $resultados->submitSyntheticReport($match, $ganador, $ladoGanador, 'duelo de prueba');
    $resultados->confirmReportForRival($match->fresh('report')->report, 'Confirmado');

    $match = $match->fresh();

    expect($match->status)->toBe('completed')
        ->and($match->winner_team)->toBe($ladoGanador)
        // Dos filas de resultado, una por duelista. Ni cuatro ni una.
        ->and($match->results()->count())->toBe(2);

    $ganador = $ganador->fresh();
    $perdedor = $perdedor->fresh();

    expect($ganador->wins)->toBe(1)
        ->and($ganador->losses)->toBe(0)
        ->and($perdedor->wins)->toBe(0)
        ->and($perdedor->losses)->toBe(1)
        ->and($ganador->matches_played)->toBe(1)
        ->and($perdedor->matches_played)->toBe(1)
        ->and($ganador->mmr)->toBeGreaterThan($perdedor->mmr);

    // Y las colas se cierran, como en cualquier partida terminada.
    expect(Queue::query()
        ->where('match_id', (string) $match->id)
        ->whereIn('status', ['matched', 'accepted'])
        ->count())->toBe(0);
});

// ------------------------------------------------------------------- nombres

it('el nombre del rival es publico desde el cruce', function () {
    $a = duelistaEn('vis-a', 'alsius', 'knight');
    $b = duelistaEn('vis-b', 'ignis', 'knight');
    encolarDuelista($a);
    encolarDuelista($b);

    app(ArenaMatchmakingService::class)->processQueue();
    $match = ArenaMatch::query()->where('arena_mode', ArenaMode::ONE_V_ONE)->firstOrFail();

    expect(MatchLineupService::namesRevealed($match))->toBeTrue();

    $lineup = app(MatchLineupService::class)->forViewer($match, [$a->id]);

    expect($lineup['names_revealed'])->toBeTrue()
        ->and($lineup['rival'][0]['name'])->toBe($b->character_name);
});

it('en 2v2 el nombre del rival sigue oculto hasta el final', function () {
    // La red de seguridad del cambio: revelar en el duelo no podia arrastrar al
    // resto. Se comprueba con el mismo metodo que decide en las dos pantallas.
    $match = new ArenaMatch(['arena_mode' => ArenaMode::TWO_V_TWO, 'status' => 'in_progress']);

    expect(MatchLineupService::namesRevealed($match))->toBeFalse();

    $match->status = 'completed';
    expect(MatchLineupService::namesRevealed($match))->toBeTrue();
});

// -------------------------------------------------------------------- party

it('la cola normal del duelo se abre con un solo personaje', function () {
    $player = duelistaEn('cola-a', 'alsius', 'hunter');

    $this->actingAs($player->user)->post(route('queue.join'), [
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => ArenaMode::ONE_V_ONE,
    ])->assertRedirect();

    expect(Queue::query()->where('player_id', $player->id)->where('arena_mode', ArenaMode::ONE_V_ONE)->exists())->toBeTrue();
});

it('no se puede armar una party de uno en el duelo', function () {
    // El boton ya no existe, pero el formulario es una peticion como otra
    // cualquiera: sin este guard, una party de una persona pasaba la validacion
    // de tamano -"exactamente 1 personaje"- y entraba en la cola premade.
    $player = duelistaEn('party-a', 'alsius', 'hunter');

    $this->actingAs($player->user)->post(route('party.create'), [
        'arena_mode' => ArenaMode::ONE_V_ONE,
        'party_player_ids' => [$player->id],
    ])->assertSessionHasErrors('error');

    expect(\App\Models\Party::query()->count())->toBe(0);
});

it('el lobby del duelo ofrece entrar solo y no invitar aliados', function () {
    $player = duelistaEn('lobby-a', 'alsius', 'hunter');

    $this->actingAs($player->user)->get(route('lobby', ['mode' => ArenaMode::ONE_V_ONE]))
        ->assertOk()
        ->assertSee('Entrar al duelo 1v1')
        ->assertDontSee('Invitar aliado')
        // La ventana entera se queda fuera, no solo su boton: dejarla en el HTML
        // era dejar un formulario de party enviable en una modalidad sin party.
        ->assertDontSee('data-modal-open="modal-premade"', false)
        ->assertDontSee('id="premadeForm"', false);
});

it('en 2v2 el boton de invitar sigue estando', function () {
    foreach (ArenaMode::all() as $mode) {
        AppSetting::setValue(ArenaMode::settingKey($mode), '1', 'modes', 'boolean', true);
    }

    $player = duelistaEn('lobby-b', 'alsius', 'hunter');

    $this->actingAs($player->user)->get(route('lobby', ['mode' => ArenaMode::TWO_V_TWO]))
        ->assertOk()
        ->assertSee('Invitar aliado 2v2');
});

// ------------------------------------------------------------------ abandono

it('en un duelo, el acusado de abandono no descarga la captura en directo', function () {
    // Aqui es donde la fuga dolia de verdad. En 2v2 al menos podias señalar a
    // un compañero; en un duelo el unico señalable es el rival, asi que el
    // 100% de los avisos le habrian entregado al enemigo, en mitad de la
    // pelea, la pantalla de quien le acusa.
    \Illuminate\Support\Facades\Storage::fake(
        \App\Models\MatchAbandonmentReport::EVIDENCE_DISK
    );
    \Illuminate\Support\Facades\Storage::disk(\App\Models\MatchAbandonmentReport::EVIDENCE_DISK)
        ->put('match-reports/testing/duelo/prueba.png', 'bytes');

    $yo = duelistaEn('aban-a', 'alsius', 'knight');
    $rival = duelistaEn('aban-b', 'ignis', 'knight');
    encolarDuelista($yo);
    encolarDuelista($rival);

    app(ArenaMatchmakingService::class)->processQueue();
    $match = ArenaMatch::query()->where('arena_mode', ArenaMode::ONE_V_ONE)->firstOrFail();
    Queue::query()->where('match_id', (string) $match->id)->update(['status' => 'accepted']);
    app(ArenaMatchResultService::class)->promoteMatchToInProgressIfReady($match->fresh());

    $aviso = app(\App\Services\ArenaAbandonmentService::class)
        ->report($match->fresh(), $yo, $rival->id, 'ACUSACION-DUELO se desconecto');
    $aviso->update(['evidence_paths' => ['match-reports/testing/duelo/prueba.png']]);

    // Lee de que se le acusa: eso si, siempre.
    $this->actingAs($rival->user)
        ->get(route('matches.show', $match))
        ->assertOk()
        ->assertSee('ACUSACION-DUELO');

    // La imagen no, hasta que el combate cierre.
    $this->actingAs($rival->user)
        ->get(route('matches.abandonment.evidence', ['abandonment' => $aviso, 'slot' => 1]))
        ->assertForbidden();

    $match->fresh()->update(['status' => 'abandoned']);

    $this->actingAs($rival->user)
        ->get(route('matches.abandonment.evidence', ['abandonment' => $aviso, 'slot' => 1]))
        ->assertOk();
});

it('en un duelo, tras avisar del rival ya no se ofrece reportar abandono', function () {
    // En 2v2 siempre quedan otros a quien señalar y el caso no aparece. En el
    // duelo es el estado normal en cuanto avisas una vez: el panel seguia
    // ofreciendo el boton y la ventana solo tenia una opcion deshabilitada,
    // asi que enviar solo podia acabar en un error de validacion.
    $yo = duelistaEn('rep-a', 'alsius', 'knight');
    $rival = duelistaEn('rep-b', 'ignis', 'knight');
    encolarDuelista($yo);
    encolarDuelista($rival);

    app(ArenaMatchmakingService::class)->processQueue();
    $match = ArenaMatch::query()->where('arena_mode', ArenaMode::ONE_V_ONE)->firstOrFail();
    Queue::query()->where('match_id', (string) $match->id)->update(['status' => 'accepted']);
    app(ArenaMatchResultService::class)->promoteMatchToInProgressIfReady($match->fresh());

    $this->actingAs($yo->user)
        ->get(route('matches.show', $match))
        ->assertOk()
        ->assertSee('data-abandonment-panel', false);

    app(\App\Services\ArenaAbandonmentService::class)
        ->report($match->fresh(), $yo, $rival->id, 'Se fue nada mas empezar');

    $this->actingAs($yo->user)
        ->get(route('matches.show', $match))
        ->assertOk()
        ->assertDontSee('data-abandonment-panel', false);
});
