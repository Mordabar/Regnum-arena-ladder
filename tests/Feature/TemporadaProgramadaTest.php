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
beforeEach(function () {
    ArenaSeason::query()->delete();
    config(['arena.season_timezone' => 'America/Bogota']);
});

afterEach(fn () => Carbon::setTestNow());

it('programa una temporada futura sin abrirla', function () {
    $r = app(SeasonClosingService::class)->programar('Season 1', now()->addDays(10), ['dias' => 30, 'premios' => false]);

    expect($r['ok'])->toBeTrue()
        ->and($r['season']->status)->toBe(ArenaSeason::STATUS_SCHEDULED)
        ->and($r['season']->ends_at->equalTo($r['season']->starts_at->copy()->addDays(30)))->toBeTrue()
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
    app(SeasonClosingService::class)->programar('Season 1', now()->addDays(2), ['dias' => 10, 'premios' => false]);

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
        'duration_days' => 45,
        'prizes' => '1',
    ])->assertSessionHasNoErrors();

    $programada = ArenaSeason::programada();
    expect($programada?->name)->toBe('Season 1')->and($programada->next_duration_days)->toBe(45);

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
    app(SeasonClosingService::class)->programar('Season 1', now()->addDays(10), ['dias' => 30]);

    Carbon::setTestNow(now()->addDays(2));
    app(SeasonScheduleService::class)->aplicar();

    expect(ArenaSeason::current())->toBeNull()
        ->and(ArenaSeason::programada()?->name)->toBe('Season 1');

    Carbon::setTestNow(now()->addDays(9));
    app(SeasonScheduleService::class)->aplicar();

    expect(ArenaSeason::current()?->name)->toBe('Season 1');
});

it('una programada que abre tarde conserva su duracion completa', function () {
    app(SeasonClosingService::class)->programar('Season 1', now()->addDay(), ['dias' => 30]);

    Carbon::setTestNow(now()->addDays(60));
    app(SeasonScheduleService::class)->aplicar();

    $actual = ArenaSeason::current();

    expect($actual?->name)->toBe('Season 1')
        ->and($actual->vencida())->toBeFalse()
        ->and($actual->ends_at->equalTo($actual->starts_at->copy()->addDays(30)))->toBeTrue();
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
