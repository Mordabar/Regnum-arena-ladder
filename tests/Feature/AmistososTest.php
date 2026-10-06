<?php

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\ArenaSeason;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMaintenanceService;
use App\Services\ArenaMatchmakingService;
use App\Services\SeasonClosingService;
use App\Support\ArenaMode;
use App\Support\Competition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Amistosos: PvP que no mueve el ranking.
 *
 * Tres promesas: no se mezclan con el competitivo, no dejan rastro en el
 * ladder, y son lo unico que queda cuando una temporada acaba y no se abre otra.
 */
beforeEach(function () {
    // Una temporada en juego, la de serie, y solo el duelo abierto.
    ArenaSeason::query()->update(['status' => ArenaSeason::STATUS_ARCHIVED]);
    ArenaSeason::create([
        'name' => 'En juego', 'slug' => 'en-juego-' . uniqid(), 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['1v1'], 'starts_at' => now()->subWeek(),
    ]);

    foreach (ArenaMode::all() as $modo) {
        AppSetting::setValue(ArenaMode::settingKey($modo), $modo === '1v1' ? '1' : '0', 'modes', 'boolean', true);
    }
});

function duelista(string $s, string $realm, int $mmr = 1000): Player
{
    $user = User::create([
        'discord_id' => 'am-' . $s, 'discord_username' => 'am_' . $s,
        'name' => 'Am ' . $s, 'email' => 'am-' . $s . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id, 'character_name' => 'Am' . ucfirst($s), 'subclass' => 'hunter',
        'realm' => $realm, 'pl_points' => 30, 'mmr' => $mmr, 'trust_score' => 100,
        'matches_played' => 4, 'wins' => 2, 'losses' => 2, 'is_active' => true,
    ]);
}

function encola(Player $p, bool $ranked, string $modo = '1v1'): Queue
{
    return Queue::create([
        'player_id' => $p->id, 'queue_type' => 'random', 'arena_mode' => $modo, 'is_ranked' => $ranked,
        'status' => 'waiting', 'estimated_mmr' => $p->mmr,
        'joined_at' => now()->subMinutes(5), 'expires_at' => now()->addMinutes(30),
    ]);
}

/** Un duelo ya en marcha entre dos, competitivo o amistoso. */
function duelo(bool $ranked, string $marca = 'x'): array
{
    $a = duelista('a' . $marca, 'ignis');
    $b = duelista('b' . $marca, 'alsius');
    $pack = fn (Player $p) => ['player_id' => $p->id, 'character_name' => $p->character_name, 'subclass' => 'hunter', 'realm' => $p->realm, 'discord_id' => (string) (100000000000000000 + $p->id)];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-' . strtoupper(substr(md5($marca . $ranked), 0, 6)),
        'report_token' => strtoupper(substr(md5($marca . 'tok' . $ranked), 0, 10)),
        'queue_mode' => 'random', 'arena_mode' => '1v1', 'is_ranked' => $ranked,
        'team_a_realm' => 'ignis', 'team_b_realm' => 'alsius',
        'team_a' => [$pack($a)], 'team_b' => [$pack($b)],
        'zone' => 'frozen_bridge', 'status' => 'in_progress', 'estimated_mmr_avg' => 1000, 'player_count' => 2,
        'started_at' => now()->subMinutes(10), 'expires_at' => now()->addMinutes(20),
    ]);

    foreach ([$a, $b] as $p) {
        Queue::create([
            'player_id' => $p->id, 'queue_type' => 'random', 'arena_mode' => '1v1', 'is_ranked' => $ranked,
            'status' => 'accepted', 'match_id' => (string) $match->id, 'joined_at' => now()->subMinutes(12),
        ]);
    }

    return [$match, $a, $b];
}

// ------------------------------------------------------------------ politica

it('el competitivo esta abierto mientras haya una temporada en juego', function () {
    expect(Competition::rankedOpen())->toBeTrue()
        ->and(Competition::open())->toBe(['ranked', 'friendly'])
        ->and(Competition::default())->toBe('ranked');

    ArenaSeason::query()->update(['status' => ArenaSeason::STATUS_ARCHIVED]);

    expect(Competition::rankedOpen())->toBeFalse()
        ->and(Competition::open())->toBe(['friendly'])
        // Pedir competitivo con el ladder en pausa cae en amistoso.
        ->and(Competition::resolve('ranked'))->toBe('friendly')
        ->and(Competition::default())->toBe('friendly');
});

