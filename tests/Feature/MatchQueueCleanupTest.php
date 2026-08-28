<?php

use App\Models\ArenaMatch;
use App\Models\AppSetting;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeLifecycleUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'lifecycle-user-' . $suffix,
        'discord_username' => 'lifecycle_' . $suffix,
        'name' => 'Lifecycle ' . $suffix,
        'email' => $suffix . '@example.com',
    ]);
}

function makeLifecyclePlayer(string $suffix, string $realm): Player
{
    $user = makeLifecycleUser($suffix);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Lifecycle ' . $suffix,
        'subclass' => 'barbarian',
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 800,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function makeActiveMatchWithAcceptedQueues(): ArenaMatch
{
    $teamAPlayers = [
        makeLifecyclePlayer('a1', 'ignis'),
        makeLifecyclePlayer('a2', 'ignis'),
    ];

    $teamBPlayers = [
        makeLifecyclePlayer('b1', 'syrtis'),
        makeLifecyclePlayer('b2', 'syrtis'),
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-9001',
        'report_token' => 'REPORT9001',
        'queue_mode' => 'random',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'syrtis',
        'team_a' => collect($teamAPlayers)->map(fn (Player $player) => [
            'player_id' => $player->id,
            'character_name' => $player->character_name,
            'subclass' => $player->subclass,
            'realm' => $player->realm,
            'discord_id' => $player->user->discord_id,
            'conjurer_role' => null,
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
            'joined_at' => now()->subMinutes(5),
            'matched_at' => now()->subMinutes(4),
            'expires_at' => now()->addMinutes(25),
            'team_id' => $index < 2 ? 'team-a' : 'team-b',
            'match_id' => (string) $match->id,
        ]);
    }

    return $match;
}

it('closes active queue rows when a match is sent to dispute', function () {
    $match = makeActiveMatchWithAcceptedQueues();

    app(ArenaMatchResultService::class)->markDisputed($match, null, 'Regression test');

    expect(
        Queue::query()
            ->where('match_id', (string) $match->id)
            ->whereIn('status', ['waiting', 'matched', 'accepted'])
            ->count()
    )->toBe(0);

    expect(
        Queue::query()
            ->where('match_id', (string) $match->id)
            ->pluck('status')
            ->unique()
            ->all()
    )->toBe(['cancelled']);
});

it('closes active queue rows when a match is force completed', function () {
    Storage::fake('arena_reports');

    $match = makeActiveMatchWithAcceptedQueues();

    app(ArenaMatchResultService::class)->forceComplete($match, 'team_a');

    expect($match->fresh()->status)->toBe('completed');

    expect(
        Queue::query()
            ->where('match_id', (string) $match->id)
            ->whereIn('status', ['waiting', 'matched', 'accepted'])
            ->count()
    )->toBe(0);
});

it('sets a confirmation deadline when a player report is submitted', function () {
    Storage::fake('arena_reports');
    AppSetting::setValue('report_confirmation_window_minutes', 12, 'runtime', 'integer', false);

    $match = makeActiveMatchWithAcceptedQueues();
    $reporter = Player::findOrFail($match->getTeamPlayerIds('team_a')[0]);

    app(ArenaMatchResultService::class)->submitReport($match, $reporter, [
        'claimed_winner_team' => 'team_a',
        'evidence_files' => [
            UploadedFile::fake()->image('final-1.png'),
            UploadedFile::fake()->image('final-2.png'),
        ],
        'reporter_note' => 'Lifecycle report',
    ]);

    $match->refresh()->load('report');

    expect($match->report)->not->toBeNull();
    expect($match->report->status)->toBe('pending_confirmation');
    expect($match->report->evidence_paths)->toHaveCount(2);
    expect($match->expires_at)->not->toBeNull();
    expect($match->expires_at->between(now()->addMinutes(11), now()->addMinutes(12)->addSeconds(5)))->toBeTrue();
});

it('moves expired in progress matches without report to dispute', function () {
    $match = makeActiveMatchWithAcceptedQueues();
    $match->update([
        'expires_at' => now()->subMinute(),
    ]);

    $result = app(ArenaMatchResultService::class)->sweepPostMatchState();

    expect($result['expired_hunts'])->toBe(1);
    expect($match->fresh()->status)->toBe('disputed');
    expect($match->fresh()->expires_at)->toBeNull();
});

it('moves expired pending confirmations to dispute', function () {
    Storage::fake('arena_reports');

    $match = makeActiveMatchWithAcceptedQueues();
    $reporter = Player::findOrFail($match->getTeamPlayerIds('team_a')[0]);

    app(ArenaMatchResultService::class)->submitSyntheticReport($match, $reporter, 'team_a', 'Lifecycle synthetic report');

    $match->refresh()->load('report');
    $match->update([
        'expires_at' => now()->subMinute(),
    ]);

    $result = app(ArenaMatchResultService::class)->sweepPostMatchState();

    expect($result['expired_report_confirmations'])->toBe(1);
    expect($match->fresh()->status)->toBe('disputed');
    expect($match->fresh()->report->status)->toBe('disputed');
    expect($match->fresh()->expires_at)->toBeNull();
});
