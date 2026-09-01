<?php

use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\QueuePulseService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pulsePlayer(string $suffix, string $realm): Player
{
    $user = User::create([
        'discord_id' => 'pulse-' . $suffix,
        'discord_username' => 'pulso_' . $suffix,
        'name' => 'Pulso ' . $suffix,
        'email' => 'pulso-' . $suffix . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Pulso' . $suffix,
        'subclass' => 'knight',
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function pulseWaiting(Player $player, string $mode = '2v2', ?string $expiresAt = 'future'): Queue
{
    return Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => $mode,
        'status' => 'waiting',
        'estimated_mmr' => $player->mmr,
        'joined_at' => now(),
        'expires_at' => $expiresAt === 'future' ? now()->addMinutes(30) : ($expiresAt === null ? null : now()->subMinute()),
    ]);
}

it('cuenta a los que esperan agrupados por reino', function () {
    pulseWaiting(pulsePlayer('a', 'ignis'));
    pulseWaiting(pulsePlayer('b', 'ignis'));
    pulseWaiting(pulsePlayer('c', 'syrtis'));

    $pulse = app(QueuePulseService::class)->forMode('2v2');

    expect($pulse['total'])->toBe(3);

    $byRealm = collect($pulse['realms'])->keyBy('key');
    expect($byRealm['ignis']['waiting'])->toBe(2)
        ->and($byRealm['syrtis']['waiting'])->toBe(1)
        ->and($byRealm['alsius']['waiting'])->toBe(0);

    // Los tres reinos salen siempre, aunque esten vacios: un hueco a cero
    // informa tanto como un numero, y evita que la lista baile de tamano.
    expect($pulse['realms'])->toHaveCount(3);
});

it('no cuenta colas de otra modalidad ni las caducadas', function () {
    pulseWaiting(pulsePlayer('a', 'ignis'));
    pulseWaiting(pulsePlayer('b', 'ignis'), '3v3');
    pulseWaiting(pulsePlayer('c', 'ignis'), '2v2', 'past');

    $player = pulsePlayer('d', 'ignis');
    pulseWaiting($player)->update(['status' => 'matched']);

    expect(app(QueuePulseService::class)->forMode('2v2')['total'])->toBe(1);
});

it('dice cuanta gente falta contando desde el reino del jugador', function () {
    pulseWaiting(pulsePlayer('a', 'ignis'));

    $pulse = app(QueuePulseService::class)->forMode('2v2', 'ignis');

    expect($pulse['hint'])->toBe('Falta 1 de tu reino y faltan 2 de un reino rival.');
});

it('avisa cuando ya hay gente de sobra para armar el cruce', function () {
    pulseWaiting(pulsePlayer('a', 'ignis'));
    pulseWaiting(pulsePlayer('b', 'ignis'));
    pulseWaiting(pulsePlayer('c', 'syrtis'));
    pulseWaiting(pulsePlayer('d', 'syrtis'));

    $pulse = app(QueuePulseService::class)->forMode('2v2', 'ignis');

    expect($pulse['hint'])->toBe('Ya hay gente suficiente: el cruce se arma en la proxima pasada.');
});

it('omite la pista si no sabemos desde que reino mira el jugador', function () {
    pulseWaiting(pulsePlayer('a', 'ignis'));

    expect(app(QueuePulseService::class)->forMode('2v2')['hint'])->toBeNull();
});

it('el sondeo de estado devuelve el pulso fuera del hash', function () {
    $player = pulsePlayer('a', 'ignis');
    pulseWaiting($player);
    pulseWaiting(pulsePlayer('b', 'syrtis'));

    $response = $this->actingAs($player->user)->getJson(route('queue.state-poll'));

    $response->assertOk()
        ->assertJsonPath('queue_pulse.total', 2)
        ->assertJsonPath('queue_pulse.mode', '2v2');

    // El pulso NO puede entrar en el hash: si entrase, cada entrada o salida
    // de cualquier jugador recargaria la pagina entera a todo el mundo.
    $hash = $response->json('hash');

    pulseWaiting(pulsePlayer('c', 'alsius'));

    $second = $this->actingAs($player->user)->getJson(route('queue.state-poll'));
    expect($second->json('hash'))->toBe($hash)
        ->and($second->json('queue_pulse.total'))->toBe(3);
});

it('la pagina de cola muestra el recuento por reino a quien esta esperando', function () {
    $player = pulsePlayer('a', 'ignis');
    pulseWaiting($player);
    pulseWaiting(pulsePlayer('b', 'syrtis'));
    pulseWaiting(pulsePlayer('c', 'syrtis'));

    $response = $this->actingAs($player->user)->get(route('queue.index'));

    $response->assertOk()
        ->assertSee('data-queue-pulse-total', false)
        ->assertSee('data-queue-pulse-realm="ignis"', false)
        ->assertSee('data-queue-pulse-realm="syrtis"', false)
        // Con 1 propio y 2 rivales en 2v2, le falta un companero de reino.
        ->assertSee('Falta 1 de tu reino.');
});

it('el sondeo no frena mientras el jugador tiene algo en marcha', function () {
    // El frenado progresivo ahorra peticiones cuando no pasa nada, pero
    // aplicarlo con el jugador en cola era justo lo contrario de lo que hace
    // falta: el cruce puede aparecer en cualquier instante.
    $poller = file_get_contents(resource_path('views/components/arena-state-poller.blade.php'));

    expect($poller)->toContain('hasLiveActivity')
        ->and($poller)->toMatch('/if \(hasLiveActivity\(nextState\)\) \{\s*resetCadence\(\);\s*\} else \{\s*stablePolls \+= 1;/');
});

it('cuenta la pista desde el reino con el que el jugador esta encolado', function () {
    // Una cuenta puede tener personajes en varios reinos. La pista se mide con
    // el que esta esperando ahora, no con el primero que aparezca en la lista.
    $ignis = pulsePlayer('propio-ignis', 'ignis');
    $syrtis = Player::create([
        'user_id' => $ignis->user_id,
        'character_name' => 'PulsoSyrtis',
        'subclass' => 'knight',
        'realm' => 'syrtis',
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);

    pulseWaiting($syrtis);
    pulseWaiting(pulsePlayer('rival', 'alsius'));
    pulseWaiting(pulsePlayer('rival-2', 'alsius'));

    $response = $this->actingAs($ignis->user)->getJson(route('queue.state-poll'));

    // Con Syrtis en cola (1 propio) y Alsius con 2, le falta un companero.
    $response->assertOk()->assertJsonPath('queue_pulse.hint', 'Falta 1 de tu reino.');
});
