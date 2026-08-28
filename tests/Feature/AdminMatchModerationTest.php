<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function asArenaAdminSessionForModeration(): array
{
    return [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];
}

function makeModerationUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'moderation-user-' . $suffix,
        'discord_username' => 'moderation_' . $suffix,
        'name' => 'Moderation ' . $suffix,
        'email' => $suffix . '@example.com',
        'is_admin' => false,
    ]);
}

function makeModerationPlayer(string $suffix, string $realm, string $subclass): Player
{
    return Player::create([
        'user_id' => makeModerationUser($suffix)->id,
        'character_name' => 'Moderation ' . $suffix,
        'subclass' => $subclass,
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 1000,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function moderationPayload(Player ...$players): array
{
    return collect($players)->map(function (Player $player) {
        return [
            'player_id' => $player->id,
            'character_name' => $player->character_name,
            'subclass' => $player->subclass,
            'realm' => $player->realm,
            'discord_id' => $player->user->discord_id,
            'conjurer_role' => null,
        ];
    })->all();
}

it('lets admin correct the reported winner before resolving the match', function () {
    $admin = User::create([
        'discord_id' => 'admin-moderation',
        'discord_username' => 'admin_moderation',
        'name' => 'Admin Moderation',
        'email' => 'admin-moderation@example.com',
        'is_admin' => true,
    ]);

    $teamA1 = makeModerationPlayer('a1', 'ignis', 'knight');
    $teamA2 = makeModerationPlayer('a2', 'ignis', 'hunter');
    $teamB1 = makeModerationPlayer('b1', 'alsius', 'knight');
    $teamB2 = makeModerationPlayer('b2', 'alsius', 'hunter');

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-MOD',
        'report_token' => 'MODREPORT1',
        'queue_mode' => 'random',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => moderationPayload($teamA1, $teamA2),
        'team_b' => moderationPayload($teamB1, $teamB2),
        'zone' => 'frozen_bridge',
        'status' => 'disputed',
        'estimated_mmr_avg' => 1000,
        'started_at' => now()->subMinutes(12),
        'reported_at' => now()->subMinutes(3),
    ]);

    $report = MatchReport::create([
        'match_id' => $match->id,
        'reported_by_player_id' => $teamA1->id,
        'reporting_team' => 'team_a',
        'claimed_winner_team' => 'team_a',
        'claimed_winner_realm' => 'ignis',
        'status' => 'disputed',
        'encounter_screenshot_path' => 'match-reports/testing/arena-mod/final.png',
        'final_screenshot_path' => 'match-reports/testing/arena-mod/final.png',
        'evidence_paths' => ['match-reports/testing/arena-mod/final.png'],
        'reporter_note' => 'Original report said team A won.',
    ]);

    $this->withSession(asArenaAdminSessionForModeration())
        ->actingAs($admin)
        ->post(route('admin.matches.resolve', $match), [
            'action' => 'force_complete',
            'winner_team' => 'team_b',
            'note' => 'Admin review corrected the winner.',
        ])
        ->assertSessionHas('success');

    $match->refresh();
    $report->refresh();

    expect($match->status)->toBe('completed');
    expect($match->winner_team)->toBe('team_b');
    expect($match->winner_realm)->toBe('alsius');

    expect($report->status)->toBe('admin_resolved');
    expect($report->claimed_winner_team)->toBe('team_b');
    expect($report->claimed_winner_realm)->toBe('alsius');
    expect($report->admin_note)->toBe('Admin review corrected the winner.');
    expect(data_get($report->resolution_payload, 'winner_team'))->toBe('team_b');
    expect(data_get($report->resolution_payload, 'original_claimed_winner_team'))->toBe('team_a');
});

it('lets admin correct a match that was already completed and processed', function () {
    $admin = User::create([
        'discord_id' => 'admin-moderation-processed',
        'discord_username' => 'admin_moderation_processed',
        'name' => 'Admin Moderation Processed',
        'email' => 'admin-moderation-processed@example.com',
        'is_admin' => true,
    ]);

    $teamA1 = makeModerationPlayer('pa1', 'ignis', 'knight');
    $teamA2 = makeModerationPlayer('pa2', 'ignis', 'hunter');
    $teamB1 = makeModerationPlayer('pb1', 'alsius', 'knight');
    $teamB2 = makeModerationPlayer('pb2', 'alsius', 'hunter');

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-MOD-PROCESSED',
        'report_token' => 'MODREPORT2',
        'queue_mode' => 'random',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => moderationPayload($teamA1, $teamA2),
        'team_b' => moderationPayload($teamB1, $teamB2),
        'zone' => 'emerald_pass',
        'status' => 'completed',
        'winner_team' => 'team_a',
        'winner_realm' => 'ignis',
        'estimated_mmr_avg' => 1000,
        'started_at' => now()->subMinutes(30),
        'completed_at' => now()->subMinutes(20),
        'reported_at' => now()->subMinutes(24),
    ]);

    $report = MatchReport::create([
        'match_id' => $match->id,
        'reported_by_player_id' => $teamA1->id,
        'reporting_team' => 'team_a',
        'claimed_winner_team' => 'team_a',
        'claimed_winner_realm' => 'ignis',
        'status' => 'confirmed',
        'encounter_screenshot_path' => 'match-reports/testing/arena-mod-processed/final.png',
        'final_screenshot_path' => 'match-reports/testing/arena-mod-processed/final.png',
        'evidence_paths' => ['match-reports/testing/arena-mod-processed/final.png'],
        'reporter_note' => 'Originally team A was confirmed.',
        'confirmed_by_player_id' => $teamB1->id,
        'confirmed_at' => now()->subMinutes(22),
    ]);

    foreach ([
        [$teamA1, 'win', 3.0, 16, 0.0, 3.0, 1000, 1016],
        [$teamA2, 'win', 3.0, 16, 0.0, 3.0, 1000, 1016],
        [$teamB1, 'loss', -2.0, -16, 0.0, 0.0, 1000, 984],
        [$teamB2, 'loss', -2.0, -16, 0.0, 0.0, 1000, 984],
    ] as [$player, $result, $plChange, $mmrChange, $plBefore, $plAfter, $mmrBefore, $mmrAfter]) {
        $player->update([
            'pl_points' => $plAfter,
            'mmr' => $mmrAfter,
            'matches_played' => 1,
            'wins' => $result === 'win' ? 1 : 0,
            'losses' => $result === 'loss' ? 1 : 0,
        ]);

        \App\Models\MatchResult::create([
            'match_id' => $match->id,
            'player_id' => $player->id,
            'result' => $result,
            'pl_change' => $plChange,
            'mmr_change' => $mmrChange,
            'pl_before' => $plBefore,
            'pl_after' => $plAfter,
            'mmr_before' => $mmrBefore,
            'mmr_after' => $mmrAfter,
            'reported_by_admin' => false,
            'scoring_context' => ['resolution_source' => 'rival_confirmation'],
            'created_at' => now()->subMinutes(20),
        ]);
    }

    $this->withSession(asArenaAdminSessionForModeration())
        ->actingAs($admin)
        ->post(route('admin.matches.resolve', $match), [
            'action' => 'force_complete',
            'winner_team' => 'team_b',
            'note' => 'Processed match corrected by admin.',
        ])
        ->assertSessionHas('success');

    $match->refresh();
    $report->refresh();
    $teamA1->refresh();
    $teamB1->refresh();

    expect($match->status)->toBe('completed');
    expect($match->winner_team)->toBe('team_b');
    expect($match->winner_realm)->toBe('alsius');

    expect($report->status)->toBe('admin_resolved');
    expect($report->claimed_winner_team)->toBe('team_b');
    expect(data_get($report->resolution_payload, 'resolution_source'))->toBe('admin_force_complete_correction');
    expect(data_get($report->resolution_payload, 'original_claimed_winner_team'))->toBe('team_a');

    expect($teamA1->wins)->toBe(0);
    expect($teamA1->losses)->toBe(1);
    expect($teamA1->mmr)->toBeLessThan(1000);

    expect($teamB1->wins)->toBe(1);
    expect($teamB1->losses)->toBe(0);
    expect($teamB1->mmr)->toBeGreaterThan(1000);
});