it('una temporada vencida ya no cuenta como en juego aunque el cron no la haya cerrado', function () {
    ArenaSeason::query()->update(['ends_at' => now()->subMinute(), 'auto_close' => true]);

    expect(Competition::rankedOpen())->toBeFalse();

    // Sin cierre automatico la fecha es solo informativa.
    ArenaSeason::query()->update(['auto_close' => false]);

    expect(Competition::rankedOpen())->toBeTrue();
});

it('los amistosos se apagan desde el panel como una modalidad', function () {
    AppSetting::setValue('friendly_enabled', '0', 'runtime', 'boolean', true);

    expect(Competition::friendlyEnabled())->toBeFalse()
        ->and(Competition::open())->toBe(['ranked']);

    ArenaSeason::query()->update(['status' => ArenaSeason::STATUS_ARCHIVED]);

    expect(Competition::open())->toBe([]);
});

// -------------------------------------------------------------------- cola

it('al entrar en cola se elige el tipo y por defecto es competitivo', function () {
    $p = duelista('q1', 'ignis');

    $this->actingAs($p->user)->post(route('queue.join'), ['player_id' => $p->id, 'queue_type' => 'random', 'arena_mode' => '1v1'])
        ->assertSessionHasNoErrors();
    expect(Queue::query()->where('player_id', $p->id)->value('is_ranked'))->toBeTruthy();

    Queue::query()->delete();

    $this->actingAs($p->user)->post(route('queue.join'), ['player_id' => $p->id, 'queue_type' => 'random', 'arena_mode' => '1v1', 'kind' => 'friendly'])
        ->assertSessionHasNoErrors();
    expect((bool) Queue::query()->where('player_id', $p->id)->value('is_ranked'))->toBeFalse();
});

it('con el ladder en pausa no se puede entrar al competitivo, y sin pedir nada entra a amistoso', function () {
    ArenaSeason::query()->update(['status' => ArenaSeason::STATUS_ARCHIVED]);
    $p = duelista('q2', 'ignis');

    $this->actingAs($p->user)->post(route('queue.join'), ['player_id' => $p->id, 'queue_type' => 'random', 'arena_mode' => '1v1', 'kind' => 'ranked'])
        ->assertSessionHasErrors('error');
    expect(Queue::query()->count())->toBe(0);

    $this->actingAs($p->user)->post(route('queue.join'), ['player_id' => $p->id, 'queue_type' => 'random', 'arena_mode' => '1v1'])
        ->assertSessionHasNoErrors();
    expect((bool) Queue::query()->where('player_id', $p->id)->value('is_ranked'))->toBeFalse();
});

it('con los amistosos apagados no se puede entrar a uno', function () {
    AppSetting::setValue('friendly_enabled', '0', 'runtime', 'boolean', true);
    $p = duelista('q3', 'ignis');

    $this->actingAs($p->user)->post(route('queue.join'), ['player_id' => $p->id, 'queue_type' => 'random', 'arena_mode' => '1v1', 'kind' => 'friendly'])
        ->assertSessionHasErrors('error');
});

// -------------------------------------------------------------- emparejador

it('un amistoso y un competitivo nunca se emparejan entre si', function () {
    encola(duelista('m1', 'ignis'), true);
    encola(duelista('m2', 'alsius'), false);

    $creados = app(ArenaMatchmakingService::class)->processQueue(false);

    expect($creados)->toBe(0)
        ->and(ArenaMatch::query()->count())->toBe(0);
});

it('dos en amistoso se emparejan y el cruce nace como amistoso', function () {
    encola(duelista('m3', 'ignis'), false);
    encola(duelista('m4', 'alsius'), false);

    expect(app(ArenaMatchmakingService::class)->processQueue(false))->toBe(1);

    $match = ArenaMatch::query()->firstOrFail();
    expect($match->isFriendly())->toBeTrue()
        ->and($match->is_ranked)->toBeFalse();
});

it('dos competitivos siguen emparejandose como siempre', function () {
    encola(duelista('m5', 'ignis'), true);
    encola(duelista('m6', 'alsius'), true);

    app(ArenaMatchmakingService::class)->processQueue(false);

    expect(ArenaMatch::query()->firstOrFail()->isFriendly())->toBeFalse();
});

it('con el ladder en pausa solo se emparejan los amistosos', function () {
    encola(duelista('m7', 'ignis'), true);
    encola(duelista('m8', 'alsius'), true);
    encola(duelista('m9', 'ignis'), false);
    encola(duelista('m10', 'alsius'), false);

    ArenaSeason::query()->update(['status' => ArenaSeason::STATUS_ARCHIVED]);
    app(ArenaMatchmakingService::class)->processQueue(false);

    expect(ArenaMatch::query()->count())->toBe(1)
        ->and(ArenaMatch::query()->first()->isFriendly())->toBeTrue();
});

