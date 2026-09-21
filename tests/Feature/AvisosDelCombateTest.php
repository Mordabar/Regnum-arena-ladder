<?php

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\MatchPing;
use App\Models\Player;
use App\Models\User;
use App\Services\ArenaMaintenanceService;
use App\Services\MatchPingService;
use App\Support\ArenaMode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Los avisos rapidos del combate.
 *
 * Lo que faltaba era poder decir "voy de camino" sin salir de la pantalla: el
 * rival solo podia mirar un claro vacio y adivinar si el otro venia, se habia
 * muerto por el camino o se habia ido a cenar.
 *
 * Lo que NO puede pasar es que se convierta en una forma de molestar, ni en una
 * rendija por la que se escape el anonimato del rival en 2v2 y 3v3. Eso es lo
 * que se prueba aqui.
 */
function jugadorAviso(string $sufijo, string $realm, string $nombre = null): Player
{
    $user = User::create([
        'discord_id' => 'av-' . $sufijo,
        'discord_username' => 'av_' . $sufijo,
        'name' => 'Av ' . $sufijo,
        'email' => 'av-' . $sufijo . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => $nombre ?? ('Av' . ucfirst($sufijo)),
        'subclass' => 'hunter',
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function cruceCon(Player $a, Player $b, string $modo = ArenaMode::ONE_V_ONE, string $estado = 'in_progress'): ArenaMatch
{
    static $n = 7000;

    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    return ArenaMatch::create([
        'match_code' => 'AV-' . (++$n),
        'report_token' => 'tokav' . $n,
        'queue_mode' => 'random',
        'arena_mode' => $modo,
        'team_a_realm' => $a->realm,
        'team_b_realm' => $b->realm,
        'team_a' => [$pack($a)],
        'team_b' => [$pack($b)],
        'zone' => 'frozen_bridge',
        'status' => $estado,
        'estimated_mmr_avg' => 1000,
        'player_count' => 2,
    ]);
}

// ------------------------------------------------------------------- el envio

it('un jugador del cruce puede avisar', function () {
    $yo = jugadorAviso('a', 'ignis');
    $rival = jugadorAviso('b', 'alsius');
    $match = cruceCon($yo, $rival);

    $resultado = app(MatchPingService::class)->enviar($match, $yo, 'voy');

    expect($resultado['ok'])->toBeTrue()
        ->and(MatchPing::count())->toBe(1);
});

it('quien no juega el cruce no puede avisar en el', function () {
    $yo = jugadorAviso('c', 'ignis');
    $rival = jugadorAviso('d', 'alsius');
    $mirón = jugadorAviso('e', 'syrtis');

    $resultado = app(MatchPingService::class)->enviar(cruceCon($yo, $rival), $mirón, 'voy');

    expect($resultado['ok'])->toBeFalse()
        ->and(MatchPing::count())->toBe(0);
});

it('un codigo inventado no pasa', function () {
    // El texto lo pone el servidor a partir del codigo. Si se aceptara
    // cualquier cosa, el codigo ES el texto y esto seria un chat libre.
    $yo = jugadorAviso('f', 'ignis');
    $match = cruceCon($yo, jugadorAviso('g', 'alsius'));

    expect(app(MatchPingService::class)->enviar($match, $yo, 'lo_que_me_de_la_gana')['ok'])->toBeFalse()
        ->and(MatchPing::count())->toBe(0);
});

it('no se avisa en un combate ya cerrado', function () {
    $yo = jugadorAviso('h', 'ignis');
    $match = cruceCon($yo, jugadorAviso('i', 'alsius'), estado: 'completed');

    expect(app(MatchPingService::class)->enviar($match, $yo, 'voy')['ok'])->toBeFalse();
});

// -------------------------------------------------------------------- el tope

it('el mismo aviso no se puede repetir al momento', function () {
    $yo = jugadorAviso('j', 'ignis');
    $match = cruceCon($yo, jugadorAviso('k', 'alsius'));
    $avisos = app(MatchPingService::class);

    expect($avisos->enviar($match, $yo, 'voy')['ok'])->toBeTrue()
        ->and($avisos->enviar($match, $yo, 'voy')['ok'])->toBeFalse()
        // Otro distinto si, claro: lo que se corta es la repeticion.
        ->and($avisos->enviar($match, $yo, 'cerca')['ok'])->toBeTrue();
});

it('pasado el descanso el mismo aviso vuelve a valer', function () {
    $yo = jugadorAviso('l', 'ignis');
    $match = cruceCon($yo, jugadorAviso('m', 'alsius'));
    $avisos = app(MatchPingService::class);

    $avisos->enviar($match, $yo, 'voy');
    $this->travel(20)->seconds();

    expect($avisos->enviar($match, $yo, 'voy')['ok'])->toBeTrue();
});

it('una rafaga se corta al sexto aviso del minuto', function () {
    // Sin tope, los diez botones son diez formas de molestar al rival.
    $yo = jugadorAviso('n', 'ignis');
    $match = cruceCon($yo, jugadorAviso('o', 'alsius'));
    $avisos = app(MatchPingService::class);

    $codigos = array_keys(MatchPing::CATALOGO);
    $aceptados = 0;

    foreach ($codigos as $code) {
        if ($avisos->enviar($match, $yo, $code)['ok']) {
            $aceptados++;
        }
    }

    expect($aceptados)->toBe(6);

    // Y al minuto siguiente se puede volver a avisar.
    $this->travel(61)->seconds();

    expect($avisos->enviar($match, $yo, 'vamos')['ok'])->toBeTrue();
});

// ----------------------------------------------------------------- el anonimato

it('en 2v2 el aviso del rival llega sin nombre', function () {
    // El anonimato de 2v2 y 3v3 no se rompe por una rendija: un aviso firmado
    // con el nombre del rival seria la forma mas tonta de saltarselo.
    $yo = jugadorAviso('p', 'ignis', 'Miyo');
    $rival = jugadorAviso('q', 'alsius', 'Surival');
    $match = cruceCon($yo, $rival, modo: ArenaMode::TWO_V_TWO);

    app(MatchPingService::class)->enviar($match, $rival, 'llegue');

    $historial = app(MatchPingService::class)->historial($match, $yo);

    expect($historial)->toHaveCount(1)
        ->and($historial[0]['nombre'])->toBe('Rival')
        ->and($historial[0]['mio'])->toBeFalse();
});

it('en 2v2 mis propios avisos si llevan mi nombre', function () {
    $yo = jugadorAviso('r', 'ignis', 'Miyo');
    $match = cruceCon($yo, jugadorAviso('s', 'alsius'), modo: ArenaMode::TWO_V_TWO);

    app(MatchPingService::class)->enviar($match, $yo, 'voy');

    expect(app(MatchPingService::class)->historial($match, $yo)[0]['nombre'])->toBe('Miyo');
});

it('en el duelo aceptado el aviso del rival lleva su nombre', function () {
    // En duelo el nombre ya es publico en cuanto se acepta, asi que esconderlo
    // aqui seria una incoherencia: se ve en la alineacion y no en el aviso.
    $yo = jugadorAviso('t', 'ignis', 'Miyo');
    $rival = jugadorAviso('u', 'alsius', 'Surival');
    $match = cruceCon($yo, $rival);

    app(MatchPingService::class)->enviar($match, $rival, 'llegue');

    expect(app(MatchPingService::class)->historial($match, $yo)[0]['nombre'])->toBe('Surival');
});

it('antes de aceptar el duelo tampoco se firma con nombre', function () {
    $yo = jugadorAviso('v', 'ignis', 'Miyo');
    $rival = jugadorAviso('w', 'alsius', 'Surival');
    $match = cruceCon($yo, $rival, estado: 'pending_acceptance');

    app(MatchPingService::class)->enviar($match, $rival, 'voy');

    expect(app(MatchPingService::class)->historial($match, $yo)[0]['nombre'])->toBe('Rival');
});

// ------------------------------------------------------------------- limpieza

it('los avisos se van con el combate', function () {
    // Viven lo que vive el enfrentamiento. Guardarlos solo haria crecer una
    // tabla que no se consulta jamas.
    $yo = jugadorAviso('x', 'ignis');
    $vivo = cruceCon($yo, jugadorAviso('y', 'alsius'));
    $cerrado = cruceCon($yo, jugadorAviso('z', 'syrtis'), estado: 'completed');

    app(MatchPingService::class)->enviar($vivo, $yo, 'voy');
    MatchPing::create(['match_id' => (string) $cerrado->id, 'player_id' => $yo->id, 'code' => 'llegue']);

    expect(MatchPing::count())->toBe(2);

    app(MatchPingService::class)->limpiarCerrados();

    expect(MatchPing::count())->toBe(1)
        ->and(MatchPing::first()->match_id)->toBe((string) $vivo->id);
});

it('el mantenimiento limpia los avisos por su cuenta', function () {
    $yo = jugadorAviso('aa', 'ignis');
    $cerrado = cruceCon($yo, jugadorAviso('bb', 'alsius'), estado: 'completed');
    MatchPing::create(['match_id' => (string) $cerrado->id, 'player_id' => $yo->id, 'code' => 'voy']);

    $resumen = app(ArenaMaintenanceService::class)->runTick(false);

    expect($resumen['pings_deleted'])->toBe(1)
        ->and(MatchPing::count())->toBe(0);
});

// ----------------------------------------------------------------- el endpoint

it('el endpoint rechaza avisar con el personaje de otro', function () {
    // Sin esto, cambiar un numero en la peticion bastaria para mandar avisos
    // en nombre del rival.
    $yo = jugadorAviso('cc', 'ignis');
    $rival = jugadorAviso('dd', 'alsius');
    $match = cruceCon($yo, $rival);

    $this->actingAs($yo->user)
        ->postJson(route('matches.ping'), [
            'match_id' => $match->id,
            'player_id' => $rival->id,
            'code' => 'voy',
        ])
        ->assertStatus(403);

    expect(MatchPing::count())->toBe(0);
});

it('el endpoint devuelve el historial ya resuelto', function () {
    $yo = jugadorAviso('ee', 'ignis', 'Miyo');
    $match = cruceCon($yo, jugadorAviso('ff', 'alsius'));

    $this->actingAs($yo->user)
        ->postJson(route('matches.ping'), [
            'match_id' => $match->id,
            'player_id' => $yo->id,
            'code' => 'muerto',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('pings.0.nombre', 'Miyo')
        ->assertJsonPath('pings.0.texto', 'Me han matado')
        ->assertJsonPath('pings.0.mio', true);
});

it('sin sesion no se avisa', function () {
    $yo = jugadorAviso('gg', 'ignis');
    $match = cruceCon($yo, jugadorAviso('hh', 'alsius'));

    $this->postJson(route('matches.ping'), [
        'match_id' => $match->id,
        'player_id' => $yo->id,
        'code' => 'voy',
    ])->assertStatus(401);
});
