<?php

use App\Models\Player;
use App\Models\User;
use App\Services\LadderCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeUser(string $suffix, bool $isAdmin = false): User
{
    return User::create([
        'discord_id' => 'test-user-' . $suffix,
        'discord_username' => 'tester_' . $suffix,
        'name' => 'Tester ' . $suffix,
        'email' => $suffix . '@example.com',
        'is_admin' => $isAdmin,
    ]);
}

function makePlayerForRanking(
    string $suffix,
    float $pl,
    int $mmr,
    int $wins,
    bool $isActive = true
): Player {
    return Player::create([
        'user_id' => makeUser($suffix)->id,
        'character_name' => 'Player ' . $suffix,
        'subclass' => 'knight',
        'realm' => 'ignis',
        'pl_points' => $pl,
        'mmr' => $mmr,
        'matches_played' => max($wins, 1),
        'wins' => $wins,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => $isActive,
    ]);
}

it('calculates ranking position using the public ladder tie breakers', function () {
    makePlayerForRanking('top-pl', 21.0, 830, 6);
    makePlayerForRanking('top-mmr', 18.0, 920, 5);
    makePlayerForRanking('top-wins', 18.0, 880, 9);
    $subject = makePlayerForRanking('subject', 18.0, 880, 4);
    makePlayerForRanking('inactive-ignored', 99.0, 999, 20, false);

    expect($subject->fresh()->ranking_position)->toBe(4);
});

it('uses the same stable tie breakers for top by realm and general ladder', function () {
    $first = makePlayerForRanking('first', 0.0, 1000, 0);
    $second = makePlayerForRanking('second', 0.0, 1000, 0);
    $third = makePlayerForRanking('third', 0.0, 1000, 0);

    $generalOrder = Player::query()
        ->where('realm', 'ignis')
        ->where('is_active', true)
        ->orderByPublicLadder()
        ->pluck('id')
        ->all();

    $topByRealm = app(LadderCacheService::class)
        ->getTopByRealm()
        ->get('ignis')
        ->pluck('id')
        ->all();

    expect($generalOrder)->toBe([$first->id, $second->id, $third->id]);
    expect($topByRealm)->toBe([$first->id, $second->id, $third->id]);
});
