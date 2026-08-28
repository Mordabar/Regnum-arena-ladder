<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeEvidenceUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'evidence-' . $suffix,
        'discord_username' => 'evidence_' . $suffix,
        'name' => 'Evidence ' . $suffix,
        'email' => $suffix . '@example.com',
    ]);
}

function makeEvidencePlayer(string $suffix, string $realm, string $subclass): Player
{
    return Player::create([
        'user_id' => makeEvidenceUser($suffix)->id,
        'character_name' => 'Evidence ' . $suffix,
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

function makeEvidencePayload(Player ...$players): array
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

it('stores report evidence on the dedicated disk and serves it to match participants', function () {
    Storage::fake('arena_reports');

    $reporter = makeEvidencePlayer('rep', 'ignis', 'knight');
    $allyA = makeEvidencePlayer('ally-a', 'ignis', 'hunter');
    $enemyA = makeEvidencePlayer('enemy-a', 'alsius', 'knight');
    $enemyB = makeEvidencePlayer('enemy-b', 'alsius', 'hunter');

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-TEST',
        'report_token' => 'EVIDENCET1',
        'queue_mode' => 'random',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => makeEvidencePayload($reporter, $allyA),
        'team_b' => makeEvidencePayload($enemyA, $enemyB),
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'estimated_mmr_avg' => 1000,
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $response = $this
        ->actingAs($reporter->user)
        ->post(route('matches.report'), [
            'match_id' => $match->id,
            'player_id' => $reporter->id,
            'claimed_winner_team' => 'team_a',
            'evidence_files' => [
                UploadedFile::fake()->image('combat-final-1.png', 1280, 720),
                UploadedFile::fake()->image('combat-final-2.png', 1280, 720),
            ],
            'reporter_note' => 'Testing evidence upload',
        ]);

    $response
        ->assertRedirect(route('matches.show', $match))
        ->assertSessionHas('success');

    $report = MatchReport::firstOrFail();

    expect($report->final_screenshot_path)->not->toBeNull();
    expect($report->evidence_paths)->toHaveCount(2);
    expect($report->evidenceItems())->toHaveCount(2);

    Storage::disk('arena_reports')->assertExists($report->final_screenshot_path);
    Storage::disk('arena_reports')->assertExists($report->evidence_paths[1]);

    $this->actingAs($enemyA->user)
        ->get($report->evidenceUrl('evidence-1'))
        ->assertOk();
});

it('blocks evidence access for users outside the match', function () {
    Storage::fake('arena_reports');

    $reporter = makeEvidencePlayer('rep-2', 'ignis', 'knight');
    $enemy = makeEvidencePlayer('enemy-2', 'alsius', 'hunter');
    $outsider = makeEvidencePlayer('outsider', 'syrtis', 'warlock');

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-TEST-2',
        'report_token' => 'EVIDENCET2',
        'queue_mode' => 'random',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => makeEvidencePayload($reporter),
        'team_b' => makeEvidencePayload($enemy),
        'zone' => 'emerald_pass',
        'status' => 'in_progress',
        'estimated_mmr_avg' => 1000,
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $report = MatchReport::create([
        'match_id' => $match->id,
        'reported_by_player_id' => $reporter->id,
        'reporting_team' => 'team_a',
        'claimed_winner_team' => 'team_a',
        'claimed_winner_realm' => 'ignis',
        'status' => 'pending_confirmation',
        'final_screenshot_path' => 'match-reports/testing/arena-test-2/final.png',
        'encounter_screenshot_path' => 'match-reports/testing/arena-test-2/final.png',
        'evidence_paths' => [
            'match-reports/testing/arena-test-2/final.png',
            'match-reports/testing/arena-test-2/supporting.png',
        ],
    ]);

    Storage::disk('arena_reports')->put($report->final_screenshot_path, 'final');
    Storage::disk('arena_reports')->put('match-reports/testing/arena-test-2/supporting.png', 'supporting');

    $this->actingAs($outsider->user)
        ->get($report->evidenceUrl('evidence-1'))
        ->assertForbidden();
});
