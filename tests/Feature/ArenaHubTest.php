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
