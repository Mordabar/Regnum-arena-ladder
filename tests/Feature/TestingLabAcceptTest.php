<?php

use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\TestingLabService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function labSession(): array
{
    return [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];
}

/** Un bot del laboratorio: se reconoce por el prefijo de discord_id. */
function labBot(string $suffix, string $realm): Player
{
    $user = User::create([
        'discord_id' => TestingLabService::LAB_DISCORD_PREFIX . $suffix,
        'discord_username' => 'bot_' . $suffix,
        'name' => 'Bot ' . $suffix,
        'email' => $suffix . '@' . TestingLabService::LAB_EMAIL_DOMAIN,
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Bot' . $suffix,
        'subclass' => 'knight',
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

/** Un jugador real, con cuenta de Discord normal. */
function labHuman(string $suffix, string $realm): Player
{
    $user = User::create([
        'discord_id' => 'real-' . $suffix,
        'discord_username' => 'humano_' . $suffix,
        'name' => 'Humano ' . $suffix,
        'email' => $suffix . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Humano' . $suffix,
        'subclass' => 'knight',
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function labPayload(Player ...$players): array
{
    return collect($players)->map(fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ])->all();
}

function labMatch(array $teamA, array $teamB, string $code = 'ARENA-7001'): ArenaMatch
{
    return ArenaMatch::create([
        'match_code' => $code,
        'report_token' => strtoupper('LAB' . substr(md5($code), 0, 7)),
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'syrtis',
        'team_a' => $teamA,
        'team_b' => $teamB,
        'zone' => 'frozen_bridge',
        'status' => 'pending_acceptance',
        'estimated_mmr_avg' => 1000,
        'expires_at' => now()->addMinutes(5),
    ]);
}

function labQueue(Player $player, ArenaMatch $match, string $status = 'matched'): Queue
{
    return Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => '2v2',
        'status' => $status,
        'match_id' => (string) $match->id,
        'estimated_mmr' => $player->mmr,
        'joined_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
}

/**
 * El caso de uso que documenta el propio laboratorio: encolas TU personaje por
 * el flujo normal y usas bots para completar el cruce. Aceptar por los bots
 * solo toca las filas de cola de los bots, asi que tiene que funcionar.
 */
it('acepta por los bots en un match donde tambien juega una persona', function () {
    $bot1 = labBot('a1', 'ignis');
    $bot2 = labBot('a2', 'syrtis');
    $bot3 = labBot('a3', 'syrtis');
    $human = labHuman('h1', 'ignis');

    $match = labMatch(labPayload($bot1, $human), labPayload($bot2, $bot3));

    $botQueues = [labQueue($bot1, $match), labQueue($bot2, $match), labQueue($bot3, $match)];
    $humanQueue = labQueue($human, $match);

    $this->withSession(labSession())
        ->post(route('admin.testing.accept'), ['match_id' => $match->id])
        ->assertRedirect();

    foreach ($botQueues as $queue) {
        expect($queue->fresh()->status)->toBe('accepted');
    }

    // La persona sigue teniendo que aceptar por su cuenta: el laboratorio no
    // acepta en su nombre.
    expect($humanQueue->fresh()->status)->toBe('matched');
    expect($match->fresh()->status)->toBe('pending_acceptance');
});

/**
 * Y cuando la persona acepta, el match arranca. Aceptar por los bots no puede
 * saltarse ese paso.
 */
it('arranca el match solo cuando la persona tambien ha aceptado', function () {
    $bot1 = labBot('b1', 'ignis');
    $bot2 = labBot('b2', 'syrtis');
    $bot3 = labBot('b3', 'syrtis');
    $human = labHuman('h2', 'ignis');

    $match = labMatch(labPayload($bot1, $human), labPayload($bot2, $bot3), 'ARENA-7002');

    labQueue($bot1, $match);
    labQueue($bot2, $match);
    labQueue($bot3, $match);
    $humanQueue = labQueue($human, $match, 'accepted');

    $this->withSession(labSession())
        ->post(route('admin.testing.accept'), ['match_id' => $match->id])
        ->assertRedirect();

    expect($match->fresh()->status)->toBe('in_progress');
});

/**
 * Resolver SI reparte PL y MMR de verdad, asi que ahi la proteccion se queda:
 * un match con una persona dentro no se cierra desde el laboratorio. Pero tiene
 * que decirlo con un mensaje, no con un 404 que no explica nada.
 */
it('se niega a resolver un match con personas y explica por que', function () {
    $bot1 = labBot('c1', 'ignis');
    $bot2 = labBot('c2', 'syrtis');
    $bot3 = labBot('c3', 'syrtis');
    $human = labHuman('h3', 'ignis');

    $match = labMatch(labPayload($bot1, $human), labPayload($bot2, $bot3), 'ARENA-7003');
    $match->update(['status' => 'in_progress']);

    $this->withSession(labSession())
        ->from(route('admin.testing'))
        ->post(route('admin.testing.resolve', $match), ['winner_team' => 'team_a'])
        ->assertRedirect(route('admin.testing'))
        ->assertSessionHasErrors('error');

    expect($match->fresh()->status)->toBe('in_progress');
});