it('el mantenimiento libera las colas de un tipo que ya no se puede jugar', function () {
    $rank = encola(duelista('r1', 'ignis'), true);
    $amis = encola(duelista('r2', 'alsius'), false);

    // El ladder se pausa: la cola competitiva se libera, la amistosa sigue.
    ArenaSeason::query()->update(['status' => ArenaSeason::STATUS_ARCHIVED]);
    app(ArenaMaintenanceService::class)->releaseQueuesInDisabledModes();

    expect($rank->fresh()->status)->toBe('cancelled')
        ->and($amis->fresh()->status)->toBe('waiting');

    // Y al apagar los amistosos, tambien esa.
    AppSetting::setValue('friendly_enabled', '0', 'runtime', 'boolean', true);
    app(ArenaMaintenanceService::class)->releaseQueuesInDisabledModes();

    expect($amis->fresh()->status)->toBe('cancelled');
});

// ------------------------------------------------------------ jugar y acabar

it('terminar un amistoso lo cierra sin repartir ni un punto', function () {
    [$match, $a, $b] = duelo(false);
    $antes = [$a->pl_points, $a->mmr, $a->wins, $a->matches_played];

    $this->actingAs($a->user)->post(route('matches.friendly.finish'), ['match_id' => $match->id, 'player_id' => $a->id])
        ->assertSessionHasNoErrors();

    $match->refresh();
    $a->refresh();

    expect($match->status)->toBe('completed')
        ->and(MatchResult::query()->count())->toBe(0)
        ->and([$a->pl_points, $a->mmr, $a->wins, $a->matches_played])->toEqual($antes)
        // Los dos quedan libres para buscar otro.
        ->and(Queue::query()->whereIn('status', ['matched', 'accepted'])->count())->toBe(0);
});

it('un amistoso se puede terminar apuntando el resultado, con o sin capturas, y no puntua', function () {
    \Illuminate\Support\Facades\Storage::fake(\App\Models\MatchReport::EVIDENCE_DISK);
    [$match, $a] = duelo(false, 'r');
    $lado = $match->getTeamSideForPlayer($a->id, (string) $a->user->discord_id);

    // Solo el ganador, sin una sola captura: vale, no hay nada que puntuar.
    $this->actingAs($a->user)->post(route('matches.friendly.finish'), [
        'match_id' => $match->id,
        'player_id' => $a->id,
        'claimed_winner_team' => $lado,
        'reporter_note' => 'buen combate',
    ])->assertSessionHasNoErrors();

    $informe = $match->fresh()->report;

    expect($match->fresh()->status)->toBe('completed')
        ->and($informe)->not->toBeNull()
        ->and($informe->claimed_winner_team)->toBe($lado)
        ->and($informe->status)->toBe('confirmed')
        ->and($informe->evidencePaths())->toBe([])
        ->and($informe->reporter_note)->toBe('buen combate')
        ->and(MatchResult::query()->count())->toBe(0);
});

it('las capturas de un amistoso son opcionales pero, si se adjuntan, quedan en el historial', function () {
    \Illuminate\Support\Facades\Storage::fake(\App\Models\MatchReport::EVIDENCE_DISK);
    [$match, $a] = duelo(false, 'u');

    $this->actingAs($a->user)->post(route('matches.friendly.finish'), [
        'match_id' => $match->id,
        'player_id' => $a->id,
        'claimed_winner_team' => 'draw',
        'evidence_files' => [\Illuminate\Http\UploadedFile::fake()->image('final.png')],
    ])->assertSessionHasNoErrors();

    expect($match->fresh()->report->evidencePaths())->toHaveCount(1);
});

it('un comentario sin ganador no se guarda como un empate', function () {
    [$match, $a] = duelo(false, 'sg');

    $this->actingAs($a->user)->post(route('matches.friendly.finish'), [
        'match_id' => $match->id, 'player_id' => $a->id,
        'claimed_winner_team' => '', 'reporter_note' => 'hola',
    ])->assertSessionHasNoErrors();

    $informe = $match->fresh()->report;

    expect($informe)->not->toBeNull()
        ->and($informe->sinGanador())->toBeTrue()
        ->and($informe->claimed_winner_realm)->toBeNull();
});

