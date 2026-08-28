<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function asArenaAdminSessionForDeletion(): array
{
    return [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];
}

function makeAdminUserForDeletion(): User
{
    return User::create([
        'discord_id' => 'admin-user-deletion',
        'discord_username' => 'admin',
        'name' => 'Admin User',
        'email' => 'admin-deletion@example.com',
        'is_admin' => true,
    ]);
}

function makeDeletionPlayer(array $userOverrides = [], array $playerOverrides = []): Player
{
    $userIndex = User::query()->count() + 1;
    $playerIndex = Player::query()->count() + 1;

    $user = User::create(array_merge([
        'discord_id' => 'user-' . $userIndex,
        'discord_username' => 'user' . $userIndex,
        'name' => 'User ' . $userIndex,
        'email' => 'user' . $userIndex . '@example.com',
    ], $userOverrides));

    return Player::create(array_merge([
        'user_id' => $user->id,
        'character_name' => 'Player ' . $playerIndex,
        'subclass' => 'hunter',
        'realm' => 'syrtis',
        'pl_points' => 0,
        'mmr' => 1000,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ], $playerOverrides));
}

function makeCompletedMatch(array $teamAPlayers, array $teamBPlayers, array $overrides = []): ArenaMatch
{
    $teamA = collect($teamAPlayers)->map(fn (Player $player) => [
        'player_id' => $player->id,
        'user_id' => $player->user_id,
        'character_name' => $player->character_name,
        'subclass' => $player->subclass,
        'realm' => $player->realm,
        'discord_id' => (string) ($player->user?->discord_id ?? ''),
    ])->values()->all();

    $teamB = collect($teamBPlayers)->map(fn (Player $player) => [
        'player_id' => $player->id,
        'user_id' => $player->user_id,
        'character_name' => $player->character_name,
        'subclass' => $player->subclass,
        'realm' => $player->realm,
        'discord_id' => (string) ($player->user?->discord_id ?? ''),
    ])->values()->all();

    return ArenaMatch::create(array_merge([
        'match_code' => 'ARENA-' . str_pad((string) (ArenaMatch::query()->count() + 1001), 4, '0', STR_PAD_LEFT),
        'report_token' => 'TOKEN' . str_pad((string) (ArenaMatch::query()->count() + 1), 6, '0', STR_PAD_LEFT),
        'queue_mode' => 'random',
        'team_a_queue_type' => 'random',
        'team_b_queue_type' => 'random',
        'team_a_realm' => $teamAPlayers[0]->realm,
        'team_b_realm' => $teamBPlayers[0]->realm,
        'team_a' => $teamA,
        'team_b' => $teamB,
        'zone' => 'emerald_pass',
        'status' => 'completed',
        'winner_team' => 'team_a',
        'winner_realm' => $teamAPlayers[0]->realm,
        'estimated_mmr_avg' => 1000,
        'completed_at' => now(),
        'reported_at' => now(),
        'expires_at' => null,
        'notes' => 'Test match',
    ], $overrides));
}

it('allows admins to purge a player with ladder history and clean related ladder data', function () {
    $admin = makeAdminUserForDeletion();
    $target = makeDeletionPlayer(
        [
            'discord_id' => 'admin-managed-' . uniqid(),
            'discord_username' => 'managed-target',
            'email' => 'managed-target@example.com',
        ],
        [
            'character_name' => 'Managed Target',
            'pl_points' => 3.0,
            'mmr' => 1016,
            'matches_played' => 1,
            'wins' => 1,
        ]
    );
    $opponent = makeDeletionPlayer([], [
        'character_name' => 'Opponent Survivor',
        'realm' => 'ignis',
        'pl_points' => 0,
        'mmr' => 984,
        'matches_played' => 1,
        'losses' => 1,
    ]);

    $match = makeCompletedMatch([$target], [$opponent]);

    MatchReport::create([
        'match_id' => $match->id,
        'reported_by_player_id' => $target->id,
        'reporting_team' => 'team_a',
        'claimed_winner_team' => 'team_a',
        'claimed_winner_realm' => 'syrtis',
        'status' => 'confirmed',
        'encounter_screenshot_path' => 'testing/encounter.png',
        'final_screenshot_path' => 'testing/final.png',
        'evidence_paths' => ['testing/final.png', 'testing/encounter.png'],
        'reporter_note' => 'Victory',
        'confirmed_by_player_id' => $opponent->id,
        'confirmed_at' => now(),
    ]);

    MatchResult::create([
        'match_id' => $match->id,
        'player_id' => $target->id,
        'result' => 'win',
        'pl_change' => 3.0,
        'mmr_change' => 16,
        'pl_before' => 0,
        'pl_after' => 3.0,
        'mmr_before' => 1000,
        'mmr_after' => 1016,
        'reported_by_admin' => false,
        'created_at' => now(),
    ]);

    MatchResult::create([
        'match_id' => $match->id,
        'player_id' => $opponent->id,
        'result' => 'loss',
        'pl_change' => -2.0,
        'mmr_change' => -16,
        'pl_before' => 2.0,
        'pl_after' => 0,
        'mmr_before' => 1000,
        'mmr_after' => 984,
        'reported_by_admin' => false,
        'created_at' => now(),
    ]);

    $this->withSession(asArenaAdminSessionForDeletion())
        ->actingAs($admin)
        ->from(route('admin.players.index'))
        ->delete(route('admin.players.destroy', $target))
        ->assertRedirect(route('admin.players.index'))
        ->assertSessionHas('success');

    expect(Player::find($target->id))->toBeNull();
    expect(User::find($target->user_id))->toBeNull();
    expect(ArenaMatch::find($match->id))->toBeNull();
    expect(MatchReport::query()->where('match_id', $match->id)->exists())->toBeFalse();
    expect(MatchResult::query()->where('match_id', $match->id)->exists())->toBeFalse();

    $opponent->refresh();
    expect($opponent->matches_played)->toBe(0);
    expect($opponent->wins)->toBe(0);
    expect($opponent->losses)->toBe(0);
    expect((float) $opponent->pl_points)->toBe(0.0);
    expect($opponent->mmr)->toBe(1000);
});

