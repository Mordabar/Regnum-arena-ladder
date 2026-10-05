<?php

use App\Models\AppSetting;
use App\Models\ArenaSeason;
use App\Services\SeasonClosingService;
use App\Services\SeasonScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * La temporada programada: se abre sola en su fecha, aunque el servidor haya
 * estado caido, y sin pisar a la que este abierta.
 */
function casi($a, $b): bool
{
    return abs($a->diffInSeconds($b, false)) <= 2;
}

beforeEach(function () {
    ArenaSeason::query()->delete();
    config(['arena.season_timezone' => 'America/Bogota']);
});

afterEach(fn () => Carbon::setTestNow());

it('programa una temporada futura sin abrirla', function () {
    $r = app(SeasonClosingService::class)->programar('Season 1', now()->addDays(10), ['fin' => now()->addDays(40), 'premios' => false]);

    expect($r['ok'])->toBeTrue()
        ->and($r['season']->status)->toBe(ArenaSeason::STATUS_SCHEDULED)
        ->and(casi($r['season']->ends_at, now()->addDays(40)))->toBeTrue()
        ->and($r['season']->auto_close)->toBeTrue()
        ->and(ArenaSeason::current())->toBeNull()
        ->and(ArenaSeason::programada()?->name)->toBe('Season 1');
});

it('rechaza una fecha pasada y una segunda programada', function () {
    $svc = app(SeasonClosingService::class);

    expect($svc->programar('X', now()->subDay())['ok'])->toBeFalse();
    expect($svc->programar('A', now()->addDay())['ok'])->toBeTrue();
    expect($svc->programar('B', now()->addDays(2))['ok'])->toBeFalse();
});

it('se abre sola al llegar la fecha', function () {
    app(SeasonClosingService::class)->programar('Season 1', now()->addDays(2), ['fin' => now()->addDays(12), 'premios' => false]);

    Carbon::setTestNow(now()->addDays(1));
    expect(app(SeasonScheduleService::class)->aplicar()['abierta'] ?? null)->toBeNull();
    expect(ArenaSeason::current())->toBeNull();

    Carbon::setTestNow(now()->addDays(2));
    app(SeasonScheduleService::class)->aplicar();

    $actual = ArenaSeason::current();
    expect($actual?->name)->toBe('Season 1')
        ->and(ArenaSeason::programada())->toBeNull()
        ->and((string) AppSetting::getValue('season_prizes_enabled', '1'))->toBeIn(['0', false, '']);
});

it('si la fecha ya paso y el servidor estaba caido, abre al volver', function () {
    app(SeasonClosingService::class)->programar('Season 1', now()->addDay());

    Carbon::setTestNow(now()->addDays(40));
    app(SeasonScheduleService::class)->aplicar();

    expect(ArenaSeason::current()?->name)->toBe('Season 1');
});

it('no pisa a la temporada abierta: espera a que se cierre', function () {
    ArenaSeason::create(['name' => 'Season 0', 'slug' => 's0', 'status' => ArenaSeason::STATUS_ACTIVE, 'enabled_modes' => ['1v1'], 'starts_at' => now()->subDays(5)]);
    app(SeasonClosingService::class)->programar('Season 1', now()->addDay());

    Carbon::setTestNow(now()->addDays(3));
    app(SeasonScheduleService::class)->aplicar();

    expect(ArenaSeason::current()?->name)->toBe('Season 0')
        ->and(ArenaSeason::programada()?->name)->toBe('Season 1');
});

it('al cerrarse la vencida sin siguiente, abre la programada', function () {
    ArenaSeason::create([
        'name' => 'Season 0', 'slug' => 's0', 'status' => ArenaSeason::STATUS_ACTIVE, 'enabled_modes' => ['1v1'],
        'starts_at' => now()->subDays(20), 'ends_at' => now()->addDays(1), 'auto_close' => true, 'open_next' => false,
    ]);
    app(SeasonClosingService::class)->programar('Season 1', now()->addDays(1)->addHour());

    Carbon::setTestNow(now()->addDays(2));
    $r = app(SeasonScheduleService::class)->aplicar();

    expect($r['cerrada'])->toBeTrue()
        ->and(ArenaSeason::current()?->name)->toBe('Season 1');
});