it('terminar sin rellenar nada avisa solo de que acabo, y rellenando algo dice que quedo en el historial', function () {
    [$match, $a] = duelo(false, 'm1');
    $vacio = $this->actingAs($a->user)->post(route('matches.friendly.finish'), [
        'match_id' => $match->id, 'player_id' => $a->id,
        'claimed_winner_team' => '', 'reporter_note' => '   ',
    ]);
    expect(session('success'))->toBe('Amistoso terminado. Cuando quieras, busca otro rival.');

    [$match2, $b] = duelo(false, 'm2');
    $lado = $match2->getTeamSideForPlayer($b->id, (string) $b->user->discord_id);
    $this->actingAs($b->user)->post(route('matches.friendly.finish'), [
        'match_id' => $match2->id, 'player_id' => $b->id, 'claimed_winner_team' => $lado,
    ]);
    expect(session('success'))->toContain('historial');
});

it('un amistoso terminado sin decir nada no deja ningun apunte', function () {
    [$match, $a] = duelo(false, 's');

    $this->actingAs($a->user)->post(route('matches.friendly.finish'), ['match_id' => $match->id, 'player_id' => $a->id, 'claimed_winner_team' => ''])
        ->assertSessionHasNoErrors();

    expect($match->fresh()->report)->toBeNull();
});

it('pulsar dos veces o desde los dos lados no rompe nada', function () {
    [$match, $a, $b] = duelo(false);

    $this->actingAs($a->user)->post(route('matches.friendly.finish'), ['match_id' => $match->id, 'player_id' => $a->id]);
    $this->actingAs($b->user)->post(route('matches.friendly.finish'), ['match_id' => $match->id, 'player_id' => $b->id])
        ->assertSessionHasNoErrors();

    expect($match->fresh()->status)->toBe('completed');
});

it('un competitivo no se termina con el boton de amistoso, ni lo termina un ajeno', function () {
    [$match, $a] = duelo(true, 'c');

    $this->actingAs($a->user)->post(route('matches.friendly.finish'), ['match_id' => $match->id, 'player_id' => $a->id])
        ->assertSessionHasErrors('error');
    expect($match->fresh()->status)->toBe('in_progress');

    [$amistoso] = duelo(false, 'd');
    $intruso = duelista('intruso', 'syrtis');

    $this->actingAs($intruso->user)->post(route('matches.friendly.finish'), ['match_id' => $amistoso->id, 'player_id' => $intruso->id])
        ->assertSessionHasErrors('error');
    expect($amistoso->fresh()->status)->toBe('in_progress');
});

it('un amistoso no se reporta ni se denuncia', function () {
    [$match, $a, $b] = duelo(false);

    expect(fn () => app(\App\Services\ArenaMatchResultService::class)->submitReport($match, $a, [
        'claimed_winner_team' => 'team_a', 'evidence_files' => [],
    ]))->toThrow(RuntimeException::class, 'amistoso');

    expect(fn () => app(\App\Services\ArenaAbandonmentService::class)->report($match, $a, $b->id, 'se fue sin avisar'))
        ->toThrow(RuntimeException::class);
});

it('un amistoso que nadie termina se cierra solo, sin anularlo ni sancionar', function () {
    [$amistoso] = duelo(false, 'e');
    [$competitivo] = duelo(true, 'f');
    ArenaMatch::query()->update(['expires_at' => now()->subMinute()]);

    app(ArenaMaintenanceService::class)->runTick(false);

    expect($amistoso->fresh()->status)->toBe('completed')
        // El competitivo sin reporte sigue su regla de siempre: se anula.
        ->and($competitivo->fresh()->status)->toBe('void');
});

it('un amistoso contra el mismo rival no cuenta como repeticion del competitivo', function () {
    [$match, $a, $b] = duelo(false, 'g');
    $match->update(['status' => 'completed', 'completed_at' => now()]);

    $historial = app(\App\Services\Matchmaking\RepeatOpponentPolicy::class)->buildRecentPairHistory();

    expect($historial)->toBe([]);
});

// ----------------------------------------------- temporada sin siguiente

it('cerrar sin abrir la siguiente deja el ladder en pausa', function () {
    $season = ArenaSeason::current();
    duelista('s1', 'ignis');

    $resultado = app(SeasonClosingService::class)->cerrar(null, true, ['abrir_siguiente' => false]);

    expect($resultado['ok'])->toBeTrue()
        ->and($resultado['siguiente'])->toBeNull()
        ->and(ArenaSeason::current())->toBeNull()
        ->and(Competition::rankedOpen())->toBeFalse()
        ->and($season->fresh()->status)->toBe(ArenaSeason::STATUS_ARCHIVED);
});

