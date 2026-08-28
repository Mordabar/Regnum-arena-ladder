<?php

use App\Models\ArenaMatch;
use App\Models\ArenaSeason;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchmakingService;
use App\Services\LadderScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeArenaModePlayer(string $suffix, string $realm, int $mmr3v3 = 1000): Player
{
    $user = User::create([
        'discord_id' => 'arena-mode-' . $suffix,
        'discord_username' => 'mode_' . $suffix,
        'name' => 'Mode ' . $suffix,
        'email' => $suffix . '@arena-mode.test',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Mode ' . $suffix,
        'subclass' => 'knight',
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 1000,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'pl_points_3v3' => 0,
        'mmr_3v3' => $mmr3v3,
        'matches_played_3v3' => 0,
        'wins_3v3' => 0,
        'losses_3v3' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function queueArenaModePlayer(Player $player, string $mode): Queue
{
    $season = ArenaSeason::current();

    return Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => $mode,
        'season_id' => $season->id,
        'status' => 'waiting',
        'estimated_mmr' => $player->seasonStats($season)['mmr'],
        'joined_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
}

it('creates a 3v3 match with six players and does not mix 2v2 queues', function () {
    ArenaSeason::current()->update(['enabled_modes' => ['3v3']]);
    foreach (range(1, 3) as $index) {
        queueArenaModePlayer(makeArenaModePlayer('ign-' . $index, 'ignis'), '3v3');
        queueArenaModePlayer(makeArenaModePlayer('als-' . $index, 'alsius'), '3v3');
    }

    queueArenaModePlayer(makeArenaModePlayer('syr-2v2', 'syrtis'), '2v2');

    $created = app(ArenaMatchmakingService::class)->processQueue();

    expect($created)->toBe(1);

    $match = ArenaMatch::query()->firstOrFail();
    expect($match->arena_mode)->toBe('3v3')
        ->and($match->team_a)->toHaveCount(3)
        ->and($match->team_b)->toHaveCount(3)
        ->and($match->player_count)->toBe(6);

    expect(Queue::query()->where('arena_mode', '2v2')->where('status', 'waiting')->count())->toBe(1);
});

it('keeps one ladder for the active season regardless of the mode query', function () {
    $season = ArenaSeason::current();
    $leader = makeArenaModePlayer('season-leader', 'ignis', 900);
    $runnerUp = makeArenaModePlayer('season-runner', 'ignis', 1300);

    $leader->updateSeasonStats($season, ['pl_points' => 25, 'mmr' => 1200, 'wins' => 8]);
    $runnerUp->updateSeasonStats($season, ['pl_points' => 10, 'mmr' => 1300, 'wins' => 4]);

    foreach (['2v2', '3v3'] as $mode) {
        $this->get(route('ladder.index', ['mode' => $mode]))
            ->assertOk()
            ->assertSeeInOrder([$leader->character_name, $runnerUp->character_name]);
    }
});

it('accumulates 2v2 and 3v3 results in the same seasonal statistics', function () {
    $season = ArenaSeason::current();
    $subject = makeArenaModePlayer('shared-subject', 'ignis');
    $allyA = makeArenaModePlayer('shared-ally-a', 'ignis');
    $allyB = makeArenaModePlayer('shared-ally-b', 'ignis');
    $rivals = collect(range(1, 3))->map(fn ($index) => makeArenaModePlayer('shared-rival-' . $index, 'alsius'));
    $scoring = app(LadderScoringService::class);

    $scoring->calculateMatchResult(
        [$subject->id, $allyA->id],
        $rivals->take(2)->pluck('id')->all(),
        true,
        '2v2',
        $season->id
    );

    $scoring->calculateMatchResult(
        [$subject->id, $allyA->id, $allyB->id],
        $rivals->pluck('id')->all(),
        true,
        '3v3',
        $season->id
    );

    $stats = $subject->fresh()->seasonStats($season);
    expect($stats['matches_played'])->toBe(2)
        ->and($stats['wins'])->toBe(2)
        ->and($stats['pl_points'])->toBeGreaterThan(0);
});

it('archives the current ladder and shows its winner in the hall of fame', function () {
    $season = ArenaSeason::current();
    $winner = makeArenaModePlayer('hall-winner', 'ignis');
    $winner->updateSeasonStats($season, ['pl_points' => 30, 'mmr' => 1250, 'wins' => 10, 'matches_played' => 12]);
    $this->withSession([
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ])->post(route('admin.seasons.start'), [
        'new_season_name' => 'Next Season',
        'new_season_mode' => '3v3',
    ])->assertRedirect();

    expect($season->fresh()->status)->toBe(ArenaSeason::STATUS_ARCHIVED)
        ->and(ArenaSeason::current()->name)->toBe('Next Season')
        ->and(ArenaSeason::current()->enabledModes())->toBe(['3v3'])
        ->and($winner->fresh()->pl_points)->toBe(0.0);

    $this->get(route('hall-of-fame.index'))
        ->assertOk()
        ->assertSee($season->name)
        ->assertSee($winner->character_name);
});