it('el banner de pausa menciona la proxima temporada solo si hay una programada', function () {
    $this->get('/')->assertDontSee('Próxima temporada');

    app(SeasonClosingService::class)->programar('Season 9', now()->addDays(5));

    $this->get('/')->assertSee('Próxima temporada: Season 9', false);
});

it('el panel programa y cancela', function () {
    $sesion = sesionDeAdmin();

    $this->withSession($sesion)->get(route('admin.seasons'))
        ->assertOk()->assertSee('Programar una temporada');

    $this->withSession($sesion)->post(route('admin.seasons.schedule'), [
        'name' => 'Season 1',
        'starts_at' => now()->addDays(20)->setTimezone('America/Bogota')->format('Y-m-d\\TH:i'),
        'ends_at' => now()->addDays(65)->setTimezone('America/Bogota')->format('Y-m-d\\TH:i'),
        'prizes' => '1',
    ])->assertSessionHasNoErrors();

    $programada = ArenaSeason::programada();
    expect($programada?->name)->toBe('Season 1')->and($programada->ends_at)->not->toBeNull();

    $this->withSession($sesion)->get(route('admin.seasons'))
        ->assertOk()->assertSee('Temporada programada')->assertSee('Cancelar programación');

    $this->withSession($sesion)->delete(route('admin.seasons.schedule.cancel', $programada))
        ->assertSessionHasNoErrors();

    expect(ArenaSeason::programada())->toBeNull();

    $this->withSession($sesion)->post(route('admin.seasons.schedule'), [
        'name' => 'Pasada', 'starts_at' => '2020-01-01T00:00',
    ])->assertSessionHasErrors('error');
});

it('al cerrarse una temporada con una programada no se abre otra generica', function () {
    ArenaSeason::create([
        'name' => 'Season 0', 'slug' => 's0', 'status' => ArenaSeason::STATUS_ACTIVE, 'enabled_modes' => ['1v1'],
        'starts_at' => now()->subDays(20), 'ends_at' => now()->addDay(), 'auto_close' => true, 'open_next' => true,
    ]);
    app(SeasonClosingService::class)->programar('Season 1', now()->addDays(10), ['fin' => now()->addDays(40)]);

    Carbon::setTestNow(now()->addDays(2));
    app(SeasonScheduleService::class)->aplicar();

    expect(ArenaSeason::current())->toBeNull()
        ->and(ArenaSeason::programada()?->name)->toBe('Season 1');

    Carbon::setTestNow(now()->addDays(9));
    app(SeasonScheduleService::class)->aplicar();

    expect(ArenaSeason::current()?->name)->toBe('Season 1');
});

it('una programada que abre tarde conserva sus fechas; si su fin ya paso, abre sin fin', function () {
    $inicio = now()->addDay();
    app(SeasonClosingService::class)->programar('Season 1', $inicio, ['fin' => now()->addDays(31)]);

    // Cae a mitad de su calendario: abre con las fechas originales.
    Carbon::setTestNow(now()->addDays(10));
    app(SeasonScheduleService::class)->aplicar();
    $actual = ArenaSeason::current();

    expect($actual?->name)->toBe('Season 1')
        ->and($actual->vencida())->toBeFalse()
        ->and(casi($actual->ends_at, Carbon::now()->subDays(10)->addDays(31)))->toBeTrue();

    // Si ni siquiera se llego a abrir antes de su fin, no nace ya vencida.
    ArenaSeason::query()->delete();
    Carbon::setTestNow();
    app(SeasonClosingService::class)->programar('Season 2', now()->addDay(), ['fin' => now()->addDays(5)]);

    Carbon::setTestNow(now()->addDays(60));
    app(SeasonScheduleService::class)->aplicar();
    $tarde = ArenaSeason::current();

    expect($tarde?->name)->toBe('Season 2')->and($tarde->ends_at)->toBeNull()->and($tarde->auto_close)->toBeFalse();
});