it('con el ladder en pausa la portada, el ladder y el lobby avisan', function () {
    ArenaSeason::query()->update(['status' => ArenaSeason::STATUS_ARCHIVED, 'ends_at' => now()->subDay()]);
    $p = duelista('p1', 'ignis');

    $this->get(route('home'))->assertOk()->assertSee('Ladder en pausa')->assertDontSee('lingotes de Magnanita');
    $this->get(route('ladder.index'))->assertOk()->assertSee('Ladder en pausa');
    $this->get(route('hall-of-fame'))->assertOk()->assertSee('Ladder en pausa');
    $this->actingAs($p->user)->get(route('lobby'))->assertOk()->assertSee('Ladder en pausa')->assertSee('Amistoso');
});

it('con una temporada en juego no sale el aviso de pausa', function () {
    $this->get(route('ladder.index'))->assertOk()->assertDontSee('Ladder en pausa');
});

it('el lobby deja elegir entre competitivo y amistoso cuando los dos estan abiertos', function () {
    $p = duelista('l1', 'ignis');
    AppSetting::setValue(ArenaMode::settingKey('2v2'), '1', 'modes', 'boolean', true);

    $this->actingAs($p->user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('Competitivo')
        ->assertSee('Amistoso')
        ->assertSee('name="kind" value="ranked"', false);

    $this->actingAs($p->user)->get(route('lobby', ['kind' => 'friendly']))
        ->assertOk()
        ->assertSee('name="kind" value="friendly"', false);
});

// -------------------------------------------------------------------- admin

it('el panel enciende y apaga los amistosos', function () {
    $sesion = sesionDeAdmin();

    $this->withSession($sesion)->get(route('admin.settings'))->assertOk()->assertSee('Amistosos');

    $campos = [
        'season_name' => 'Alpha Season', 'home_tagline' => 'x', 'rules_excerpt' => 'x',
        'support_contact' => '', 'discord_invite_url' => '', 'discord_server_label' => '',
        'matchmaking_hold_seconds' => 0, 'rematch_rest_minutes' => 2, 'accept_window_minutes' => 5,
        'hunt_window_minutes' => 30, 'report_confirmation_window_minutes' => 15, 'dispute_auto_void_hours' => 48,
        'premade_daily_limit' => 3, 'random_vs_premade_pl_bonus_pct' => 25, 'random_vs_premade_mmr_bonus_pct' => 18,
        'premade_vs_random_pl_win_penalty_pct' => 20, 'premade_vs_random_mmr_win_penalty_pct' => 14,
        'abandonment_lock_hours' => 12, 'support_infraction_lock_hours' => 24, 'abandonment_trust_penalty' => 15,
        'support_infraction_trust_penalty' => 25, 'penalty_max_lock_hours' => 96, 'inactive_after_days' => 14,
    ];

    $this->withSession($sesion)->post(route('admin.settings.update'), $campos + ['friendly_enabled' => '0'])
        ->assertSessionHasNoErrors();

    expect(Competition::friendlyEnabled())->toBeFalse();
});

it('la lista de enfrentamientos del admin filtra por tipo', function () {
    duelo(true, 'h');
    duelo(false, 'i');

    $this->withSession(sesionDeAdmin())->get(route('admin.matches.index', ['kind' => 'friendly']))
        ->assertOk()
        ->assertSee('Amistoso');
});

// ------------------------------------------------------------------ discord

it('el DM y el anuncio de un amistoso lo dicen', function () {
    config([
        'services.discord.bot_token' => 'token-de-prueba',
        'services.discord.announcements.channel_id' => '555000000000000001',
    ]);
    Http::fake([
        'discord.com/api/v10/users/@me/channels' => fn ($r) => Http::response(['id' => 'dm-' . $r['recipient_id']]),
        'discord.com/api/v10/channels/*' => Http::response(['id' => 'mensaje']),
    ]);

    [$match] = duelo(false, 'j');
    app(\App\Services\DiscordBotService::class)->notifyMatchFound($match);
    app()->terminate();

    $cuerpos = collect(Http::recorded())->map(fn ($par) => json_encode($par[0]->data()))->implode(' ');

    expect($cuerpos)->toContain('Amistoso');
});

it('el anuncio del canal distingue un amistoso esperando de uno competitivo', function () {
    config([
        'services.discord.bot_token' => 'token-de-prueba',
        'services.discord.announcements.channel_id' => '555000000000000001',
    ]);
    Http::fake(['discord.com/*' => Http::response(['id' => 'ok'])]);

    $cola = encola(duelista('an1', 'ignis'), false);
    app(\App\Services\Discord\ActivityAnnouncer::class)->queueJoined($cola);

    app()->terminate();

    $cuerpos = collect(Http::recorded())->map(fn ($par) => json_encode($par[0]->data(), JSON_UNESCAPED_UNICODE))->implode(' ');

    expect($cuerpos)->toContain('Amistoso 1v1')
        ->and($cuerpos)->not->toContain('Duelo 1v1');
});

it('el panel cierra la temporada sin abrir otra y lo explica', function () {
    $season = ArenaSeason::current();
    duelista('ad1', 'ignis');

    $this->withSession(sesionDeAdmin())->post(route('admin.season.close'), [
        'confirmacion' => 'CERRAR', 'esperada' => $season->id, 'forzar' => '1', 'abrir_siguiente' => '0',
    ])->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'pausa'));

    expect(ArenaSeason::current())->toBeNull()
        ->and(Competition::rankedOpen())->toBeFalse();

    // Y desde el panel se puede abrir otra para reactivar el ranking.
    $this->withSession(sesionDeAdmin())->post(route('admin.seasons.open'), ['name' => 'Season 1'])
        ->assertSessionHasNoErrors();

    expect(Competition::rankedOpen())->toBeTrue();
});

