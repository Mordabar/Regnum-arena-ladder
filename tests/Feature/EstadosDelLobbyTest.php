<?php

use App\Models\AppSetting;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Support\ArenaMode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El lobby se acuerda de donde estabas: modalidad, tipo de partida y guerrero.
 *
 * Antes cada visita sin ?mode devolvia al jugador a 2v2, y mas de uno entraba en
 * la cola equivocada sin darse cuenta.
 */
beforeEach(function () {
    foreach (ArenaMode::all() as $modo) {
        AppSetting::setValue(ArenaMode::settingKey($modo), '1', 'modes', 'boolean', true);
    }
});

function guerreroDelLobby(string $s, string $reino = 'ignis', ?User $user = null): Player
{
    $user ??= User::create([
        'discord_id' => 'lb-' . $s, 'discord_username' => 'lb_' . $s,
        'name' => 'Lb ' . $s, 'email' => 'lb-' . $s . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id, 'character_name' => 'Lb' . ucfirst($s), 'subclass' => 'hunter',
        'realm' => $reino, 'pl_points' => 10, 'mmr' => 1000, 'trust_score' => 100, 'is_active' => true,
    ]);
}

it('recuerda la ultima modalidad elegida y la usa cuando no se pide ninguna', function () {
    $p = guerreroDelLobby('a');

    // Sin nada recordado, la de por defecto.
    $this->actingAs($p->user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('name="arena_mode" value="2v2"', false);

    // Elige 1v1: se guarda.
    $this->actingAs($p->user)->get(route('lobby', ['mode' => '1v1']))
        ->assertOk()
        ->assertSee('name="arena_mode" value="1v1"', false)
        ->assertCookie('arena_modo', '1v1');

    // Vuelve sin query (recargar, el enlace del menu): sigue en 1v1.
    $this->actingAs($p->user)->withCookie('arena_modo', '1v1')->get(route('lobby'))
        ->assertOk()
        ->assertSee('name="arena_mode" value="1v1"', false);
});

it('recuerda tambien el tipo de partida', function () {
    $p = guerreroDelLobby('b');

    $this->actingAs($p->user)->get(route('lobby', ['mode' => '1v1', 'kind' => 'friendly']))
        ->assertOk()
        ->assertCookie('arena_tipo', 'friendly');

    $this->actingAs($p->user)->withCookie('arena_modo', '1v1')->withCookie('arena_tipo', 'friendly')->get(route('lobby'))
        ->assertOk()
        ->assertSee('name="kind" value="friendly"', false);
});

it('una modalidad recordada que ya esta apagada no deja al jugador en una pantalla muerta', function () {
    $p = guerreroDelLobby('c');
    AppSetting::setValue(ArenaMode::settingKey('1v1'), '0', 'modes', 'boolean', true);

    $this->actingAs($p->user)->withCookie('arena_modo', '1v1')->get(route('lobby'))
        ->assertOk()
        ->assertDontSee('name="arena_mode" value="1v1"', false);
});

it('un valor recordado manipulado se ignora', function () {
    $p = guerreroDelLobby('d');

    $this->actingAs($p->user)->withCookie('arena_modo', '9v9')->withCookie('arena_tipo', '<x>')->get(route('lobby'))
        ->assertOk();
});

it('con una cola en marcha la pantalla es la de esa modalidad aunque se entre sin query', function () {
    $p = guerreroDelLobby('e');
    Queue::create([
        'player_id' => $p->id, 'queue_type' => 'random', 'arena_mode' => '3v3', 'is_ranked' => true,
        'status' => 'waiting', 'estimated_mmr' => 1000, 'joined_at' => now(), 'expires_at' => now()->addMinutes(30),
    ]);

    // Aunque lo ultimo que eligio fuera 1v1, esta en la cola de 3v3.
    $this->actingAs($p->user)->withCookie('arena_modo', '1v1')->get(route('lobby'))
        ->assertOk()
        ->assertCookie('arena_modo', '3v3');
});

it('recuerda el guerrero que se esta usando', function () {
    $primero = guerreroDelLobby('f');
    $segundo = guerreroDelLobby('g', 'alsius', $primero->user);

    $this->actingAs($primero->user)->get(route('lobby', ['player' => $segundo->id]))
        ->assertOk()
        ->assertCookie('arena_guerrero', (string) $segundo->id, false);
});

it('un guerrero ajeno pedido por URL no se guarda', function () {
    $mio = guerreroDelLobby('h');
    $ajeno = guerreroDelLobby('j');

    $this->actingAs($mio->user)->get(route('lobby', ['player' => $ajeno->id]))
        ->assertOk()
        ->assertCookieMissing('arena_guerrero');
});

it('un ?mode raro no rompe el lobby', function () {
    $p = guerreroDelLobby('i');

    $this->actingAs($p->user)->get('/lobby?mode[]=x')->assertOk();
});
