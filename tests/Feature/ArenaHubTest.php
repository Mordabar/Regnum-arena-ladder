<?php

use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function hubUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'hub-' . $suffix,
        'discord_username' => 'hub_' . $suffix,
        'name' => 'Hub ' . $suffix,
        'email' => 'hub-' . $suffix . '@example.com',
    ]);
}

function hubPlayer(User $user, string $name, string $realm = 'syrtis', string $subclass = 'hunter'): Player
{
    return Player::create([
        'user_id' => $user->id,
        'character_name' => $name,
        'subclass' => $subclass,
        'realm' => $realm,
        'race' => Player::defaultRace($realm),
        'gender' => 'male',
        'pl_points' => 30,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

it('el lobby lleva la cola dentro: elegir guerrero y pelear pasan en la misma pantalla', function () {
    // El bug que lo motivo: desde el lobby, "Pelear" navegaba a otra pagina en
    // vez de meterte en la cola.
    $user = hubUser('a');
    hubPlayer($user, 'Sylwen');

    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('action="' . route('queue.join') . '"', false)
        ->assertSee('data-champion-id="hub-stage"', false)
        ->assertSee('data-champion-slot', false);
});

it('la url vieja de la cola apunta al lobby y conserva la modalidad', function () {
    $user = hubUser('b');
    hubPlayer($user, 'Reliquia');

    $this->actingAs($user)->get('/queue?mode=2v2')
        ->assertRedirect(route('lobby', ['mode' => '2v2']));
});

/** Un enfrentamiento ya en marcha, con el jugador dentro. */
function hubLiveMatch(Player $mine, Player $foe): ArenaMatch
{
    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-9001',
        'report_token' => 'HUBLIVE1',
        'queue_mode' => 'random',
        'arena_mode' => '1v1',
        'team_a_realm' => $mine->realm,
        'team_b_realm' => $foe->realm,
        'team_a' => [$pack($mine)],
        'team_b' => [$pack($foe)],
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'estimated_mmr_avg' => 1000,
        'player_count' => 2,
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    foreach ([$mine, $foe] as $player) {
        Queue::create([
            'player_id' => $player->id,
            'queue_type' => 'random',
            'arena_mode' => '1v1',
            'status' => 'accepted',
            'match_id' => (string) $match->id,
            'estimated_mmr' => $player->mmr,
            'joined_at' => now()->subMinutes(2),
            'expires_at' => now()->addMinutes(20),
        ]);
    }

    return $match;
}

it('el combate en curso trae su reloj y las figuras de los dos bandos', function () {
    // Lo que faltaba: cuando todos aceptan y el combate arranca, no habia nada
    // que dijera cuanto tiempo queda para pelear y reportar.
    $mine = hubPlayer(hubUser('c'), 'Aeryn', 'syrtis', 'marksman');
    $foe = hubPlayer(hubUser('d'), 'Grumm', 'ignis', 'barbarian');
    $match = hubLiveMatch($mine, $foe);

    $this->actingAs($mine->user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('data-live-match', false)
        ->assertSee('data-clock-expires="' . $match->expires_at->timestamp . '"', false)
        ->assertSee('para pelear')
        ->assertSee('data-champion-id="live-own-0"', false)
        ->assertSee('data-champion-id="live-rival-0"', false);
});

it('el combate en curso tapa el escaparate: una sola figura grande a la vez', function () {
    $mine = hubPlayer(hubUser('e'), 'Solitaria', 'alsius', 'knight');
    $foe = hubPlayer(hubUser('f'), 'Rival', 'ignis', 'warlock');
    hubLiveMatch($mine, $foe);

    $this->actingAs($mine->user)->get(route('lobby'))
        ->assertOk()
        ->assertDontSee('data-champion-id="hub-stage"', false);
});

it('el combate en curso no destapa el nombre del rival', function () {
    $mine = hubPlayer(hubUser('g'), 'Anonima', 'alsius', 'knight');
    $foe = hubPlayer(hubUser('h'), 'NombreSecreto', 'ignis', 'warlock');
    hubLiveMatch($mine, $foe);

    $this->actingAs($mine->user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('Guerrero Anónimo')
        ->assertDontSee('NombreSecreto');
});

it('en cola el reloj cuenta desde que entro y se ve el pulso por reino', function () {
    $user = hubUser('i');
    $player = hubPlayer($user, 'Paciente', 'ignis', 'warlock');

    $queue = Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => '2v2',
        'status' => 'waiting',
        'estimated_mmr' => $player->mmr,
        'joined_at' => now()->subMinutes(3),
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('data-clock-since="' . $queue->joined_at->timestamp . '"', false)
        ->assertSee('data-queue-pulse-total', false)
        ->assertSee('data-champion-id="queue-stage"', false)
        ->assertSee('Salir de la cola');
});

it('el rival puede confirmar el reporte sin salir del lobby', function () {
    // Antes "Subir el reporte" y su respuesta vivian en otra pagina. El formulario
    // del lobby apuntaba ademas a un campo que el controlador no lee.
    \Illuminate\Support\Facades\Storage::fake('arena_reports');

    $mine = hubPlayer(hubUser('conf-a'), 'Confirmadora', 'syrtis', 'hunter');
    $foe = hubPlayer(hubUser('conf-b'), 'Reportador', 'ignis', 'knight');
    $match = hubLiveMatch($mine, $foe);

    app(\App\Services\ArenaMatchResultService::class)
        ->submitSyntheticReport($match, $foe, 'team_b', 'reporte de prueba');

    $response = $this->actingAs($mine->user)->get(route('lobby'));

    $response->assertOk()
        ->assertSee('Confirma si es correcto')
        ->assertSee('name="report_id" value="' . $match->fresh('report')->report->id . '"', false);

    $this->actingAs($mine->user)->post(route('matches.report.confirm'), [
        'report_id' => $match->fresh('report')->report->id,
        'player_id' => $mine->id,
    ])->assertRedirect();

    expect($match->fresh()->status)->toBe('completed')
        ->and($match->fresh()->winner_team)->toBe('team_b');
});

it('quien todavia no ha reportado ve el formulario en el lobby', function () {
    $mine = hubPlayer(hubUser('form-a'), 'Reportadora', 'alsius', 'warlock');
    $foe = hubPlayer(hubUser('form-b'), 'Contrario', 'ignis', 'barbarian');
    $match = hubLiveMatch($mine, $foe);

    $this->actingAs($mine->user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('Subir el reporte del combate')
        ->assertSee('action="' . route('matches.report') . '"', false)
        ->assertSee('name="evidence_files[]"', false);
});

it('el rail cambia de guerrero sin JavaScript y el formulario le sigue', function () {
    // Los slots son enlaces con ?player, no botones que solo pinta un script:
    // sin JavaScript se tenia que poder cambiar de guerrero igual.
    $user = hubUser('rail');
    $primero = hubPlayer($user, 'Primera', 'syrtis', 'hunter');
    $segundo = hubPlayer($user, 'Segunda', 'alsius', 'conjurer');

    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        // El & del enlace viaja escapado en el HTML.
        ->assertSee('player=' . $segundo->id . '"', false)
        ->assertSee('data-subclass="hunter"', false)
        ->assertSee('value="' . $primero->id . '"', false);

    $this->actingAs($user)->get(route('lobby', ['player' => $segundo->id]))
        ->assertOk()
        ->assertSee('data-subclass="conjurer"', false)
        ->assertSee('value="' . $segundo->id . '"', false)
        ->assertSee('data-champion-subclass="conjurer"', false);
});

it('un guerrero de otro usuario no se puede colar por la url', function () {
    $user = hubUser('rail-mio');
    $mio = hubPlayer($user, 'Mio');
    $ajeno = hubPlayer(hubUser('rail-ajeno'), 'Ajeno', 'ignis', 'warlock');

    $this->actingAs($user)->get(route('lobby', ['player' => $ajeno->id]))
        ->assertOk()
        ->assertSee('value="' . $mio->id . '"', false)
        ->assertDontSee('Ajeno');
});

it('el modo se elige sobre la figura y la premade arranca con ese guerrero', function () {
    // Elegir personaje pasaba dos veces y armar party pedia elegirlo una
    // tercera. Ahora el rail manda sobre las tres cosas.
    $user = hubUser('modos');
    $primero = hubPlayer($user, 'Lider', 'syrtis', 'knight');
    hubPlayer($user, 'Companiera', 'syrtis', 'conjurer');

    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('class="arena-console-modes"', false)
        ->assertSee('id="tabBtnPremade"', false)
        ->assertSee('data-party-leader-select', false)
        // El lider viene marcado con el guerrero del escenario.
        ->assertSee('value="' . $primero->id . '"' . "\n" . '                                        data-user', false);
});

it('las reglas se abren en una ventana, no ocupan sitio todo el rato', function () {
    $user = hubUser('reglas');
    hubPlayer($user, 'Curiosa');

    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('Ver reglas de juego')
        ->assertSee('id="modal-arena-rules"', false)
        ->assertSee('el enfrentamiento se anula y no reparte puntos');
});
