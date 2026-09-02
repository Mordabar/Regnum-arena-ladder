<?php

use App\Models\ArenaMatch;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\TestingLabService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function purgeBot(string $suffix, string $realm = 'ignis'): Player
{
    $user = User::create([
        'discord_id' => TestingLabService::LAB_DISCORD_PREFIX . $suffix,
        'discord_username' => 'bot_' . $suffix,
        'name' => 'Bot ' . $suffix,
        'email' => $suffix . '@' . TestingLabService::LAB_EMAIL_DOMAIN,
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Bot' . $suffix,
        'subclass' => 'knight',
        'realm' => $realm,
        'race' => Player::defaultRace($realm),
        'gender' => 'male',
        'pl_points' => 0,
        'mmr' => 800,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function purgeHuman(string $suffix, array $stats = []): Player
{
    $user = User::create([
        'discord_id' => 'real-' . $suffix,
        'discord_username' => 'humano_' . $suffix,
        'name' => 'Humano ' . $suffix,
        'email' => $suffix . '@example.com',
    ]);

    return Player::create(array_merge([
        'user_id' => $user->id,
        'character_name' => 'Humano' . $suffix,
        'subclass' => 'hunter',
        'realm' => 'syrtis',
        'race' => 'alturian',
        'gender' => 'male',
        'pl_points' => 100.0,
        'mmr' => 1200,
        'wins' => 10,
        'losses' => 5,
        'matches_played' => 15,
        'trust_score' => 100,
        'is_active' => true,
    ], $stats));
}

function purgePack(Player $p): array
{
    return [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];
}

it('limpia el rastro aunque haya enfrentamientos con personajes reales', function () {
    // Este era el bloqueo: probar el flujo obliga a jugar con tu propio
    // personaje, asi que siempre habia un match mixto y el boton no servia.
    $bot = purgeBot('a');
    $humano = purgeHuman('a');

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-8001',
        'report_token' => 'PURGE001',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'syrtis',
        'team_a' => [purgePack($bot)],
        'team_b' => [purgePack($humano)],
        'zone' => 'frozen_bridge',
        'status' => 'completed',
        'estimated_mmr_avg' => 1000,
        'player_count' => 2,
    ]);

    $result = app(TestingLabService::class)->purgeTrace(true);

    expect($result['matches_deleted'])->toBe(1)
        ->and(ArenaMatch::find($match->id))->toBeNull()
        ->and(Player::find($bot->id))->toBeNull()
        ->and(Player::find($humano->id))->not->toBeNull();
});

it('devuelve al jugador real los puntos que gano en las pruebas', function () {
    // Borrar el match sin deshacer el reparto dejaria al personaje real con
    // puntos de mentira en el ranking.
    $bot = purgeBot('b');
    $humano = purgeHuman('b');

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-8002',
        'report_token' => 'PURGE002',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'syrtis',
        'team_a' => [purgePack($bot)],
        'team_b' => [purgePack($humano)],
        'zone' => 'frozen_bridge',
        'status' => 'completed',
        'estimated_mmr_avg' => 1000,
        'player_count' => 2,
    ]);

    MatchResult::create([
        'match_id' => $match->id,
        'player_id' => $humano->id,
        'result' => 'win',
        'pl_change' => 12.5,
        'mmr_change' => 20,
        'pl_before' => 87.5,
        'pl_after' => 100.0,
        'mmr_before' => 1180,
        'mmr_after' => 1200,
        'created_at' => now(),
    ]);

    MatchResult::create([
        'match_id' => $match->id,
        'player_id' => $bot->id,
        'result' => 'loss',
        'pl_change' => -8.0,
        'mmr_change' => -15,
        'pl_before' => 8.0,
        'pl_after' => 0.0,
        'mmr_before' => 815,
        'mmr_after' => 800,
        'created_at' => now(),
    ]);

    $resultado = app(TestingLabService::class)->purgeTrace(true);
    $humano->refresh();

    expect((float) $humano->pl_points)->toBe(87.5)
        ->and($humano->mmr)->toBe(1180)
        ->and($humano->wins)->toBe(9)
        ->and($humano->losses)->toBe(5)
        ->and($humano->matches_played)->toBe(14)
        ->and($resultado['real_players_restored'])->toBe(1)
        ->and($resultado['pl_reverted'])->toBe(12.5);
});

it('no deja a nadie con puntos negativos', function () {
    // Un personaje que solo jugo pruebas acabaria por debajo de cero si se
    // restase a ciegas.
    $bot = purgeBot('c');
    $novato = purgeHuman('c', ['pl_points' => 5.0, 'mmr' => 1000, 'wins' => 1, 'losses' => 0, 'matches_played' => 1]);

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-8003',
        'report_token' => 'PURGE003',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'syrtis',
        'team_a' => [purgePack($bot)],
        'team_b' => [purgePack($novato)],
        'zone' => 'frozen_bridge',
        'status' => 'completed',
        'estimated_mmr_avg' => 1000,
        'player_count' => 2,
    ]);

    MatchResult::create([
        'match_id' => $match->id,
        'player_id' => $novato->id,
        'result' => 'win',
        'pl_change' => 40.0,
        'mmr_change' => 500,
        'pl_before' => 0,
        'pl_after' => 40.0,
        'mmr_before' => 500,
        'mmr_after' => 1000,
        'created_at' => now(),
    ]);

    app(TestingLabService::class)->purgeTrace(true);
    $novato->refresh();

    expect((float) $novato->pl_points)->toBe(0.0)
        ->and($novato->mmr)->toBe(500)
        ->and($novato->wins)->toBe(0);
});