it('cancelar no borra una temporada que ya se abrio', function () {
    $p = app(SeasonClosingService::class)->programar('Season 1', now()->addDay())['season'];

    Carbon::setTestNow(now()->addDays(2));
    app(SeasonScheduleService::class)->aplicar();

    $this->withSession(sesionDeAdmin())
        ->delete(route('admin.seasons.schedule.cancel', $p))
        ->assertSessionHasErrors('error');

    expect(ArenaSeason::query()->whereKey($p->id)->value('status'))->toBe(ArenaSeason::STATUS_ACTIVE);
});

it('la programada se edita antes de abrir y no despues', function () {
    $p = app(SeasonClosingService::class)->programar('Season 1', now()->addDays(10), ['fin' => now()->addDays(40)])['season'];
    $sesion = sesionDeAdmin();

    $nuevoInicio = now()->addDays(20)->setTimezone('America/Bogota')->format('Y-m-d\\TH:i');

    $this->withSession($sesion)->put(route('admin.seasons.schedule.update', $p), [
        'name' => 'Season 1B', 'starts_at' => $nuevoInicio, 'ends_at' => now()->addDays(70)->setTimezone('America/Bogota')->format('Y-m-d\\TH:i'), 'prizes' => '0', 'reset_on_close' => '1',
    ])->assertSessionHasNoErrors();

    $p->refresh();
    expect($p->name)->toBe('Season 1B')
        ->and($p->ends_at->greaterThan($p->starts_at))->toBeTrue()
        ->and($p->prizes_on_open)->toBeFalse()
        ->and($p->reset_on_close)->toBeTrue();

    // Una fecha pasada se rechaza y no cambia nada.
    $this->withSession($sesion)->put(route('admin.seasons.schedule.update', $p), [
        'name' => 'X', 'starts_at' => '2020-01-01T00:00',
    ])->assertSessionHasErrors('error');
    expect($p->fresh()->name)->toBe('Season 1B');

    // Ya abierta, el formulario de edicion no la toca.
    Carbon::setTestNow(now()->addDays(30));
    app(SeasonScheduleService::class)->aplicar();

    $this->withSession($sesion)->put(route('admin.seasons.schedule.update', $p), [
        'name' => 'Otra', 'starts_at' => now()->addDays(5)->setTimezone('America/Bogota')->format('Y-m-d\\TH:i'),
    ])->assertSessionHasErrors('error');

    expect($p->fresh()->name)->toBe('Season 1B')->and($p->fresh()->status)->toBe(ArenaSeason::STATUS_ACTIVE);
});

it('el cierre abre la siguiente con sus fechas exactas, programada si empieza despues', function () {
    $s = ArenaSeason::create([
        'name' => 'Season 0', 'slug' => 's0', 'status' => ArenaSeason::STATUS_ACTIVE, 'enabled_modes' => ['1v1'],
        'starts_at' => now()->subDays(10), 'ends_at' => now()->addDays(5), 'auto_close' => true, 'open_next' => true,
        'next_name' => 'Season 1', 'next_starts_at' => now()->addDays(20), 'next_ends_at' => now()->addDays(50),
    ]);

    Carbon::setTestNow(now()->addDays(6));
    app(SeasonScheduleService::class)->aplicar();

    $siguiente = ArenaSeason::programada();
    expect(ArenaSeason::current())->toBeNull()
        ->and($siguiente?->name)->toBe('Season 1')
        ->and(casi($siguiente->starts_at, Carbon::now()->subDays(6)->addDays(20)))->toBeTrue();

    // Llegada su fecha, la abre el reloj con su calendario.
    Carbon::setTestNow(now()->addDays(15));
    app(SeasonScheduleService::class)->aplicar();

    expect(ArenaSeason::current()?->name)->toBe('Season 1')
        ->and(ArenaSeason::current()->auto_close)->toBeTrue();
});