it('el calendario guarda si al acabar se abre otra temporada', function () {
    $season = ArenaSeason::current();

    $this->withSession(sesionDeAdmin())->post(route('admin.seasons.update', $season), [
        'name' => 'En juego', 'starts_at' => now()->subWeek()->utc()->format('Y-m-d\TH:i'),
        'ends_at' => '', 'open_next' => '0', 'next_prizes_enabled' => '1',
    ])->assertSessionHasNoErrors();

    expect($season->fresh()->open_next)->toBeFalse();
});

// ------------------------------------------------- hallazgos del arbitro

it('moderacion no puede puntuar ni sancionar un amistoso, solo anularlo', function () {
    [$match, $a, $b] = duelo(false, 'k');
    $servicio = app(\App\Services\ArenaMatchResultService::class);

    expect(fn () => $servicio->forceComplete($match, 'team_a'))->toThrow(RuntimeException::class, 'amistoso')
        ->and(fn () => $servicio->applyAbandonmentWalkover($match, $a->id))->toThrow(RuntimeException::class)
        ->and(fn () => $servicio->applySupportInfraction($match, $a->id))->toThrow(RuntimeException::class);

    expect(MatchResult::query()->count())->toBe(0)
        ->and((float) $a->fresh()->pl_points)->toBe(30.0);

    // Ni siquiera uno ya terminado, que no tiene resultados que "corregir".
    $servicio->finishFriendly($match);
    expect(fn () => $servicio->forceComplete($match->fresh(), 'team_b'))->toThrow(RuntimeException::class);
    expect(MatchResult::query()->count())->toBe(0);

    // Anular si se puede.
    [$otro] = duelo(false, 'l');
    $servicio->markVoid($otro);
    expect($otro->fresh()->status)->toBe('void');
});

it('la pantalla de un amistoso en el panel solo ofrece anular', function () {
    [$match] = duelo(false, 'm');

    $this->withSession(sesionDeAdmin())->get(route('admin.matches.show', $match))
        ->assertOk()
        ->assertDontSee('Cerrar con un resultado')
        ->assertSee('Anular');
});

it('cerrar la temporada sin abrir otra anula los competitivos a medias y no toca los amistosos', function () {
    [$competitivo] = duelo(true, 'n');
    [$amistoso] = duelo(false, 'o');
    $pendiente = tap(duelo(true, 'p')[0])->update(['status' => 'pending_acceptance']);

    $resultado = app(SeasonClosingService::class)->cerrar(null, true, ['abrir_siguiente' => false]);

    expect($resultado['anulados'])->toBe(2)
        ->and($competitivo->fresh()->status)->toBe('void')
        ->and(ArenaMatch::query()->whereKey($pendiente->id)->exists())->toBeFalse()
        ->and($amistoso->fresh()->status)->toBe('in_progress');
});

it('cerrar abriendo la siguiente no toca los combates en curso', function () {
    [$competitivo] = duelo(true, 'q');

    app(SeasonClosingService::class)->cerrar(null, true);

    expect($competitivo->fresh()->status)->toBe('in_progress');
});

it('un tipo manipulado en la URL no rompe el lobby', function () {
    $p = duelista('url', 'ignis');

    $this->actingAs($p->user)->get('/lobby?kind[]=x')->assertOk();
    $this->actingAs($p->user)->get('/lobby?kind=loquesea')->assertOk();
});

