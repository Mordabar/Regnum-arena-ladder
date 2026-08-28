<?php

use App\Models\Player;
use App\Models\User;
use App\Services\LadderScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeScoringUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'scoring-user-' . $suffix,
        'discord_username' => 'scoring_' . $suffix,
        'name' => 'Scoring ' . $suffix,
        'email' => $suffix . '@example.com',
    ]);
}

function makeScoringPlayer(
    string $suffix,
    string $realm,
    int $mmr,
    float $pl = 0
): Player {
    return Player::create([
        'user_id' => makeScoringUser($suffix)->id,
        'character_name' => 'Scoring ' . $suffix,
        'subclass' => 'knight',
        'realm' => $realm,
        'pl_points' => $pl,
        'mmr' => $mmr,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

it('uses the concept baseline of +3 and -2 in balanced matches', function () {
    $winners = collect([
        makeScoringPlayer('bal-win-a', 'ignis', 1000),
        makeScoringPlayer('bal-win-b', 'ignis', 1000),
        makeScoringPlayer('bal-win-c', 'ignis', 1000),
    ]);

    $losers = collect([
        makeScoringPlayer('bal-los-a', 'alsius', 1000),
        makeScoringPlayer('bal-los-b', 'alsius', 1000),
        makeScoringPlayer('bal-los-c', 'alsius', 1000),
    ]);

    $result = app(LadderScoringService::class)->calculateMatchResult(
        $winners->pluck('id')->all(),
        $losers->pluck('id')->all()
    );

    expect($result['category'])->toBe('parejo');
    expect($result['pl_win'])->toBe(3.0);
    expect($result['pl_loss'])->toBe(-2.0);
});

it('rewards clear underdog wins above the +3 baseline and punishes favorites harder', function () {
    $winners = collect([
        makeScoringPlayer('dog-win-a', 'ignis', 850),
        makeScoringPlayer('dog-win-b', 'ignis', 850),
        makeScoringPlayer('dog-win-c', 'ignis', 850),
    ]);

    $losers = collect([
        makeScoringPlayer('dog-los-a', 'alsius', 1200),
        makeScoringPlayer('dog-los-b', 'alsius', 1200),
        makeScoringPlayer('dog-los-c', 'alsius', 1200),
    ]);

    $result = app(LadderScoringService::class)->calculateMatchResult(
        $winners->pluck('id')->all(),
        $losers->pluck('id')->all()
    );

    expect($result['category'])->toBe('gran_underdog');
    expect($result['pl_win'])->toBeGreaterThan(5.1);
    expect($result['pl_win'])->toBeLessThanOrEqual(8.0);
    expect($result['pl_loss'])->toBeLessThan(-6.1);
    expect($result['pl_loss'])->toBeGreaterThanOrEqual(-10.0);
});

it('softens PL losses for true underdogs and trims gains for heavy favorites', function () {
    $winners = collect([
        makeScoringPlayer('fav-win-a', 'syrtis', 1200),
        makeScoringPlayer('fav-win-b', 'syrtis', 1200),
        makeScoringPlayer('fav-win-c', 'syrtis', 1200),
    ]);

    $losers = collect([
        makeScoringPlayer('fav-los-a', 'ignis', 850),
        makeScoringPlayer('fav-los-b', 'ignis', 850),
        makeScoringPlayer('fav-los-c', 'ignis', 850),
    ]);

    $result = app(LadderScoringService::class)->calculateMatchResult(
        $winners->pluck('id')->all(),
        $losers->pluck('id')->all()
    );

    expect($result['category'])->toBe('gran_favorito');
    expect($result['pl_win'])->toBeGreaterThanOrEqual(0.5);
    expect($result['pl_win'])->toBeLessThan(2.0);
    expect($result['pl_loss'])->toBeLessThanOrEqual(-0.5);
    expect($result['pl_loss'])->toBeGreaterThanOrEqual(-0.9);
});

it('respects the +8 and -10 caps on extreme rating gaps', function () {
    $winners = collect([
        makeScoringPlayer('cap-win-a', 'ignis', 300),
        makeScoringPlayer('cap-win-b', 'ignis', 300),
        makeScoringPlayer('cap-win-c', 'ignis', 300),
    ]);

    $losers = collect([
        makeScoringPlayer('cap-los-a', 'alsius', 2200),
        makeScoringPlayer('cap-los-b', 'alsius', 2200),
        makeScoringPlayer('cap-los-c', 'alsius', 2200),
    ]);

    $result = app(LadderScoringService::class)->calculateMatchResult(
        $winners->pluck('id')->all(),
        $losers->pluck('id')->all()
    );

    expect($result['pl_win'])->toBe(8.0);
    expect($result['pl_loss'])->toBe(-10.0);
});
