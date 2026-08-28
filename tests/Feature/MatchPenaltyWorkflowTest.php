<?php

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makePenaltyUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'penalty-user-' . $suffix,
        'discord_username' => 'penalty_' . $suffix,
        'name' => 'Penalty ' . $suffix,
        'email' => $suffix . '@example.com',
    ]);
}

function makePenaltyPlayer(string $suffix, string $realm, string $subclass = 'barbarian'): Player
{
    $user = makePenaltyUser($suffix);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Penalty ' . $suffix,
        'subclass' => $subclass,
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 800,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'trust_score' => 100,
        'penalty_strikes' => 0,
        'is_active' => true,
    ]);
}

function makePenaltyMatch(): ArenaMatch
{
    $teamAPlayers = [
        makePenaltyPlayer('a1', 'ignis', 'barbarian'),
        makePenaltyPlayer('a2', 'ignis', 'conjurer'),
    ];

    $teamBPlayers = [
        makePenaltyPlayer('b1', 'alsius', 'barbarian'),
        makePenaltyPlayer('b2', 'alsius', 'hunter'),
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-7777',
        'report_token' => 'PENALTY777',
        'queue_mode' => 'random',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => collect($teamAPlayers)->map(fn (Player $player) => [
            'player_id' => $player->id,
            'character_name' => $player->character_name,
            'subclass' => $player->subclass,
            'realm' => $player->realm,
            'discord_id' => $player->user->discord_id,
            'conjurer_role' => $player->subclass === 'conjurer' ? 'support' : null,
        ])->all(),
        'team_b' => collect($teamBPlayers)->map(fn (Player $player) => [
            'player_id' => $player->id,
            'character_name' => $player->character_name,
            'subclass' => $player->subclass,
            'realm' => $player->realm,
            'discord_id' => $player->user->discord_id,
            'conjurer_role' => null,
        ])->all(),
        'zone' => 'central_ruins',
        'status' => 'in_progress',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    foreach (collect($teamAPlayers)->merge($teamBPlayers) as $index => $player) {
        Queue::create([
            'player_id' => $player->id,
            'queue_type' => 'random',
            'status' => 'accepted',
            'estimated_mmr' => 800,
            'joined_at' => now()->subMinutes(3),
            'matched_at' => now()->subMinutes(2),
            'expires_at' => now()->addMinutes(27),
            'team_id' => $index < 2 ? 'team-a' : 'team-b',
            'match_id' => (string) $match->id,
        ]);
    }

    return $match;
}

it('applies abandonment walkover with strike, trust penalty and lock', function () {
    Storage::fake('arena_reports');
    AppSetting::setValue('abandonment_lock_hours', 12, 'runtime', 'integer', false);
    AppSetting::setValue('abandonment_trust_penalty', 15, 'runtime', 'integer', false);
    AppSetting::setValue('penalty_max_lock_hours', 96, 'runtime', 'integer', false);

    $match = makePenaltyMatch();
    $offender = Player::findOrFail($match->getTeamPlayerIds('team_a')[0]);

    app(ArenaMatchResultService::class)->applyAbandonmentWalkover($match, $offender->id, null, 'Left the hunt');

    $offender->refresh();
    $match->refresh();

    expect($offender->penalty_strikes)->toBe(1);
    expect($offender->queue_lock_reason)->toBe('abandonment');
    expect($offender->trust_score)->toBe(85);
    expect($offender->queue_locked_until)->not->toBeNull();
    expect($offender->queue_locked_until->between(now()->addHours(11), now()->addHours(12)->addSeconds(5)))->toBeTrue();
    expect($match->status)->toBe('completed');
    expect($match->winner_team)->toBe('team_b');
    expect($match->results()->count())->toBe(4);
});

it('escalates abandonment locks based on accumulated strikes', function () {
    AppSetting::setValue('abandonment_lock_hours', 12, 'runtime', 'integer', false);
    AppSetting::setValue('abandonment_trust_penalty', 15, 'runtime', 'integer', false);
    AppSetting::setValue('penalty_max_lock_hours', 96, 'runtime', 'integer', false);

    $player = makePenaltyPlayer('repeat', 'syrtis');
    $player->update([
        'penalty_strikes' => 1,
        'trust_score' => 90,
    ]);

    app(ArenaMatchResultService::class)->applyAbandonmentPenalty($player);

    $player->refresh();

    expect($player->penalty_strikes)->toBe(2);
    expect($player->trust_score)->toBe(75);
    expect($player->queue_lock_reason)->toBe('abandonment');
    expect($player->queue_locked_until)->not->toBeNull();
    expect($player->queue_locked_until->between(now()->addHours(23), now()->addHours(24)->addSeconds(5)))->toBeTrue();
});

it('applies a heavier support infraction penalty and awards the rival team', function () {
    Storage::fake('arena_reports');
    AppSetting::setValue('support_infraction_lock_hours', 24, 'runtime', 'integer', false);
    AppSetting::setValue('support_infraction_trust_penalty', 25, 'runtime', 'integer', false);
    AppSetting::setValue('penalty_max_lock_hours', 96, 'runtime', 'integer', false);

    $match = makePenaltyMatch();
    $offender = Player::findOrFail($match->getTeamPlayerIds('team_a')[1]);

    app(ArenaMatchResultService::class)->applySupportInfraction($match, $offender->id, null, 'Double support evidence');

    $offender->refresh();
    $match->refresh();

    expect($offender->penalty_strikes)->toBe(1);
    expect($offender->queue_lock_reason)->toBe('support_infraction');
    expect($offender->trust_score)->toBe(75);
    expect($offender->queue_locked_until)->not->toBeNull();
    expect($offender->queue_locked_until->between(now()->addHours(23), now()->addHours(24)->addSeconds(5)))->toBeTrue();
    expect($match->status)->toBe('completed');
    expect($match->winner_team)->toBe('team_b');
});