it('el contador de completados del panel no cuenta amistosos', function () {
    [$match] = duelo(false, 'r');
    $match->update(['status' => 'completed']);

    $this->withSession(sesionDeAdmin())->get(route('admin.dashboard'))->assertOk();
    expect(ArenaMatch::query()->where('status', 'completed')->where('is_ranked', true)->count())->toBe(0);
});

it('ni el reporte sintetico del laboratorio ni una confirmacion puntuan un amistoso', function () {
    [$match, $a, $b] = duelo(false, 's');
    $servicio = app(\App\Services\ArenaMatchResultService::class);

    expect(fn () => $servicio->submitSyntheticReport($match, $a, 'team_a'))->toThrow(RuntimeException::class);
    expect(fn () => app(\App\Services\Matches\MatchLifecycleService::class)->finalizeMatch($match, 'team_a', true, []))
        ->toThrow(RuntimeException::class, 'amistoso');
    expect(fn () => $servicio->applyAbandonmentPenalty($a, $match))->toThrow(RuntimeException::class);

    expect(MatchResult::query()->count())->toBe(0)
        ->and((float) $a->fresh()->pl_points)->toBe(30.0)
        ->and($a->fresh()->queue_locked_until)->toBeNull();
});

// ------------------------------------------- 2v2, partys y cruces caidos

it('un 2v2 amistoso se arma con cuatro y nace como amistoso', function () {
    AppSetting::setValue(ArenaMode::settingKey('2v2'), '1', 'modes', 'boolean', true);

    foreach (['i1', 'i2'] as $s) { encola(duelista($s, 'ignis'), false, '2v2'); }
    foreach (['a1', 'a2'] as $s) { encola(duelista($s, 'alsius'), false, '2v2'); }

    expect(app(ArenaMatchmakingService::class)->processQueue(false))->toBe(1);

    $match = ArenaMatch::query()->firstOrFail();
    expect($match->isFriendly())->toBeTrue()
        ->and($match->arena_mode)->toBe('2v2')
        ->and($match->player_count)->toBe(4);
});

it('un competitivo y un amistoso de 2v2 no se completan el equipo entre si', function () {
    AppSetting::setValue(ArenaMode::settingKey('2v2'), '1', 'modes', 'boolean', true);

    // Dos ignis, uno en cada tipo: no hay equipo completo en ninguno.
    encola(duelista('x1', 'ignis'), true, '2v2');
    encola(duelista('x2', 'ignis'), false, '2v2');
    encola(duelista('x3', 'alsius'), true, '2v2');
    encola(duelista('x4', 'alsius'), false, '2v2');

    expect(app(ArenaMatchmakingService::class)->processQueue(false))->toBe(0);
});

it('un cruce amistoso que no aceptan todos vuelve a la cola como amistoso', function () {
    [$match, $a, $b] = duelo(false, 't');
    $match->update(['status' => 'pending_acceptance', 'expires_at' => now()->subMinute()]);
    Queue::query()->where('player_id', $a->id)->update(['status' => 'accepted']);
    Queue::query()->where('player_id', $b->id)->update(['status' => 'matched']);

    app(ArenaMatchmakingService::class)->expirePendingAcceptanceMatches(false);

    // El cruce se borra y quien si acepto vuelve a esperar, en SU tipo.
    expect(ArenaMatch::query()->count())->toBe(0);
    $vueltas = Queue::query()->whereIn('status', ['waiting'])->get();
    expect($vueltas->every(fn (Queue $q) => $q->is_ranked === false))->toBeTrue();
});

it('una party amistosa entra a la cola como amistosa y no gasta el cupo diario', function () {
    AppSetting::setValue(ArenaMode::settingKey('2v2'), '1', 'modes', 'boolean', true);
    $lider = duelista('pl', 'ignis');
    $aliado = duelista('pa', 'ignis');

    $this->actingAs($lider->user)->post(route('party.create'), [
        'arena_mode' => '2v2', 'kind' => 'friendly', 'party_player_ids' => [$lider->id, $aliado->id],
        'party_conjurer_roles' => [null, null],
    ])->assertSessionHasNoErrors();

    $party = \App\Models\Party::query()->firstOrFail();
    expect((bool) $party->is_ranked)->toBeFalse();

    $miembro = \App\Models\PartyMember::query()->where('party_id', $party->id)->where('player_id', $aliado->id)->firstOrFail();
    $this->actingAs($aliado->user)->post(route('party.accept', [$party, $miembro]));

    $this->actingAs($lider->user)->post(route('party.enqueue', $party->fresh()))->assertSessionHasNoErrors();

    $colas = Queue::query()->where('queue_type', 'premade')->get();
    expect($colas)->toHaveCount(2)
        ->and($colas->every(fn (Queue $q) => $q->is_ranked === false))->toBeTrue();
});