it('sin fecha de inicio la siguiente abre justo al cerrar, con su fin exacto', function () {
    ArenaSeason::create([
        'name' => 'Season 0', 'slug' => 's0', 'status' => ArenaSeason::STATUS_ACTIVE, 'enabled_modes' => ['1v1'],
        'starts_at' => now()->subDays(10), 'ends_at' => now()->addDay(), 'auto_close' => true, 'open_next' => true,
        'next_name' => 'Season 1', 'next_ends_at' => now()->addDays(90),
    ]);

    Carbon::setTestNow(now()->addDays(2));
    app(SeasonScheduleService::class)->aplicar();

    $actual = ArenaSeason::current();
    expect($actual?->name)->toBe('Season 1')
        ->and(casi($actual->ends_at, Carbon::now()->subDays(2)->addDays(90)))->toBeTrue();
});

it('el panel guarda las fechas de la siguiente y rechaza un fin anterior al inicio', function () {
    $s = ArenaSeason::create([
        'name' => 'Season 0', 'slug' => 's0', 'status' => ArenaSeason::STATUS_ACTIVE, 'enabled_modes' => ['1v1'],
        'starts_at' => now()->subDays(10), 'ends_at' => now()->addDays(20), 'auto_close' => true,
    ]);
    $sesion = sesionDeAdmin();
    $f = fn ($dias) => now()->addDays($dias)->setTimezone('America/Bogota')->format('Y-m-d\\TH:i');
    $base = ['name' => 'Season 0', 'starts_at' => $s->starts_at->copy()->setTimezone('America/Bogota')->format('Y-m-d\\TH:i'), 'ends_at' => $f(20), 'auto_close' => '1', 'open_next' => '1', 'next_name' => 'Season 1'];

    $this->withSession($sesion)->post(route('admin.seasons.update', $s), $base + ['next_starts_at' => $f(40), 'next_ends_at' => $f(100)])
        ->assertSessionHasNoErrors();
    expect($s->fresh()->next_starts_at)->not->toBeNull()->and($s->fresh()->next_ends_at)->not->toBeNull();

    $this->withSession($sesion)->post(route('admin.seasons.update', $s), $base + ['next_starts_at' => $f(40), 'next_ends_at' => $f(30)])
        ->assertSessionHasErrors('next_ends_at');
});

it('renombrar una programada actualiza su slug sin chocar con otras', function () {
    ArenaSeason::create(['name' => 'Season 2', 'slug' => 'season-2', 'status' => ArenaSeason::STATUS_ARCHIVED, 'enabled_modes' => ['1v1'], 'starts_at' => now()->subYear()]);
    $p = app(SeasonClosingService::class)->programar('Season 1', now()->addDays(5))['season'];

    $r = app(SeasonClosingService::class)->reprogramar($p, 'Season 2', now()->addDays(6));

    expect($r['ok'])->toBeTrue()
        ->and($p->fresh()->name)->toBe('Season 2')
        ->and($p->fresh()->slug)->toBe('season-2-2');
});

it('el lobby y el salon de la fama llevan el premio y la barra, sin el podio de la portada', function () {
    ArenaSeason::create([
        'name' => 'Season 0', 'slug' => 's0', 'status' => ArenaSeason::STATUS_ACTIVE, 'enabled_modes' => ['1v1'],
        'starts_at' => now()->subDays(10), 'ends_at' => now()->addDays(20), 'auto_close' => true,
    ]);
    AppSetting::setValue('season_prizes_enabled', '1', 'branding', 'boolean', true);

    $salon = $this->get(route('hall-of-fame'))->assertOk();
    $salon->assertSee('Premios de la temporada')->assertSee('arena-season', false);
    expect($salon->getContent())->not->toContain('arena-podium-stage');
});

it('la regla de meses de la barra empieza en el mes de inicio', function () {
    $s = ArenaSeason::create([
        'name' => 'Season 0', 'slug' => 's0', 'status' => ArenaSeason::STATUS_ACTIVE, 'enabled_modes' => ['1v1'],
        'starts_at' => now()->setDate(2026, 4, 8)->setTime(12, 0), 'ends_at' => now()->setDate(2026, 11, 29)->setTime(12, 0), 'auto_close' => true,
    ]);

    expect(collect($s->progreso()['hitos'])->first()['mes'])->toBe('Abr');
});
