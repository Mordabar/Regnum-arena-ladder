<?php

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Party;
use App\Models\PartyMember;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchmakingService;
use App\Services\LadderScoringService;
use App\Support\ArenaMode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setArenaModeEnabled(string $mode, bool $enabled): void
{
    AppSetting::setValue(ArenaMode::settingKey($mode), $enabled ? '1' : '0', 'modes', 'boolean', true);
}

function enableOnlyModes(array $modes): void
{
    foreach (ArenaMode::all() as $mode) {
        setArenaModeEnabled($mode, in_array($mode, $modes, true));
    }
}

function makeModePlayer(string $suffix, string $realm, string $subclass = 'knight', int $mmr = 1000): Player
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
        'subclass' => $subclass,
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => $mmr,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function queueModePlayer(Player $player, string $mode): Queue
{
    return Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => $mode,
        'status' => 'waiting',
        'estimated_mmr' => $player->mmr,
        'joined_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
}

function adminSession(): array
{
    return [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];
}

it('arma un match de 3v3 con seis jugadores sin mezclar las colas de 2v2', function () {
    enableOnlyModes(['2v2', '3v3']);

    // Seis jugadores para 3v3: tres por reino.
    foreach (range(1, 3) as $index) {
        queueModePlayer(makeModePlayer('ign-' . $index, 'ignis'), '3v3');
        queueModePlayer(makeModePlayer('als-' . $index, 'alsius'), '3v3');
    }

    // Un solitario en 2v2 que no debe ser absorbido por el 3v3.
    queueModePlayer(makeModePlayer('syr-solo', 'syrtis'), '2v2');

    $created = app(ArenaMatchmakingService::class)->processQueue();

    expect($created)->toBe(1);

    $match = ArenaMatch::query()->firstOrFail();

    expect($match->arena_mode)->toBe('3v3')
        ->and($match->team_a)->toHaveCount(3)
        ->and($match->team_b)->toHaveCount(3)
        ->and($match->player_count)->toBe(6);

    expect(Queue::query()->where('arena_mode', '2v2')->where('status', 'waiting')->count())->toBe(1);
});

it('sigue armando matches de 2v2 con dos jugadores por equipo', function () {
    enableOnlyModes(['2v2', '3v3']);

    foreach (range(1, 2) as $index) {
        queueModePlayer(makeModePlayer('two-ign-' . $index, 'ignis'), '2v2');
        queueModePlayer(makeModePlayer('two-als-' . $index, 'alsius'), '2v2');
    }

    expect(app(ArenaMatchmakingService::class)->processQueue())->toBe(1);

    $match = ArenaMatch::query()->firstOrFail();

    expect($match->arena_mode)->toBe('2v2')
        ->and($match->player_count)->toBe(4);
});

it('nunca enfrenta un equipo de 2v2 contra uno de 3v3', function () {
    enableOnlyModes(['2v2', '3v3']);

    // Un equipo completo de 2v2 en ignis y uno de 3v3 en alsius: no hay rival
    // valido para ninguno de los dos, asi que no debe crearse ningun match.
    foreach (range(1, 2) as $index) {
        queueModePlayer(makeModePlayer('mix-ign-' . $index, 'ignis'), '2v2');
    }
    foreach (range(1, 3) as $index) {
        queueModePlayer(makeModePlayer('mix-als-' . $index, 'alsius'), '3v3');
    }

    expect(app(ArenaMatchmakingService::class)->processQueue())->toBe(0);
    expect(ArenaMatch::query()->count())->toBe(0);
});

it('ignora las colas de una modalidad apagada', function () {
    enableOnlyModes(['2v2']);

    foreach (range(1, 3) as $index) {
        queueModePlayer(makeModePlayer('off-ign-' . $index, 'ignis'), '3v3');
        queueModePlayer(makeModePlayer('off-als-' . $index, 'alsius'), '3v3');
    }

    expect(app(ArenaMatchmakingService::class)->processQueue())->toBe(0);
    expect(ArenaMatch::query()->count())->toBe(0);
});