it('un miembro de una party no puede buscar en solitario con otra modalidad o tipo', function () {
    AppSetting::setValue(ArenaMode::settingKey('2v2'), '1', 'modes', 'boolean', true);
    $lider = duelista('ml', 'ignis');
    $aliado = duelista('ma', 'ignis');

    $this->actingAs($lider->user)->post(route('party.create'), [
        'arena_mode' => '2v2', 'kind' => 'friendly', 'party_player_ids' => [$lider->id, $aliado->id],
        'party_conjurer_roles' => [null, null],
    ])->assertSessionHasNoErrors();

    $party = \App\Models\Party::query()->firstOrFail();
    $miembro = \App\Models\PartyMember::query()->where('party_id', $party->id)->where('player_id', $aliado->id)->firstOrFail();
    $this->actingAs($aliado->user)->post(route('party.accept', [$party, $miembro]));

    // Desde otra pestaña o una peticion directa: la party manda.
    $this->actingAs($aliado->user)->post(route('queue.join'), [
        'player_id' => $aliado->id, 'queue_type' => 'random', 'arena_mode' => '1v1', 'kind' => 'ranked',
    ])->assertSessionHasErrors('error');

    expect(Queue::query()->where('player_id', $aliado->id)->count())->toBe(0);

    // Y el lider sigue pudiendo meter a la party con su modalidad y su tipo.
    $this->actingAs($lider->user)->post(route('party.enqueue', $party->fresh()))->assertSessionHasNoErrors();
    expect(Queue::query()->where('queue_type', 'premade')->count())->toBe(2);
});

it('una party competitiva no puede buscar partida con el ladder en pausa', function () {
    AppSetting::setValue(ArenaMode::settingKey('2v2'), '1', 'modes', 'boolean', true);
    $lider = duelista('qa', 'ignis');
    $aliado = duelista('qb', 'ignis');

    $this->actingAs($lider->user)->post(route('party.create'), [
        'arena_mode' => '2v2', 'kind' => 'ranked', 'party_player_ids' => [$lider->id, $aliado->id],
        'party_conjurer_roles' => [null, null],
    ]);
    $party = \App\Models\Party::query()->firstOrFail();
    $miembro = \App\Models\PartyMember::query()->where('party_id', $party->id)->where('player_id', $aliado->id)->firstOrFail();
    $this->actingAs($aliado->user)->post(route('party.accept', [$party, $miembro]));

    ArenaSeason::query()->update(['status' => ArenaSeason::STATUS_ARCHIVED]);

    $this->actingAs($lider->user)->post(route('party.enqueue', $party->fresh()))->assertSessionHasErrors('error');
    expect(Queue::query()->count())->toBe(0);
});

it('el chat del combate trae las frases nuevas con su redaccion', function () {
    $textos = collect(\App\Models\MatchPing::CATALOGO)->pluck('texto', null)->all();

    expect($textos)->toContain('Voy en camino', 'Ok', 'Bien jugado', 'Me atacó un tercero', 'Me están atacando')
        ->and($textos)->not->toContain('Voy de camino')
        ->and(\App\Models\MatchPing::esUnCodigo('ok'))->toBeTrue();
});

it('invitar a una party avisa por push al invitado, no al lider', function () {
    AppSetting::setValue(ArenaMode::settingKey('2v2'), '1', 'modes', 'boolean', true);
    $lider = duelista('pushl', 'ignis');
    $aliado = duelista('pusha', 'ignis');

    $avisados = null;
    $falso = Mockery::mock(\App\Services\WebPushService::class);
    $falso->shouldReceive('avisarAJugadores')->andReturnUsing(function ($ids) use (&$avisados) {
        $avisados = collect($ids)->map(fn ($i) => (int) $i)->values()->all();
    });
    app()->instance(\App\Services\WebPushService::class, $falso);

    $this->actingAs($lider->user)->post(route('party.create'), [
        'arena_mode' => '2v2', 'kind' => 'friendly', 'party_player_ids' => [$lider->id, $aliado->id],
        'party_conjurer_roles' => [null, null],
    ])->assertSessionHasNoErrors();

    expect($avisados)->toBe([$aliado->id]);
});
