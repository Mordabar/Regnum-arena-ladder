<?php

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\MatchResult;
use App\Models\Party;
use App\Models\PartyMember;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makePremadeFlowUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'premade-user-' . $suffix,
        'discord_username' => 'premade_' . $suffix,
        'name' => 'Premade ' . $suffix,
        'email' => $suffix . '@example.com',
    ]);
}

function makePremadeFlowPlayer(
    string $suffix,
    string $realm,
    string $subclass,
    int $mmr = 1000,
    float $pl = 0
): Player {
    return Player::create([
        'user_id' => makePremadeFlowUser($suffix)->id,
        'character_name' => 'Premade ' . $suffix,
        'subclass' => $subclass,
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

function premadeFlowPayload(array $players, array $roles = []): array
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

function createReadyPremadeParty(Player $leader, Player $teammate, array $roles = []): Party
{
    test()->actingAs($leader->user)
        ->from(route('lobby'))
        ->post(route('party.create'), [
            'party_player_ids' => [$leader->id, $teammate->id],
            'party_conjurer_roles' => [
                $roles[$leader->id] ?? null,
                $roles[$teammate->id] ?? null,
            ],
        ])
        ->assertRedirect(route('lobby'));

    $party = Party::query()->latest('created_at')->firstOrFail();
    $member = PartyMember::query()
        ->where('party_id', $party->id)
        ->where('player_id', $teammate->id)
        ->firstOrFail();

    test()->actingAs($teammate->user)
        ->from(route('lobby'))
        ->post(route('party.accept', [$party, $member]))
        ->assertRedirect();

    return $party->fresh();
}

it('queues a premade party as one linked duo from the main queue flow', function () {
    $leader = makePremadeFlowPlayer('leader', 'ignis', 'conjurer', 1010);
    $teammate = makePremadeFlowPlayer('mate-a', 'ignis', 'knight', 1000);

    $party = createReadyPremadeParty($leader, $teammate, [
        $leader->id => 'offensive',
    ]);

    expect($party->status)->toBe('ready');

    $this->actingAs($leader->user)
        ->from(route('lobby'))
        ->post(route('party.enqueue', $party))
        ->assertRedirect(route('lobby'));

    $queues = Queue::query()
        ->where('queue_type', 'premade')
        ->orderBy('id')
        ->get();

    expect($queues)->toHaveCount(2);
    expect($queues->pluck('team_id')->filter()->unique())->toHaveCount(1);
    expect($queues->pluck('party_signature')->filter()->unique())->toHaveCount(1);
    expect($queues->pluck('status')->unique()->all())->toBe(['waiting']);
    expect($queues->firstWhere('player_id', $leader->id)?->conjurer_role)->toBe('offensive');
    expect($queues->every(fn (Queue $queue) => is_array($queue->team_composition) && count($queue->team_composition) === 2))->toBeTrue();
});

it('blocks an exact premade duo after 3 matches in the same day', function () {
    $leader = makePremadeFlowPlayer('limit-leader', 'syrtis', 'knight');
    $teammate = makePremadeFlowPlayer('limit-a', 'syrtis', 'hunter');
    $opponents = [
        makePremadeFlowPlayer('limit-op-a', 'alsius', 'knight'),
        makePremadeFlowPlayer('limit-op-b', 'alsius', 'hunter'),
    ];

    $partySignature = collect([$leader, $teammate])
        ->pluck('user_id')
        ->sort()
        ->implode('-');

    foreach ([1, 2, 3] as $index) {
        ArenaMatch::create([
            'match_code' => 'ARENA-9' . $index . $index . $index,
            'report_token' => 'TRIADLIM' . $index,
            'queue_mode' => 'premade',
            'team_a_queue_type' => 'premade',
            'team_b_queue_type' => 'random',
            'team_a_realm' => 'syrtis',
            'team_b_realm' => 'alsius',
            'team_a' => premadeFlowPayload([$leader, $teammate]),
            'team_b' => premadeFlowPayload($opponents),
            'team_a_party_signature' => $partySignature,
            'team_b_party_signature' => null,
            'zone' => 'frozen_bridge',
            'status' => 'completed',
            'winner_team' => 'team_a',
            'winner_realm' => 'syrtis',
            'completed_at' => now()->subMinutes($index),
        ]);
    }

    $this->actingAs($leader->user)
        ->from(route('lobby'))
        ->post(route('party.create'), [
            'party_player_ids' => [$leader->id, $teammate->id],
            'party_conjurer_roles' => [null, null],
        ])
        ->assertRedirect(route('lobby'))
        ->assertSessionHasErrors(['error']);

    expect(Queue::query()->where('queue_type', 'premade')->count())->toBe(0);
});

it('blocks inviting a player who already belongs to another active party', function () {
    $leaderA = makePremadeFlowPlayer('leader-a', 'ignis', 'knight', 1005);
    $sharedPlayer = makePremadeFlowPlayer('shared', 'ignis', 'hunter', 995);
    $leaderB = makePremadeFlowPlayer('leader-b', 'ignis', 'conjurer', 1000);

    $this->actingAs($leaderA->user)
        ->from(route('lobby'))
        ->post(route('party.create'), [
            'party_player_ids' => [$leaderA->id, $sharedPlayer->id],
            'party_conjurer_roles' => [null, null],
        ])
        ->assertRedirect(route('lobby'));

    expect(Party::query()->count())->toBe(1);

    $this->actingAs($leaderB->user)
        ->from(route('lobby'))
        ->post(route('party.create'), [
            'party_player_ids' => [$leaderB->id, $sharedPlayer->id],
            'party_conjurer_roles' => ['offensive', null],
        ])
        ->assertRedirect(route('lobby'))
        ->assertSessionHasErrors(['error']);

    expect(Party::query()->count())->toBe(1);
});

it('applies the random vs premade bonus to PL and MMR when the random side wins', function () {
    Storage::fake(MatchReport::EVIDENCE_DISK);

    AppSetting::setValue('random_vs_premade_pl_bonus_pct', 25, 'runtime', 'float');
    AppSetting::setValue('random_vs_premade_mmr_bonus_pct', 18, 'runtime', 'float');
    AppSetting::setValue('premade_vs_random_pl_win_penalty_pct', 20, 'runtime', 'float');
    AppSetting::setValue('premade_vs_random_mmr_win_penalty_pct', 14, 'runtime', 'float');

    $randomTeam = [
        makePremadeFlowPlayer('rvp-rand-a', 'ignis', 'knight', 1000),
        makePremadeFlowPlayer('rvp-rand-b', 'ignis', 'hunter', 1000),
    ];

    $premadeTeam = [
        makePremadeFlowPlayer('rvp-prem-a', 'alsius', 'knight', 1000),
        makePremadeFlowPlayer('rvp-prem-b', 'alsius', 'hunter', 1000),
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-8333',
        'report_token' => 'RVPBONUS01',
        'queue_mode' => 'random',
        'team_a_queue_type' => 'random',
        'team_b_queue_type' => 'premade',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => premadeFlowPayload($randomTeam),
        'team_b' => premadeFlowPayload($premadeTeam),
        'team_a_party_signature' => null,
        'team_b_party_signature' => collect($premadeTeam)->pluck('user_id')->sort()->implode('-'),
        'zone' => 'central_ruins',
        'status' => 'in_progress',
        'started_at' => now()->subMinutes(5),
        'expires_at' => now()->addMinutes(20),
    ]);

    app(ArenaMatchResultService::class)->forceComplete($match, 'team_a');

    $winnerResult = MatchResult::query()
        ->where('match_id', $match->id)
        ->where('player_id', $randomTeam[0]->id)
        ->firstOrFail();

    $loserResult = MatchResult::query()
        ->where('match_id', $match->id)
        ->where('player_id', $premadeTeam[0]->id)
        ->firstOrFail();

    expect($winnerResult->pl_change)->toBe(3.8);
    expect($winnerResult->mmr_change)->toBe(19);
    expect($winnerResult->scoring_context['player_queue_type'])->toBe('random');
    expect($winnerResult->scoring_context['opponent_queue_type'])->toBe('premade');
    expect($winnerResult->scoring_context['queue_type_multiplier_pl'])->toBe(1.25);
    expect($winnerResult->scoring_context['queue_type_multiplier_mmr'])->toBe(1.18);

    expect($loserResult->pl_change)->toBe(-2.5);
    expect($loserResult->mmr_change)->toBe(-19);
    expect($loserResult->scoring_context['player_queue_type'])->toBe('premade');
    expect($loserResult->scoring_context['opponent_queue_type'])->toBe('random');
});

it('softens the loss for random teams and trims the gain for premades in mixed matches', function () {
    Storage::fake(MatchReport::EVIDENCE_DISK);

    AppSetting::setValue('random_vs_premade_pl_bonus_pct', 25, 'runtime', 'float');
    AppSetting::setValue('random_vs_premade_mmr_bonus_pct', 18, 'runtime', 'float');
    AppSetting::setValue('premade_vs_random_pl_win_penalty_pct', 20, 'runtime', 'float');
    AppSetting::setValue('premade_vs_random_mmr_win_penalty_pct', 14, 'runtime', 'float');

    $randomTeam = [
        makePremadeFlowPlayer('rvp-loss-rand-a', 'ignis', 'knight', 1000),
        makePremadeFlowPlayer('rvp-loss-rand-b', 'ignis', 'hunter', 1000),
    ];

    $premadeTeam = [
        makePremadeFlowPlayer('rvp-loss-prem-a', 'alsius', 'knight', 1000),
        makePremadeFlowPlayer('rvp-loss-prem-b', 'alsius', 'hunter', 1000),
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-8444',
        'report_token' => 'RVPBONUS02',
        'queue_mode' => 'random',
        'team_a_queue_type' => 'random',
        'team_b_queue_type' => 'premade',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => premadeFlowPayload($randomTeam),
        'team_b' => premadeFlowPayload($premadeTeam),
        'team_a_party_signature' => null,
        'team_b_party_signature' => collect($premadeTeam)->pluck('user_id')->sort()->implode('-'),
        'zone' => 'emerald_pass',
        'status' => 'in_progress',
        'started_at' => now()->subMinutes(5),
        'expires_at' => now()->addMinutes(20),
    ]);

    app(ArenaMatchResultService::class)->forceComplete($match, 'team_b');

    $randomLoss = MatchResult::query()
        ->where('match_id', $match->id)
        ->where('player_id', $randomTeam[0]->id)
        ->firstOrFail();

    $premadeWin = MatchResult::query()
        ->where('match_id', $match->id)
        ->where('player_id', $premadeTeam[0]->id)
        ->firstOrFail();

    expect($randomLoss->pl_change)->toBe(-1.6);
    expect($randomLoss->mmr_change)->toBe(-14);
    expect($randomLoss->scoring_context['queue_type_multiplier_pl'])->toBe(0.8);
    expect($randomLoss->scoring_context['queue_type_multiplier_mmr'])->toBe(0.86);

    expect($premadeWin->pl_change)->toBe(2.4);
    expect($premadeWin->mmr_change)->toBe(14);
    expect($premadeWin->scoring_context['queue_type_multiplier_pl'])->toBe(0.8);
    expect($premadeWin->scoring_context['queue_type_multiplier_mmr'])->toBe(0.86);
});

it('keeps queue type multipliers neutral for random mirrors and premade mirrors', function () {
    Storage::fake(MatchReport::EVIDENCE_DISK);

    AppSetting::setValue('random_vs_premade_pl_bonus_pct', 25, 'runtime', 'float');
    AppSetting::setValue('random_vs_premade_mmr_bonus_pct', 18, 'runtime', 'float');
    AppSetting::setValue('premade_vs_random_pl_win_penalty_pct', 20, 'runtime', 'float');
    AppSetting::setValue('premade_vs_random_mmr_win_penalty_pct', 14, 'runtime', 'float');

    $randomTeamA = [
        makePremadeFlowPlayer('mirror-rand-a1', 'ignis', 'knight', 1000),
        makePremadeFlowPlayer('mirror-rand-a2', 'ignis', 'hunter', 1000),
    ];

    $randomTeamB = [
        makePremadeFlowPlayer('mirror-rand-b1', 'alsius', 'knight', 1000),
        makePremadeFlowPlayer('mirror-rand-b2', 'alsius', 'hunter', 1000),
    ];

    $randomMirror = ArenaMatch::create([
        'match_code' => 'ARENA-8555',
        'report_token' => 'RVPBONUS03',
        'queue_mode' => 'random',
        'team_a_queue_type' => 'random',
        'team_b_queue_type' => 'random',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => premadeFlowPayload($randomTeamA),
        'team_b' => premadeFlowPayload($randomTeamB),
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'started_at' => now()->subMinutes(5),
        'expires_at' => now()->addMinutes(20),
    ]);

    app(ArenaMatchResultService::class)->forceComplete($randomMirror, 'team_a');

    $randomWinner = MatchResult::query()
        ->where('match_id', $randomMirror->id)
        ->where('player_id', $randomTeamA[0]->id)
        ->firstOrFail();

    expect($randomWinner->pl_change)->toBe(3.0);
    expect($randomWinner->mmr_change)->toBe(16);
    // json_encode(1.0) serializa "1", asi que el multiplicador neutro vuelve
    // como int. Se compara el valor, que es lo que importa (los no enteros,
    // como 1.25 o 0.8, si conservan el tipo float y usan toBe()).
    expect($randomWinner->scoring_context['queue_type_multiplier_pl'])->toEqual(1.0);
    expect($randomWinner->scoring_context['queue_type_multiplier_mmr'])->toEqual(1.0);

    $premadeTeamA = [
        makePremadeFlowPlayer('mirror-prem-a1', 'syrtis', 'knight', 1000),
        makePremadeFlowPlayer('mirror-prem-a2', 'syrtis', 'hunter', 1000),
    ];

    $premadeTeamB = [
        makePremadeFlowPlayer('mirror-prem-b1', 'ignis', 'knight', 1000),
        makePremadeFlowPlayer('mirror-prem-b2', 'ignis', 'hunter', 1000),
    ];

    $premadeMirror = ArenaMatch::create([
        'match_code' => 'ARENA-8666',
        'report_token' => 'RVPBONUS04',
        'queue_mode' => 'premade',
        'team_a_queue_type' => 'premade',
        'team_b_queue_type' => 'premade',
        'team_a_realm' => 'syrtis',
        'team_b_realm' => 'ignis',
        'team_a' => premadeFlowPayload($premadeTeamA),
        'team_b' => premadeFlowPayload($premadeTeamB),
        'team_a_party_signature' => collect($premadeTeamA)->pluck('user_id')->sort()->implode('-'),
        'team_b_party_signature' => collect($premadeTeamB)->pluck('user_id')->sort()->implode('-'),
        'zone' => 'emerald_pass',
        'status' => 'in_progress',
        'started_at' => now()->subMinutes(5),
        'expires_at' => now()->addMinutes(20),
    ]);

    app(ArenaMatchResultService::class)->forceComplete($premadeMirror, 'team_b');

    $premadeWinner = MatchResult::query()
        ->where('match_id', $premadeMirror->id)
        ->where('player_id', $premadeTeamB[0]->id)
        ->firstOrFail();

    expect($premadeWinner->pl_change)->toBe(3.0);
    expect($premadeWinner->mmr_change)->toBe(16);
    expect($premadeWinner->scoring_context['queue_type_multiplier_pl'])->toEqual(1.0);
    expect($premadeWinner->scoring_context['queue_type_multiplier_mmr'])->toEqual(1.0);
});