it('reiniciar deja los bots a cero sin borrarlos', function () {
    $bot = purgeBot('d');
    $bot->update(['pl_points' => 55.0, 'mmr' => 1100, 'wins' => 4, 'matches_played' => 6]);

    Queue::create([
        'player_id' => $bot->id,
        'queue_type' => 'random',
        'arena_mode' => '2v2',
        'status' => 'waiting',
        'estimated_mmr' => $bot->mmr,
        'joined_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    app(TestingLabService::class)->purgeTrace(false);
    $bot->refresh();

    expect($bot->exists)->toBeTrue()
        ->and((float) $bot->pl_points)->toBe(0.0)
        ->and($bot->mmr)->toBe(800)
        ->and($bot->wins)->toBe(0)
        ->and(Queue::where('player_id', $bot->id)->count())->toBe(0);
});

it('el laboratorio se puede vaciar y regenerar desde el panel', function () {
    // El recorrido completo que antes daba error: hay un match mixto, se pide
    // borrar y se pide crear de nuevo.
    $bot = purgeBot('e');
    $humano = purgeHuman('e');

    ArenaMatch::create([
        'match_code' => 'ARENA-8004',
        'report_token' => 'PURGE004',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'syrtis',
        'team_a' => [purgePack($bot)],
        'team_b' => [purgePack($humano)],
        'zone' => 'frozen_bridge',
        'status' => 'completed',
        'estimated_mmr_avg' => 1000,
        'player_count' => 2,
    ]);

    $sesion = [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];

    $this->withSession($sesion)
        ->post(route('admin.testing.destroy'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(TestingLabService::class)->testPlayerIds())->toHaveCount(0);

    $this->withSession($sesion)
        ->post(route('admin.testing.seed'), [
            'ignis_count' => 2,
            'syrtis_count' => 2,
            'alsius_count' => 0,
            'replace_existing' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(TestingLabService::class)->testPlayerIds())->toHaveCount(4);
});

it('un bot puede reportar para que la persona ensaye la confirmacion', function () {
    // Cerrar de golpe un enfrentamiento con personas dentro se sigue negando,
    // porque repartiria puntos sin que nadie confirmara. Lo que si se puede es
    // empujar la mitad del bot.
    \Illuminate\Support\Facades\Storage::fake('arena_reports');

    $lab = app(\App\Services\TestingLabService::class);
    $lab->seedRoster(['ignis' => 2, 'syrtis' => 2]);
    $bots = $lab->testPlayersQuery()->get();

    $human = \App\Models\Player::create([
        'user_id' => \App\Models\User::create([
            'discord_id' => 'lab-human',
            'discord_username' => 'lab_human',
            'name' => 'Lab Human',
            'email' => 'lab-human@example.com',
        ])->id,
        'character_name' => 'Persona',
        'subclass' => 'knight',
        'realm' => 'ignis',
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);

    $mate = $bots->firstWhere('realm', 'ignis');
    $foes = $bots->where('realm', 'syrtis')->take(2)->values();

    $pack = fn (\App\Models\Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    $match = \App\Models\ArenaMatch::create([
        'match_code' => 'LAB-1', 'report_token' => 'LABTOKEN',
        'queue_mode' => 'random', 'arena_mode' => '2v2',
        'team_a_realm' => 'ignis', 'team_b_realm' => 'syrtis',
        'team_a' => [$pack($human), $pack($mate)],
        'team_b' => [$pack($foes[0]), $pack($foes[1])],
        'zone' => 'frozen_bridge', 'status' => 'in_progress',
        'estimated_mmr_avg' => 1000, 'player_count' => 4,
        'started_at' => now(), 'expires_at' => now()->addMinutes(30),
    ]);

    $admin = [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];

    // Cerrar de golpe: se niega, porque hay una persona dentro.
    $this->withSession($admin)
        ->post(route('admin.testing.resolve', $match), ['winner_team' => 'team_a'])
        ->assertSessionHasErrors('error');

    // Reportar por el bot: se acepta, y lo firma un bot del equipo CONTRARIO,
    // que es el unico que deja a la persona algo que confirmar.
    $this->withSession($admin)
        ->post(route('admin.testing.bot-report', $match))
        ->assertSessionHas('success');

    $report = $match->fresh('report')->report;

    expect($report)->not->toBeNull()
        ->and($report->status)->toBe('pending_confirmation')
        ->and($report->reporting_team)->toBe('team_b');
});