it('no procesa nada cuando las dos modalidades estan apagadas', function () {
    enableOnlyModes([]);

    foreach (range(1, 2) as $index) {
        queueModePlayer(makeModePlayer('closed-ign-' . $index, 'ignis'), '2v2');
        queueModePlayer(makeModePlayer('closed-als-' . $index, 'alsius'), '2v2');
    }

    expect(app(ArenaMatchmakingService::class)->processQueue())->toBe(0);
    expect(ArenaMode::anyEnabled())->toBeFalse();
});

it('acumula los resultados de 2v2 y 3v3 en el mismo ranking', function () {
    enableOnlyModes(['2v2', '3v3']);

    $subject = makeModePlayer('shared-subject', 'ignis');
    $allyA = makeModePlayer('shared-ally-a', 'ignis');
    $allyB = makeModePlayer('shared-ally-b', 'ignis');
    $rivals = collect(range(1, 3))->map(fn ($index) => makeModePlayer('shared-rival-' . $index, 'alsius'));

    $scoring = app(LadderScoringService::class);

    // Una victoria en 2v2...
    $scoring->calculateMatchResult(
        [$subject->id, $allyA->id],
        $rivals->take(2)->pluck('id')->all(),
        true
    );

    // ...y otra en 3v3 caen sobre las mismas columnas del jugador.
    $scoring->calculateMatchResult(
        [$subject->id, $allyA->id, $allyB->id],
        $rivals->pluck('id')->all(),
        true
    );

    $subject->refresh();

    expect($subject->matches_played)->toBe(2)
        ->and($subject->wins)->toBe(2)
        ->and($subject->pl_points)->toBeGreaterThan(0);
});

it('usa la misma formula de puntos sin importar la modalidad', function () {
    $scoring = app(LadderScoringService::class);

    $winners2v2 = collect(range(1, 2))->map(fn ($i) => makeModePlayer('f-w2-' . $i, 'ignis', 'knight', 1000));
    $losers2v2 = collect(range(1, 2))->map(fn ($i) => makeModePlayer('f-l2-' . $i, 'alsius', 'knight', 1000));
    $winners3v3 = collect(range(1, 3))->map(fn ($i) => makeModePlayer('f-w3-' . $i, 'ignis', 'knight', 1000));
    $losers3v3 = collect(range(1, 3))->map(fn ($i) => makeModePlayer('f-l3-' . $i, 'alsius', 'knight', 1000));

    $result2v2 = $scoring->calculateMatchResult(
        $winners2v2->pluck('id')->all(),
        $losers2v2->pluck('id')->all()
    );
    $result3v3 = $scoring->calculateMatchResult(
        $winners3v3->pluck('id')->all(),
        $losers3v3->pluck('id')->all()
    );

    // Mismo MMR promedio en ambos lados => mismo PL ganado y perdido.
    expect($result3v3['pl_win'])->toBe($result2v2['pl_win'])
        ->and($result3v3['pl_loss'])->toBe($result2v2['pl_loss'])
        ->and($result3v3['category'])->toBe($result2v2['category']);
});

it('permite al admin encender y apagar cada modalidad por separado', function () {
    enableOnlyModes(['2v2']);

    expect(ArenaMode::isEnabled('2v2'))->toBeTrue()
        ->and(ArenaMode::isEnabled('3v3'))->toBeFalse();

    setArenaModeEnabled('3v3', true);

    expect(ArenaMode::enabled())->toBe(['2v2', '3v3']);

    setArenaModeEnabled('2v2', false);

    expect(ArenaMode::isEnabled('2v2'))->toBeFalse()
        ->and(ArenaMode::isEnabled('3v3'))->toBeTrue()
        ->and(ArenaMode::default())->toBe('3v3');
});

