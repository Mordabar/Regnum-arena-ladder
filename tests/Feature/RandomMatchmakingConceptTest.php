<?php

use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchmakingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeConceptQueueUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'concept-queue-' . $suffix,
        'discord_username' => 'concept_' . $suffix,
        'name' => 'Concept ' . $suffix,
        'email' => $suffix . '@example.com',
    ]);
}

function makeConceptQueuePlayer(
    string $suffix,
    string $realm,
    string $subclass,
    int $mmr
): Player {
    return Player::create([
        'user_id' => makeConceptQueueUser($suffix)->id,
        'character_name' => 'Concept ' . $suffix,
        'subclass' => $subclass,
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => $mmr,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function queueConceptPlayer(Player $player, ?string $conjurerRole = null): Queue
{
    return Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'status' => 'waiting',
        'conjurer_role' => $conjurerRole,
        'estimated_mmr' => $player->mmr,
        'joined_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
}

function makeConceptTeamPayload(array $players, array $roles = []): array
{
    return collect($players)->map(function (Player $player) use ($roles) {
        return [
            'player_id' => $player->id,
            'character_name' => $player->character_name,
            'subclass' => $player->subclass,
            'realm' => $player->realm,
            'discord_id' => $player->user->discord_id,
            'conjurer_role' => $roles[$player->id] ?? null,
        ];
    })->all();
}

it('builds random teams without double support conjurers', function () {
    $ignisSupportA = makeConceptQueuePlayer('ign-sup-a', 'ignis', 'conjurer', 1000);
    $ignisSupportB = makeConceptQueuePlayer('ign-sup-b', 'ignis', 'conjurer', 1002);
    $ignisKnight = makeConceptQueuePlayer('ign-kni', 'ignis', 'knight', 1001);
    $ignisHunter = makeConceptQueuePlayer('ign-hun', 'ignis', 'hunter', 1003);

    $alsiusKnight = makeConceptQueuePlayer('als-kni', 'alsius', 'knight', 1000);
    $alsiusHunter = makeConceptQueuePlayer('als-hun', 'alsius', 'hunter', 1002);

    queueConceptPlayer($ignisSupportA, 'support');
    queueConceptPlayer($ignisSupportB, 'support');
    queueConceptPlayer($ignisKnight);
    queueConceptPlayer($ignisHunter);

    queueConceptPlayer($alsiusKnight);
    queueConceptPlayer($alsiusHunter);

    $created = app(ArenaMatchmakingService::class)->processRandomQueue();

    expect($created)->toBe(1);

    $match = ArenaMatch::query()->firstOrFail();
    $ignisTeam = collect($match->getTeamByRealm('ignis'));

    expect(
        $ignisTeam->filter(fn (array $player) => ($player['subclass'] ?? null) === 'conjurer' && ($player['conjurer_role'] ?? null) === 'support')->count()
    )->toBeLessThanOrEqual(1);
});

it('prefers a fresh rival over an exact recent rematch', function () {
    $ignisPlayers = [
        makeConceptQueuePlayer('ign-a', 'ignis', 'knight', 1000),
        makeConceptQueuePlayer('ign-b', 'ignis', 'hunter', 1002),
    ];

    $alsiusPlayers = [
        makeConceptQueuePlayer('als-a', 'alsius', 'knight', 1001),
        makeConceptQueuePlayer('als-b', 'alsius', 'hunter', 1003),
    ];

    $syrtisPlayers = [
        makeConceptQueuePlayer('syr-a', 'syrtis', 'knight', 1006),
        makeConceptQueuePlayer('syr-b', 'syrtis', 'hunter', 1008),
    ];

    ArenaMatch::create([
        'match_code' => 'ARENA-7001',
        'report_token' => 'REMATCH001',
        'queue_mode' => 'random',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => makeConceptTeamPayload($ignisPlayers),
        'team_b' => makeConceptTeamPayload($alsiusPlayers),
        'zone' => 'frozen_bridge',
        'status' => 'completed',
        'winner_team' => 'team_a',
        'winner_realm' => 'ignis',
        'completed_at' => now()->subHour(),
    ]);

    foreach ($ignisPlayers as $player) {
        queueConceptPlayer($player);
    }

    foreach ($alsiusPlayers as $player) {
        queueConceptPlayer($player);
    }

    foreach ($syrtisPlayers as $player) {
        queueConceptPlayer($player);
    }

    $created = app(ArenaMatchmakingService::class)->processRandomQueue();

    expect($created)->toBe(1);

    $newMatch = ArenaMatch::query()
        ->where('match_code', '!=', 'ARENA-7001')
        ->firstOrFail();

    $realms = [$newMatch->team_a_realm, $newMatch->team_b_realm];
    sort($realms);

    // La propiedad bajo prueba es que NO se repita el cruce recien jugado
    // (ignis vs alsius). Cual de los otros dos cruces se elige depende del MMR
    // promedio de cada equipo, no de esta regla: con estos valores gana
    // alsius vs syrtis (diferencia 5) sobre ignis vs syrtis (diferencia 6).
    // Exigir un reino concreto ataria el test a ese detalle en vez de a la regla.
    expect($realms)->not->toBe(['alsius', 'ignis']);

    // Y syrtis, que era el unico reino sin historial reciente, entra si o si.
    expect($realms)->toContain('syrtis');
});

it('avoids reusing an active zone while free zones remain', function () {
    $activeIgnis = [
        makeConceptQueuePlayer('active-ign-a', 'ignis', 'knight', 900),
        makeConceptQueuePlayer('active-ign-b', 'ignis', 'hunter', 901),
    ];

    $activeAlsius = [
        makeConceptQueuePlayer('active-als-a', 'alsius', 'knight', 903),
        makeConceptQueuePlayer('active-als-b', 'alsius', 'hunter', 904),
    ];

    ArenaMatch::create([
        'match_code' => 'ARENA-7002',
        'report_token' => 'ZONEFREE01',
        'queue_mode' => 'random',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => makeConceptTeamPayload($activeIgnis),
        'team_b' => makeConceptTeamPayload($activeAlsius),
        'zone' => 'central_ruins',
        'status' => 'in_progress',
        'started_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(20),
    ]);

    $newIgnis = [
        makeConceptQueuePlayer('new-ign-a', 'ignis', 'knight', 1000),
        makeConceptQueuePlayer('new-ign-b', 'ignis', 'hunter', 1001),
    ];

    $newSyrtis = [
        makeConceptQueuePlayer('new-syr-a', 'syrtis', 'knight', 1000),
        makeConceptQueuePlayer('new-syr-b', 'syrtis', 'hunter', 1001),
    ];

    foreach ($newIgnis as $player) {
        queueConceptPlayer($player);
    }

    foreach ($newSyrtis as $player) {
        queueConceptPlayer($player);
    }

    app(ArenaMatchmakingService::class)->processRandomQueue();

    $newMatch = ArenaMatch::query()
        ->where('match_code', '!=', 'ARENA-7002')
        ->firstOrFail();

    expect($newMatch->zone)->not->toBe('central_ruins');
    expect($newMatch->zone_name)->toStartWith('Zona ');
});

it('renders numbered zone labels from the canonical zone key', function () {
    $match = new ArenaMatch(['zone' => 'crimson_canyon']);

    expect($match->zone_key)->toBe('crimson_canyon');
    expect($match->zone_number)->toBe(6);
    expect($match->zone_name)->toBe('Zona 6 - Crimson Canyon');
});