it('rebuilds remaining player stats from surviving history after purging a player', function () {
    $admin = makeAdminUserForDeletion();
    $target = makeDeletionPlayer(
        [
            'discord_id' => 'admin-managed-' . uniqid(),
            'discord_username' => 'managed-delete-me',
            'email' => 'managed-delete-me@example.com',
        ],
        [
            'character_name' => 'Delete Me',
            'pl_points' => 3.0,
            'mmr' => 1016,
            'matches_played' => 1,
            'wins' => 1,
        ]
    );
    $survivor = makeDeletionPlayer([], [
        'character_name' => 'Survivor',
        'realm' => 'ignis',
        'pl_points' => 1.0,
        'mmr' => 1004,
        'matches_played' => 2,
        'wins' => 1,
        'losses' => 1,
    ]);
    $keepOpponent = makeDeletionPlayer([], [
        'character_name' => 'Keep Opponent',
        'realm' => 'alsius',
        'pl_points' => 0,
        'mmr' => 982,
        'matches_played' => 1,
        'losses' => 1,
    ]);

    $keepMatch = makeCompletedMatch([$survivor], [$keepOpponent], [
        'match_code' => 'ARENA-KEEP',
        'report_token' => 'KEEP000001',
        'completed_at' => now()->subHour(),
        'reported_at' => now()->subHour(),
    ]);

    MatchResult::create([
        'match_id' => $keepMatch->id,
        'player_id' => $survivor->id,
        'result' => 'win',
        'pl_change' => 3.0,
        'mmr_change' => 18,
        'pl_before' => 0,
        'pl_after' => 3.0,
        'mmr_before' => 1000,
        'mmr_after' => 1018,
        'reported_by_admin' => false,
        'created_at' => now()->subHour(),
    ]);

    MatchResult::create([
        'match_id' => $keepMatch->id,
        'player_id' => $keepOpponent->id,
        'result' => 'loss',
        'pl_change' => -2.0,
        'mmr_change' => -18,
        'pl_before' => 2.0,
        'pl_after' => 0,
        'mmr_before' => 1000,
        'mmr_after' => 982,
        'reported_by_admin' => false,
        'created_at' => now()->subHour(),
    ]);

    $deleteMatch = makeCompletedMatch([$target], [$survivor], [
        'match_code' => 'ARENA-DELETE',
        'report_token' => 'DELETE0001',
        'completed_at' => now(),
        'reported_at' => now(),
    ]);

    MatchResult::create([
        'match_id' => $deleteMatch->id,
        'player_id' => $target->id,
        'result' => 'win',
        'pl_change' => 3.0,
        'mmr_change' => 16,
        'pl_before' => 0,
        'pl_after' => 3.0,
        'mmr_before' => 1000,
        'mmr_after' => 1016,
        'reported_by_admin' => false,
        'created_at' => now(),
    ]);

    MatchResult::create([
        'match_id' => $deleteMatch->id,
        'player_id' => $survivor->id,
        'result' => 'loss',
        'pl_change' => -2.0,
        'mmr_change' => -14,
        'pl_before' => 3.0,
        'pl_after' => 1.0,
        'mmr_before' => 1018,
        'mmr_after' => 1004,
        'reported_by_admin' => false,
        'created_at' => now(),
    ]);

    $this->withSession(asArenaAdminSessionForDeletion())
        ->actingAs($admin)
        ->from(route('admin.players.index'))
        ->delete(route('admin.players.destroy', $target))
        ->assertRedirect(route('admin.players.index'))
        ->assertSessionHas('success');

    expect(ArenaMatch::find($deleteMatch->id))->toBeNull();
    expect(ArenaMatch::find($keepMatch->id))->not->toBeNull();

    $survivor->refresh();
    expect($survivor->matches_played)->toBe(1);
    expect($survivor->wins)->toBe(1);
    expect($survivor->losses)->toBe(0);
    expect((float) $survivor->pl_points)->toBe(3.0);
    expect($survivor->mmr)->toBe(1018);
});