it('saca de la cola a los jugadores cuando el admin apaga esa modalidad', function () {
    enableOnlyModes(['2v2', '3v3']);

    $waiting3v3 = queueModePlayer(makeModePlayer('drop-ign', 'ignis'), '3v3');
    $waiting2v2 = queueModePlayer(makeModePlayer('keep-als', 'alsius'), '2v2');

    $this->withSession(adminSession())
        ->post(route('admin.settings.update'), array_merge(defaultSettingsPayload(), [
            'mode_2v2_enabled' => '1',
            'mode_3v3_enabled' => '0',
        ]))
        ->assertRedirect();

    expect($waiting3v3->fresh()->status)->toBe('cancelled')
        ->and($waiting2v2->fresh()->status)->toBe('waiting')
        ->and(ArenaMode::isEnabled('3v3'))->toBeFalse();
});

it('devuelve la party a estado previo en vez de disolverla al apagar su modalidad', function () {
    enableOnlyModes(['2v2', '3v3']);

    $leader = makeModePlayer('party-leader', 'ignis');
    $mateA = makeModePlayer('party-mate-a', 'ignis');
    $mateB = makeModePlayer('party-mate-b', 'ignis');

    $party = Party::create([
        'leader_player_id' => $leader->id,
        'status' => 'queued',
        'realm' => 'ignis',
        'arena_mode' => '3v3',
    ]);

    foreach ([$leader, $mateA, $mateB] as $index => $player) {
        PartyMember::create([
            'party_id' => $party->id,
            'player_id' => $player->id,
            'is_accepted_invite' => true,
            'is_leader' => $index === 0,
        ]);
    }

    $this->withSession(adminSession())
        ->post(route('admin.settings.update'), array_merge(defaultSettingsPayload(), [
            'mode_2v2_enabled' => '1',
            'mode_3v3_enabled' => '0',
        ]))
        ->assertRedirect();

    // La party sobrevive lista para volver a encolar si se reactiva 3v3.
    expect($party->fresh()->status)->toBe('ready');
});

it('respeta el tamaño de party segun la modalidad', function () {
    expect(ArenaMode::teamSize('2v2'))->toBe(2)
        ->and(ArenaMode::teamSize('3v3'))->toBe(3);

    $party2v2 = Party::create([
        'leader_player_id' => makeModePlayer('size-a', 'ignis')->id,
        'status' => 'forming',
        'realm' => 'ignis',
        'arena_mode' => '2v2',
    ]);
    $party3v3 = Party::create([
        'leader_player_id' => makeModePlayer('size-b', 'ignis')->id,
        'status' => 'forming',
        'realm' => 'ignis',
        'arena_mode' => '3v3',
    ]);

    expect($party2v2->teamSize())->toBe(2)
        ->and($party3v3->teamSize())->toBe(3);
});

it('etiqueta cada match con su modalidad real', function () {
    $match2v2 = new ArenaMatch(['queue_mode' => 'random', 'arena_mode' => '2v2']);
    $match3v3 = new ArenaMatch(['queue_mode' => 'premade', 'arena_mode' => '3v3']);

    expect($match2v2->queue_mode_name)->toBe('Random 2v2')
        ->and($match3v3->queue_mode_name)->toBe('Premade 3v3');
});

/**
 * El formulario de ajustes del admin postea todos sus campos juntos, asi que
 * los tests que solo quieren mover los interruptores igual deben mandar el resto.
 */
function defaultSettingsPayload(): array
{
    return [
        'season_name' => 'Alpha Season',
        'home_tagline' => 'Conquest PvP por reino y subclase',
        'rules_excerpt' => 'Random y premade con ladder automatico.',
        'support_contact' => '',
        'discord_invite_url' => '',
        'discord_server_label' => '',
        'accept_window_minutes' => 5,
        'hunt_window_minutes' => 30,
        'report_confirmation_window_minutes' => 15,
        'premade_daily_limit' => 3,
        'random_vs_premade_pl_bonus_pct' => 25,
        'random_vs_premade_mmr_bonus_pct' => 18,
        'premade_vs_random_pl_win_penalty_pct' => 20,
        'premade_vs_random_mmr_win_penalty_pct' => 14,
        'abandonment_lock_hours' => 12,
        'support_infraction_lock_hours' => 24,
        'abandonment_trust_penalty' => 15,
        'support_infraction_trust_penalty' => 25,
        'penalty_max_lock_hours' => 96,
    ];
}
