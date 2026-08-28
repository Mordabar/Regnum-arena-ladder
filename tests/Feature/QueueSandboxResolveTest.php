<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSandboxBot(string $suffix, string $realm, string $subclass): Player
{
    $user = User::create([
        'discord_id' => 'queue-lab-' . $suffix,
        'discord_username' => 'queue_lab_' . $suffix,
        'name' => 'Queue Lab ' . $suffix,
        'email' => $suffix . '@queue-lab.test',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Bot ' . $suffix,
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

function sandboxPayload(Player ...$players): array
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

it('resolves sandbox matches using the rival side when a report already exists', function () {
    $admin = User::create([
        'discord_id' => 'admin-sandbox',
        'discord_username' => 'admin_sandbox',
        'name' => 'Admin Sandbox',
        'email' => 'admin-sandbox@example.com',
        'is_admin' => true,
    ]);

    $teamA1 = makeSandboxBot('ign-a1', 'ignis', 'knight');
    $teamA2 = makeSandboxBot('ign-a2', 'ignis', 'hunter');
    $teamB1 = makeSandboxBot('als-b1', 'alsius', 'knight');
    $teamB2 = makeSandboxBot('als-b2', 'alsius', 'hunter');

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-SBX',
        'report_token' => 'SANDBOXRP1',
        'queue_mode' => 'random',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => sandboxPayload($teamA1, $teamA2),
        'team_b' => sandboxPayload($teamB1, $teamB2),
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'estimated_mmr_avg' => 1000,
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $report = MatchReport::create([
        'match_id' => $match->id,
        'reported_by_player_id' => $teamA1->id,
        'reporting_team' => 'team_a',
        'claimed_winner_team' => 'team_a',
        'claimed_winner_realm' => 'ignis',
        'status' => 'pending_confirmation',
        'encounter_screenshot_path' => 'match-reports/testing/arena-sbx/encounter.svg',
        'final_screenshot_path' => 'match-reports/testing/arena-sbx/final.svg',
        'evidence_paths' => [
            'match-reports/testing/arena-sbx/final.svg',
            'match-reports/testing/arena-sbx/encounter.svg',
        ],
    ]);

    $this->withSession([
            'arena_admin.authenticated' => true,
            'arena_admin.username' => 'admin',
            'arena_admin.display_name' => 'admin',
        ])
        ->actingAs($admin)
        ->post(route('admin.testing.resolve', $match), [
            'winner_team' => 'team_b',
        ])
        ->assertSessionHas('success');

    $report->refresh();
    $match->refresh();

    expect($report->status)->toBe('confirmed');
    expect($report->confirmed_by_player_id)->toBeIn([$teamB1->id, $teamB2->id]);
    expect($match->status)->toBe('completed');
    expect($match->winner_team)->toBe('team_a');
});
