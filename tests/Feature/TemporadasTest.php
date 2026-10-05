<?php

use App\Models\AppSetting;
use App\Models\ArenaSeason;
use App\Models\Player;
use App\Models\SeasonPlayerStat;
use App\Models\User;
use App\Services\ArenaMaintenanceService;
use App\Services\SeasonScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * El calendario de temporadas: la barra, el cierre automatico y la pantalla del
 * admin.
 *
 * La zona de las temporadas en los tests es America/Bogota (UTC-5, sin horario
 * de verano): "29 nov 23:59" es 30 nov 04:59 UTC.
 */
beforeEach(function () {
    // La instalacion deja una temporada abierta (con las fechas de la Season 0
    // si las migraciones corrieron antes de que se aplicara la fecha). Cada
    // test crea la suya.
    ArenaSeason::query()->delete();
    config(['arena.season_timezone' => 'America/Bogota']);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Un instante de hora de Bogota, ya en UTC. Con setTestNow en otra zona, Carbon
 * lee los valores de la base de datos (que son UTC) en esa zona, y el test
 * mediria otra cosa que el servidor.
 */
function enBogota(int $a, int $m, int $d, int $h = 0, int $i = 0, int $s = 0): Carbon
{
    return Carbon::create($a, $m, $d, $h, $i, $s, 'America/Bogota')->utc();
}

function temporada(array $extra = []): ArenaSeason
{
    return ArenaSeason::create(array_merge([
        'name' => 'Season 0',
        'slug' => 'season-0-' . uniqid(),
        'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['1v1', '2v2'],
        // 8 abr 00:00 -> 29 nov 23:59, hora de Bogota.
        'starts_at' => Carbon::create(2026, 4, 8, 0, 0, 0, 'America/Bogota')->utc(),
        'ends_at' => Carbon::create(2026, 11, 29, 23, 59, 0, 'America/Bogota')->utc(),
        'auto_close' => true,
    ], $extra));
}

function personajeDeTemporada(string $s, float $pl): Player
{
    $user = User::create([
        'discord_id' => 'tp-' . $s, 'discord_username' => 'tp_' . $s,
        'name' => 'Tp ' . $s, 'email' => 'tp-' . $s . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id, 'character_name' => 'Tp' . ucfirst($s), 'subclass' => 'hunter',
        'realm' => 'ignis', 'pl_points' => $pl, 'mmr' => 1000, 'trust_score' => 100,
        'matches_played' => 5, 'wins' => 3, 'losses' => 2, 'is_active' => true,
    ]);
}

function cruceDeTemporada(ArenaSeason $season): void
{
    DB::table('matches')->insert([
        'match_code' => 'TP' . $season->id . substr((string) microtime(true), -5),
        'report_token' => bin2hex(random_bytes(10)),
        'arena_mode' => '2v2', 'season_id' => $season->id, 'status' => 'completed',
        'zone' => 'central_ruins', 'team_a' => json_encode([]), 'team_b' => json_encode([]),
        'team_a_realm' => 'ignis', 'team_b_realm' => 'alsius',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ------------------------------------------------------------------ progreso

it('mide los dias entre el 8 de abril y el 29 de noviembre', function () {
    $season = temporada();

    // A mitad de camino exacta del intervalo.
    $mitad = $season->starts_at->copy()->addSeconds(
        (int) (($season->ends_at->getTimestamp() - $season->starts_at->getTimestamp()) / 2)
    );

    $p = $season->progreso($mitad);

    expect($p['estado'])->toBe('en_curso')
        ->and($p['porcentaje'])->toBe(50.0)
        ->and($p['dias'])->toBe(236)       // 8 abr + 235 dias = 29 nov, y el 8 cuenta
        ->and($p['dia'])->toBeBetween(118, 119);
});

it('antes de empezar la barra esta vacia y despues de acabar, llena', function () {
    $season = temporada();

    $antes = $season->progreso(enBogota(2026, 3, 1, 12, 0, 0));
    expect($antes['estado'])->toBe('pendiente')
        ->and($antes['porcentaje'])->toBe(0.0)
        ->and($antes['dia'])->toBe(0)
        ->and($antes['restante'])->toContain('Empieza el 8 de abril de 2026');

    $despues = $season->progreso(enBogota(2026, 12, 5, 12, 0, 0));
    expect($despues['estado'])->toBe('terminada')
        ->and($despues['porcentaje'])->toBe(100.0)
        ->and($despues['dia'])->toBe($despues['dias'])
        ->and($despues['restante'])->toBe('Temporada terminada');
});

it('el primer dia es el dia 1 y el ultimo cuenta entero', function () {
    $season = temporada();

    expect($season->progreso(enBogota(2026, 4, 8, 9, 0, 0))['dia'])->toBe(1)
        ->and($season->progreso(enBogota(2026, 4, 9, 0, 1, 0))['dia'])->toBe(2)
        ->and($season->progreso(enBogota(2026, 11, 29, 20, 0, 0))['dia'])->toBe(236);
});

it('la cuenta atras habla en dias, horas y minutos segun lo cerca que este el final', function () {
    $season = temporada();
    $texto = fn (Carbon $ahora) => $season->progreso($ahora)['restante'];

    expect($texto(enBogota(2026, 11, 20, 0, 0, 0)))->toBe('Quedan 10 días')
        ->and($texto(enBogota(2026, 11, 29, 12, 0, 0)))->toBe('Quedan 12 horas')
        ->and($texto(enBogota(2026, 11, 29, 23, 30, 0)))->toBe('Quedan 29 minutos')
        ->and($texto(enBogota(2026, 11, 29, 23, 58, 30)))->toBe('Queda 1 minuto');
});

it('sin fecha de fin, o con una que no cuadra, no hay barra', function () {
    expect(temporada(['ends_at' => null])->progreso())->toBeNull();

    $revuelta = temporada([
        'slug' => 'otra',
        'ends_at' => Carbon::create(2026, 1, 1, 0, 0, 0, 'America/Bogota')->utc(),
    ]);
    expect($revuelta->progreso())->toBeNull();
});

it('marca el primer dia de cada mes dentro de la temporada', function () {
    $hitos = temporada()->progreso()['hitos'];

    // Mayo, junio, julio, agosto, septiembre, octubre y noviembre.
    expect(collect($hitos)->pluck('mes')->all())->toBe(['May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov'])
        ->and(collect($hitos)->every(fn ($h) => $h['pos'] > 0 && $h['pos'] < 100))->toBeTrue()
        ->and(collect($hitos)->pluck('pos')->all())->toBe(collect($hitos)->pluck('pos')->sort()->values()->all());
});

it('las fechas se leen en la zona de las temporadas, no en UTC', function () {
    $p = temporada()->progreso(enBogota(2026, 6, 1, 0, 0, 0));

    expect($p['inicio']->format('Y-m-d H:i'))->toBe('2026-04-08 00:00')
        ->and($p['fin']->format('Y-m-d H:i'))->toBe('2026-11-29 23:59');
});

// ----------------------------------------------------------------- la barra

it('el podio de la portada lleva la barra de la temporada', function () {
    temporada();
    Carbon::setTestNow(enBogota(2026, 10, 5, 12, 0, 0));

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('arena-season', false)
        ->assertSee('role="progressbar"', false)
        ->assertSee('Season 0')
        ->assertSee('8 abr 2026')
        ->assertSee('29 nov 2026')
        ->assertSee('Quedan 56 días');
});

it('sin calendario el podio sale igual y sin barra', function () {
    temporada(['ends_at' => null, 'auto_close' => false]);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('arena-season', false);
});

it('la barra sale tambien en el ladder y en el salon de la fama', function () {
    temporada();

    $this->get(route('ladder.index'))->assertOk()->assertSee('arena-season-track', false);
    $this->get(route('hall-of-fame'))->assertOk()->assertSee('arena-season-track', false);
});

it('el salon enseña la barra aunque no haya premios', function () {
    temporada();
    AppSetting::setValue('season_prizes_enabled', '0', 'branding', 'boolean', true);

    $this->get(route('hall-of-fame'))
        ->assertOk()
        ->assertSee('En juego ahora')
        ->assertSee('arena-season-track', false)
        ->assertDontSee('lingotes de Magnanita');
});

// ----------------------------------------------------------- cierre automatico

it('cierra sola la temporada cuando llega su fecha y congela el podio', function () {
    $season = temporada();
    $campeon = personajeDeTemporada('a', 500);
    personajeDeTemporada('b', 300);

    // Un minuto antes: nada.
    Carbon::setTestNow(enBogota(2026, 11, 29, 23, 58, 0));
    expect(app(SeasonScheduleService::class)->aplicar()['cerrada'])->toBeFalse()
        ->and($season->fresh()->status)->toBe(ArenaSeason::STATUS_ACTIVE);

    // En punto.
    Carbon::setTestNow(enBogota(2026, 11, 30, 0, 0, 5));
    $resultado = app(SeasonScheduleService::class)->aplicar();

    expect($resultado['cerrada'])->toBeTrue()
        ->and($resultado['congelados'])->toBe(2);

    $cerrada = $season->fresh();
    expect($cerrada->status)->toBe(ArenaSeason::STATUS_ARCHIVED)
        ->and($cerrada->closed_reason)->toBe('auto')
        // Acabo el dia previsto, no el minuto en que el cron se dio cuenta.
        ->and($cerrada->ends_at->equalTo(enBogota(2026, 11, 29, 23, 59, 0)))->toBeTrue();

    expect((float) SeasonPlayerStat::query()->where('season_id', $season->id)->where('player_id', $campeon->id)->value('pl_points'))
        ->toBe(500.0);
});

it('el segundo tick no cierra tambien la temporada que acaba de nacer', function () {
    $season = temporada(['next_duration_days' => null]);
    personajeDeTemporada('c', 100);
    Carbon::setTestNow(enBogota(2026, 12, 1, 0, 0, 0));

    $reloj = app(SeasonScheduleService::class);
    $reloj->aplicar();
    $segundo = $reloj->aplicar();

    expect($segundo['cerrada'])->toBeFalse()
        ->and(ArenaSeason::query()->where('status', ArenaSeason::STATUS_ARCHIVED)->count())->toBe(1)
        ->and(ArenaSeason::query()->where('status', ArenaSeason::STATUS_ACTIVE)->count())->toBe(1);
});

it('si otro tick ya la cerro, cerrar con la temporada esperada no toca la nueva', function () {
    $vieja = temporada();
    personajeDeTemporada('d', 100);
    Carbon::setTestNow(enBogota(2026, 12, 1, 0, 0, 0));

    app(SeasonScheduleService::class)->aplicar();
    $nueva = ArenaSeason::current();

    // El tick lento llega tarde con el id de la vieja.
    $resultado = app(\App\Services\SeasonClosingService::class)->cerrar(null, true, ['esperada' => $vieja->id]);

    expect($resultado['ok'])->toBeFalse()
        ->and($nueva->fresh()->status)->toBe(ArenaSeason::STATUS_ACTIVE);
});

it('no se cierra sola si no esta marcada o si no tiene fecha', function () {
    temporada(['auto_close' => false]);
    Carbon::setTestNow(enBogota(2027, 1, 1, 0, 0, 0));
    expect(app(SeasonScheduleService::class)->aplicar()['cerrada'])->toBeFalse();

    ArenaSeason::query()->delete();
    temporada(['ends_at' => null, 'auto_close' => true]);
    expect(app(SeasonScheduleService::class)->aplicar()['cerrada'])->toBeFalse();
});

it('al cerrarse sola abre la siguiente con el nombre y la duracion que traia configurados', function () {
    $season = temporada(['next_name' => 'Season 1', 'next_duration_days' => 60, 'next_prizes_enabled' => true]);
    personajeDeTemporada('e', 100);
    Carbon::setTestNow(enBogota(2026, 12, 1, 0, 0, 0));

    $siguiente = app(SeasonScheduleService::class)->aplicar()['siguiente'];

    expect($siguiente->name)->toBe('Season 1')
        ->and($siguiente->status)->toBe(ArenaSeason::STATUS_ACTIVE)
        ->and($siguiente->auto_close)->toBeTrue()
        ->and($siguiente->ends_at->equalTo(now()->addDays(60)))->toBeTrue()
        // Lo demas se hereda, el nombre no: era para esta vez.
        ->and($siguiente->next_duration_days)->toBe(60)
        ->and($siguiente->next_name)->toBeNull();
});

it('sin duracion la siguiente queda abierta sin fecha de fin y sin barra', function () {
    temporada(['next_name' => 'Season 1', 'next_duration_days' => null]);
    personajeDeTemporada('f', 100);
    Carbon::setTestNow(enBogota(2026, 12, 1, 0, 0, 0));

    $siguiente = app(SeasonScheduleService::class)->aplicar()['siguiente'];

    expect($siguiente->ends_at)->toBeNull()
        ->and($siguiente->auto_close)->toBeFalse()
        ->and($siguiente->progreso())->toBeNull();
});

it('puede apagar los premios al cerrarse: la Season 1 no reparte los de la 0', function () {
    temporada(['next_prizes_enabled' => false]);
    personajeDeTemporada('g', 100);
    Carbon::setTestNow(enBogota(2026, 12, 1, 0, 0, 0));

    app(SeasonScheduleService::class)->aplicar();
    AppSetting::flushSettingsCache();

    $cerrada = ArenaSeason::query()->where('status', ArenaSeason::STATUS_ARCHIVED)->first();

    // Lo repartido se queda con la temporada cerrada...
    expect($cerrada->reparto())->toBe([1 => 10, 2 => 5, 3 => 2])
        // ...y la portada ya no los anuncia.
        ->and(app(\App\Services\SeasonPrizeService::class)->activos())->toBeFalse();

    $this->get(route('home'))->assertOk()->assertDontSee('lingotes de Magnanita');
});

it('puede reiniciar el ranking despues de congelar el podio', function () {
    $season = temporada(['reset_on_close' => true]);
    $campeon = personajeDeTemporada('h', 500);
    cruceDeTemporada($season);
    Carbon::setTestNow(enBogota(2026, 12, 1, 0, 0, 0));

    app(SeasonScheduleService::class)->aplicar();

    // La vitrina guardo los 500; el ranking vivo quedo a cero.
    expect((float) SeasonPlayerStat::query()->where('season_id', $season->id)->where('player_id', $campeon->id)->value('pl_points'))->toBe(500.0)
        ->and((float) $campeon->fresh()->pl_points)->toBe(0.0)
        ->and(DB::table('matches')->count())->toBe(0);
});

it('sin reiniciar, el ranking vivo no se toca', function () {
    $season = temporada(['reset_on_close' => false]);
    $campeon = personajeDeTemporada('i', 500);
    cruceDeTemporada($season);
    Carbon::setTestNow(enBogota(2026, 12, 1, 0, 0, 0));

    app(SeasonScheduleService::class)->aplicar();

    expect((float) $campeon->fresh()->pl_points)->toBe(500.0)
        ->and(DB::table('matches')->count())->toBe(1);
});

it('el mantenimiento periodico cierra la temporada vencida', function () {
    $season = temporada();
    personajeDeTemporada('j', 100);
    Carbon::setTestNow(enBogota(2026, 12, 1, 0, 0, 0));

    $tick = app(ArenaMaintenanceService::class)->runTick(false);

    expect($tick['season_closed'])->toBeTrue()
        ->and($season->fresh()->status)->toBe(ArenaSeason::STATUS_ARCHIVED);
});

it('un fallo del calendario no tumba el mantenimiento', function () {
    $this->mock(SeasonScheduleService::class, fn ($m) => $m->shouldReceive('aplicar')->andThrow(new RuntimeException('boom')));

    $tick = app(ArenaMaintenanceService::class)->runTick(false);

    expect($tick['skipped'])->toBeFalse()
        ->and($tick['season_closed'])->toBeFalse();
});

it('el salon de la fama ordena por el dia en que acabo cada temporada', function () {
    temporada(['name' => 'Season 0']);
    personajeDeTemporada('k', 100);
    Carbon::setTestNow(enBogota(2026, 12, 1, 0, 0, 0));
    app(SeasonScheduleService::class)->aplicar();

    $this->get(route('hall-of-fame'))->assertOk()->assertSee('Season 0');
});

it('el comando enseña el calendario y cierra la vencida si se le pide', function () {
    $season = temporada();
    personajeDeTemporada('l', 100);
    Carbon::setTestNow(enBogota(2026, 12, 1, 0, 0, 0));

    $this->artisan('arena:temporada')
        ->expectsOutputToContain('Season 0')
        ->expectsOutputToContain('ya vencio')
        ->assertSuccessful();

    expect($season->fresh()->status)->toBe(ArenaSeason::STATUS_ACTIVE);

    $this->artisan('arena:temporada --cerrar-vencida')->assertSuccessful();

    expect($season->fresh()->status)->toBe(ArenaSeason::STATUS_ARCHIVED);
});

// -------------------------------------------------------------- migracion

it('la migracion pone las fechas de la Season 0 solo si la abierta no tiene fin', function () {
    $migracion = require database_path('migrations/2026_10_05_000002_set_season_zero_calendar.php');

    $sinFecha = temporada(['starts_at' => now()->subMonths(2), 'ends_at' => null, 'auto_close' => false]);
    $migracion->up();

    $sinFecha->refresh();
    expect($sinFecha->starts_at->equalTo(enBogota(2026, 4, 8, 0, 0, 0)))->toBeTrue()
        ->and($sinFecha->ends_at->equalTo(enBogota(2026, 11, 29, 23, 59, 0)))->toBeTrue()
        ->and($sinFecha->auto_close)->toBeTrue()
        ->and($sinFecha->next_name)->toBe('Season 1')
        ->and($sinFecha->next_prizes_enabled)->toBeFalse();

    // Ya configurada por el admin: no se pisa.
    $propia = Carbon::create(2027, 3, 1, 0, 0, 0, 'America/Bogota')->utc();
    $sinFecha->update(['ends_at' => $propia, 'auto_close' => false]);
    $migracion->up();

    expect($sinFecha->fresh()->ends_at->equalTo($propia))->toBeTrue()
        ->and($sinFecha->fresh()->auto_close)->toBeFalse();
});

// -------------------------------------------------------------------- admin

it('la pantalla de temporadas es solo para el admin', function () {
    temporada();

    $this->get(route('admin.seasons'))->assertRedirect();

    $this->withSession(sesionDeAdmin())
        ->get(route('admin.seasons'))
        ->assertOk()
        ->assertSee('Season 0')
        ->assertSee('Cierre automatico')
        ->assertSee('Cerrar ahora');
});

it('la pantalla abre tambien sin temporada abierta y deja abrir una', function () {
    $sesion = sesionDeAdmin();

    $this->withSession($sesion)->get(route('admin.seasons'))
        ->assertOk()->assertSee('No hay ninguna temporada abierta');

    $this->withSession($sesion)->post(route('admin.seasons.open'), [
        'name' => 'Season 1',
        'starts_at' => '2026-12-01T00:00',
        'ends_at' => '2027-01-31T23:59',
        'auto_close' => '1',
    ])->assertSessionHasNoErrors();

    $nueva = ArenaSeason::current();
    expect($nueva->name)->toBe('Season 1')
        ->and($nueva->auto_close)->toBeTrue()
        ->and($nueva->ends_at->equalTo(enBogota(2027, 1, 31, 23, 59, 0)))->toBeTrue();
});

it('no deja abrir otra temporada si ya hay una abierta', function () {
    temporada();

    $this->withSession(sesionDeAdmin())
        ->post(route('admin.seasons.open'), ['name' => 'Otra'])
        ->assertSessionHasErrors('error');

    expect(ArenaSeason::query()->where('status', ArenaSeason::STATUS_ACTIVE)->count())->toBe(1);
});

it('el admin edita el calendario en hora de Bogota y se guarda en UTC', function () {
    $season = temporada(['ends_at' => null, 'auto_close' => false]);
    Carbon::setTestNow(enBogota(2026, 10, 5, 12, 0, 0));

    $this->withSession(sesionDeAdmin())->post(route('admin.seasons.update', $season), [
        'name' => 'Alpha Season 0',
        'starts_at' => '2026-04-08T00:00',
        'ends_at' => '2026-11-29T23:59',
        'auto_close' => '1',
        'next_name' => 'Season 1',
        'next_duration_days' => '',
        'reset_on_close' => '0',
        'next_prizes_enabled' => '0',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $season->refresh();
    expect($season->name)->toBe('Alpha Season 0')
        ->and($season->ends_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-30 04:59')
        ->and($season->auto_close)->toBeTrue()
        ->and($season->next_name)->toBe('Season 1')
        ->and($season->next_duration_days)->toBeNull()
        ->and($season->next_prizes_enabled)->toBeFalse();
});

it('el calendario no admite un fin anterior al inicio ni cierre automatico sin fecha', function () {
    $season = temporada();
    $sesion = sesionDeAdmin();
    Carbon::setTestNow(enBogota(2026, 10, 5, 12, 0, 0));

    $this->withSession($sesion)->post(route('admin.seasons.update', $season), [
        'name' => 'X', 'starts_at' => '2026-05-01T00:00', 'ends_at' => '2026-04-01T00:00',
    ])->assertSessionHasErrors('ends_at');

    $this->withSession($sesion)->post(route('admin.seasons.update', $season), [
        'name' => 'X', 'starts_at' => '2026-05-01T00:00', 'ends_at' => '', 'auto_close' => '1',
    ])->assertSessionHasErrors('auto_close');

    // Una fecha pasada con cierre automatico la cerraria en el proximo minuto.
    $this->withSession($sesion)->post(route('admin.seasons.update', $season), [
        'name' => 'X', 'starts_at' => '2026-04-01T00:00', 'ends_at' => '2026-05-01T00:00', 'auto_close' => '1',
    ])->assertSessionHasErrors('ends_at');

    expect($season->fresh()->name)->toBe('Season 0');
});

it('una temporada cerrada no se edita', function () {
    $cerrada = temporada(['status' => ArenaSeason::STATUS_ARCHIVED, 'auto_close' => false]);

    $this->withSession(sesionDeAdmin())->post(route('admin.seasons.update', $cerrada), [
        'name' => 'Cambiada', 'starts_at' => '2026-04-08T00:00',
    ])->assertSessionHasErrors('error');

    expect($cerrada->fresh()->name)->toBe('Season 0');
});

it('cerrar a mano acepta las opciones del formulario', function () {
    $season = temporada();
    $campeon = personajeDeTemporada('m', 500);
    cruceDeTemporada($season);

    $this->withSession(sesionDeAdmin())->post(route('admin.season.close'), [
        'confirmacion' => 'CERRAR',
        'esperada' => $season->id,
        'siguiente' => 'Season 1',
        'duracion_dias' => '30',
        'resetear' => '1',
        'premios_siguiente' => '0',
        'forzar' => '0',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $nueva = ArenaSeason::current();
    expect($season->fresh()->closed_reason)->toBe('manual')
        ->and($nueva->name)->toBe('Season 1')
        ->and($nueva->auto_close)->toBeTrue()
        ->and((float) $campeon->fresh()->pl_points)->toBe(0.0);
});

it('cerrar a mano una temporada que ya no es la abierta no cierra la nueva', function () {
    $vieja = temporada();
    personajeDeTemporada('n', 100);

    // Dos pestañas abiertas: la primera cierra...
    $this->withSession(sesionDeAdmin())->post(route('admin.season.close'), [
        'confirmacion' => 'CERRAR', 'esperada' => $vieja->id, 'forzar' => '1',
    ])->assertSessionHasNoErrors();

    $nueva = ArenaSeason::current();

    // ...y la segunda, con el id de la vieja, no puede llevarse la nueva.
    $this->withSession(sesionDeAdmin())->post(route('admin.season.close'), [
        'confirmacion' => 'CERRAR', 'esperada' => $vieja->id, 'forzar' => '1',
    ])->assertSessionHasErrors('error');

    expect($nueva->fresh()->status)->toBe(ArenaSeason::STATUS_ACTIVE);
});
