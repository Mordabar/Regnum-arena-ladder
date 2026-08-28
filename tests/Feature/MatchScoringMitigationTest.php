<?php

use App\Models\ArenaMatch;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\User;
use App\Services\ArenaMatchResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeMitigationUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'mitigation-user-' . $suffix,
        'discord_username' => 'mitigation_' . $suffix,
        'name' => 'Mitigation ' . $suffix,
        'email' => $suffix . '@example.com',
    ]);
}

function makeMitigationPlayer(string $suffix, string $realm): Player
{
    return Player::create([
        'user_id' => makeMitigationUser($suffix)->id,
        'character_name' => 'Mitigation ' . $suffix,
        'subclass' => 'knight',
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

function mitigationPayload(array $players): array
{
    return collect($players)->map(fn (Player $player) => [
        'player_id' => $player->id,
        'character_name' => $player->character_name,
        'subclass' => $player->subclass,
        'realm' => $player->realm,
        'discord_id' => $player->user->discord_id,
        'conjurer_role' => null,
    ])->all();
}

function makeMitigationMatch(
    string $code,
    string $token,
    array $teamA,
    array $teamB,
    string $status,
    ?string $winnerTeam = null,
    ?string $winnerRealm = null
): ArenaMatch {
    return ArenaMatch::create([
        'match_code' => $code,
        'report_token' => $token,
        'queue_mode' => 'random',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => mitigationPayload($teamA),
        'team_b' => mitigationPayload($teamB),
        'zone' => 'central_ruins',
        'status' => $status,
        'winner_team' => $winnerTeam,
        'winner_realm' => $winnerRealm,
        'started_at' => $status === 'in_progress' ? now()->subMinutes(5) : null,
        'completed_at' => $status === 'completed' ? now()->subHour() : null,
        'expires_at' => now()->addMinutes(20),
    ]);
}

it('reduces PL on exact rematches within the anti-farm window', function () {
    $teamA = [
        makeMitigationPlayer('ign-a', 'ignis'),
        makeMitigationPlayer('ign-b', 'ignis'),
        makeMitigationPlayer('ign-c', 'ignis'),
    ];

    $teamB = [
        makeMitigationPlayer('als-a', 'alsius'),
        makeMitigationPlayer('als-b', 'alsius'),
        makeMitigationPlayer('als-c', 'alsius'),
    ];

    makeMitigationMatch('ARENA-8101', 'FARM000001', $teamA, $teamB, 'completed', 'team_a', 'ignis');

    $currentMatch = makeMitigationMatch('ARENA-8102', 'FARM000002', $teamA, $teamB, 'in_progress');

    app(ArenaMatchResultService::class)->forceComplete($currentMatch, 'team_a');

    $winnerAverage = round(
        MatchResult::query()
            ->where('match_id', $currentMatch->id)
            ->whereIn('player_id', collect($teamA)->pluck('id'))
            ->avg('pl_change'),
        1
    );

    $loserAverage = round(
        MatchResult::query()
            ->where('match_id', $currentMatch->id)
            ->whereIn('player_id', collect($teamB)->pluck('id'))
            ->avg('pl_change'),
        1
    );

    expect($winnerAverage)->toBe(2.4);
    expect($loserAverage)->toBe(-1.6);
});
