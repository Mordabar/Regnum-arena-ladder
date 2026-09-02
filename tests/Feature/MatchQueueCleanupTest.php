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

function makeActiveMatchWithAcceptedQueues(string $tag = ''): ArenaMatch
{
    // El sufijo permite montar mas de un enfrentamiento en el mismo test sin
    // chocar con el discord_id, que es unico.
    $teamAPlayers = [
        makeLifecyclePlayer('a1' . $tag, 'ignis'),
        makeLifecyclePlayer('a2' . $tag, 'ignis'),
    ];

    $teamBPlayers = [
        makeLifecyclePlayer('b1' . $tag, 'syrtis'),
        makeLifecyclePlayer('b2' . $tag, 'syrtis'),
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-9001' . $tag,
        'report_token' => 'REPORT9001' . $tag,
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

it('el silencio del rival da el reporte por bueno en vez de abrir una disputa', function () {
    // Antes esto mandaba el match a disputa, o sea a una cola que solo un
    // administrador podia vaciar: bastaba con que el rival no volviera a entrar
    // para dejar el enfrentamiento colgado para siempre.
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
    expect($match->fresh()->status)->toBe('completed');
    expect($match->fresh()->report->status)->toBe('confirmed');
    expect($match->fresh()->expires_at)->toBeNull();
    expect($match->fresh()->results()->count())->toBeGreaterThan(0);
});

it('una disputa que nadie resuelve se anula sola al vencer su plazo', function () {
    // Era el unico estado sin plazo: esperaba a un administrador para siempre,
    // y un ladder de una persona no puede apoyarse en que esa persona entre.
    AppSetting::setValue('dispute_auto_void_hours', 48, 'runtime', 'integer', false);
    AppSetting::flushSettingsCache();

    $match = makeActiveMatchWithAcceptedQueues();
    $match->update(['status' => 'disputed', 'expires_at' => null]);
    // updated_at se toca aparte: el update de arriba lo habria puesto a ahora.
    ArenaMatch::withoutTimestamps(fn () => ArenaMatch::query()
        ->whereKey($match->id)
        ->update(['updated_at' => now()->subHours(49)]));

    $result = app(ArenaMatchResultService::class)->sweepPostMatchState();

    expect($result['expired_disputes'])->toBe(1);
    expect($match->fresh()->status)->toBe('void');
    expect($match->fresh()->results()->count())->toBe(0);
});

it('una disputa reciente sigue esperando a moderacion', function () {
    AppSetting::setValue('dispute_auto_void_hours', 48, 'runtime', 'integer', false);
    AppSetting::flushSettingsCache();

    $match = makeActiveMatchWithAcceptedQueues();
    $match->update(['status' => 'disputed', 'expires_at' => null]);

    $result = app(ArenaMatchResultService::class)->sweepPostMatchState();

    expect($result['expired_disputes'])->toBe(0);
    expect($match->fresh()->status)->toBe('disputed');
});

it('ningun enfrentamiento activo se queda sin un cierre automatico', function () {
    // La garantia entera en un test: se dejan vencidos los tres plazos a la vez
    // y despues del barrido no puede quedar nada en un estado abierto.
    Storage::fake('arena_reports');
    AppSetting::setValue('dispute_auto_void_hours', 1, 'runtime', 'integer', false);
    AppSetting::flushSettingsCache();

    // 1. Nadie reporto dentro de la ventana de caceria.
    $silent = makeActiveMatchWithAcceptedQueues();
    $silent->update(['expires_at' => now()->subMinute()]);

    // 2. Reportado, pero el rival no contesto.
    $reported = makeActiveMatchWithAcceptedQueues('-dos');
    $reporter = Player::findOrFail($reported->getTeamPlayerIds('team_a')[0]);
    app(ArenaMatchResultService::class)->submitSyntheticReport($reported, $reporter, 'team_a', 'sin respuesta');
    $reported->refresh()->update(['expires_at' => now()->subMinute()]);

    $service = app(ArenaMatchResultService::class);

    // El primer barrido manda el silencioso a disputa y cierra el reportado.
    $service->sweepPostMatchState();
    // El segundo, con la disputa ya vencida, la anula.
    ArenaMatch::query()->whereKey($silent->id)->update(['updated_at' => now()->subHours(2)]);
    $service->sweepPostMatchState();

    expect($silent->fresh()->status)->toBe('void')
        ->and($reported->fresh()->status)->toBe('completed');

    $stillOpen = ArenaMatch::query()
        ->whereIn('status', ['pending_acceptance', 'in_progress', 'disputed'])
        ->count();

    expect($stillOpen)->toBe(0);
});
