<?php

use App\Models\AppSetting;
use App\Models\ArenaSeason;
use App\Models\Player;
use App\Models\SeasonPlayerStat;
use App\Models\User;
use App\Services\SeasonClosingService;
use App\Services\SeasonPrizeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // La instalacion deja una temporada abierta de serie. Estos tests crean la
    // suya, asi que primero se archiva lo que hubiera: con dos abiertas no se
    // sabe cual se esta cerrando.
    ArenaSeason::query()->update(['status' => ArenaSeason::STATUS_ARCHIVED]);
});

/**
 * Los premios de la temporada y el Salon de la Fama.
 *
 * Dos promesas:
 *
 *   1. La portada dice lo que se reparte AHORA, leido de los ajustes.
 *   2. El Salon dice lo que repartio CADA temporada pasada, con las cifras del
 *      dia que se cerro. Una vitrina que cambia cuando los campeones siguen
 *      jugando no es una vitrina.
 */
function jugadorConPuntos(string $sufijo, string $realm, float $pl, int $mmr = 1000, bool $activo = true): Player
{
    $user = User::create([
        'discord_id' => 'pr-' . $sufijo,
        'discord_username' => 'pr_' . $sufijo,
        'name' => 'Pr ' . $sufijo,
        'email' => 'pr-' . $sufijo . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Pr' . ucfirst($sufijo),
        'subclass' => 'hunter',
        'realm' => $realm,
        'pl_points' => $pl,
        'mmr' => $mmr,
        'trust_score' => 100,
        'matches_played' => 10,
        'wins' => 6,
        'losses' => 4,
        'is_active' => $activo,
    ]);
}

// -------------------------------------------------------------------- premios

it('el reparto por defecto son 17 lingotes', function () {
    $premios = app(SeasonPrizeService::class);

    expect($premios->reparto())->toBe([1 => 10, 2 => 5, 3 => 2])
        ->and($premios->total())->toBe(17)
        ->and($premios->moneda())->toBe('lingotes de Magnanita')
        ->and($premios->activos())->toBeTrue();
});

it('el reparto se cambia desde los ajustes', function () {
    AppSetting::setValue('season_prize_1', 20, 'branding', 'integer', true);
    AppSetting::setValue('season_prize_2', 0, 'branding', 'integer', true);
    AppSetting::setValue('season_prize_currency', 'coronas', 'branding', 'string', true);

    $premios = app(SeasonPrizeService::class);

    expect($premios->reparto())->toBe([1 => 20, 3 => 2])
        // Un puesto a cero no reparte, asi que desaparece del podio en vez de
        // salir con un premio de cero.
        ->and($premios->total())->toBe(22)
        ->and($premios->moneda())->toBe('coronas');
});

it('el podio son los tres primeros del ladder', function () {
    $primero = jugadorConPuntos('a', 'ignis', 400);
    $segundo = jugadorConPuntos('b', 'alsius', 300);
    $tercero = jugadorConPuntos('c', 'syrtis', 200);
    jugadorConPuntos('d', 'ignis', 100);

    $podio = app(SeasonPrizeService::class)->podio();

    expect($podio)->toHaveCount(3)
        ->and($podio[0]['puesto'])->toBe(1)
        ->and($podio[0]['premio'])->toBe(10)
        ->and($podio[0]['player']->id)->toBe($primero->id)
        ->and($podio[1]['player']->id)->toBe($segundo->id)
        ->and($podio[2]['player']->id)->toBe($tercero->id);
});

it('el podio sale con huecos cuando no hay nadie', function () {
    // El primer dia de temporada la tabla esta vacia, y el podio sigue teniendo
    // que decir lo que se reparte: es justo lo que se viene a leer.
    $podio = app(SeasonPrizeService::class)->podio();

    expect($podio)->toHaveCount(3)
        ->and($podio[0]['player'])->toBeNull()
        ->and($podio[0]['premio'])->toBe(10);
});

it('la portada enseña el podio y lo que se reparte', function () {
    jugadorConPuntos('e', 'ignis', 400);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('17')
        ->assertSee('lingotes de Magnanita')
        ->assertSee('PrE');
});

it('apagando los premios la portada no los enseña', function () {
    AppSetting::setValue('season_prizes_enabled', '0', 'branding', 'boolean', true);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('lingotes de Magnanita');
});

// ------------------------------------------------------------ salon de la fama

it('el salon se abre aunque no haya ninguna temporada cerrada', function () {
    ArenaSeason::query()->delete();

    $this->get(route('hall-of-fame'))
        ->assertOk()
        ->assertSee('Salon de la Fama')
        ->assertSee('vitrina esta vacia');
});

it('cerrar la temporada congela el podio y abre la siguiente', function () {
    ArenaSeason::create([
        'name' => 'Alpha Season',
        'slug' => 'alpha-season',
        'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['1v1', '2v2'],
        'starts_at' => now()->subMonth(),
    ]);

    $campeon = jugadorConPuntos('f', 'ignis', 500);
    jugadorConPuntos('g', 'alsius', 300);

    $resultado = app(SeasonClosingService::class)->cerrar('Season 1');

    expect($resultado['ok'])->toBeTrue()
        ->and($resultado['congelados'])->toBe(2)
        ->and($resultado['season']->status)->toBe(ArenaSeason::STATUS_ARCHIVED)
        // El reparto se guarda CON la temporada: si se leyera de los ajustes,
        // la temporada 0 diria lo que reparte la 3.
        ->and($resultado['season']->reparto())->toBe([1 => 10, 2 => 5, 3 => 2])
        ->and($resultado['season']->prize_currency)->toBe('lingotes de Magnanita')
        // Y la siguiente queda abierta, heredando las modalidades: cerrar no
        // puede dejar la arena sin modos y a todos fuera de la cola.
        ->and($resultado['siguiente']->name)->toBe('Season 1')
        ->and($resultado['siguiente']->enabledModes())->toBe(['1v1', '2v2'])
        ->and(ArenaSeason::current()->id)->toBe($resultado['siguiente']->id);

    $congelado = SeasonPlayerStat::query()
        ->where('season_id', $resultado['season']->id)
        ->where('player_id', $campeon->id)
        ->firstOrFail();

    expect((float) $congelado->pl_points)->toBe(500.0);
});

it('lo congelado no cambia porque el campeon siga jugando', function () {
    // Es la razon de ser de la vitrina: con cuantos puntos se gano la temporada
    // 0 tiene que seguir siendo el mismo numero dentro de dos años.
    ArenaSeason::create([
        'name' => 'Alpha Season', 'slug' => 'alpha', 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['2v2'], 'starts_at' => now()->subMonth(),
    ]);

    $campeon = jugadorConPuntos('h', 'ignis', 500);
    $cerrada = app(SeasonClosingService::class)->cerrar()['season'];

    $campeon->update(['pl_points' => 999]);

    $congelado = SeasonPlayerStat::query()
        ->where('season_id', $cerrada->id)
        ->where('player_id', $campeon->id)
        ->firstOrFail();

    expect((float) $congelado->pl_points)->toBe(500.0);
});

it('el salon enseña el podio de la temporada cerrada con su premio', function () {
    ArenaSeason::create([
        'name' => 'Alpha Season', 'slug' => 'alpha', 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['2v2'], 'starts_at' => now()->subMonth(),
    ]);

    jugadorConPuntos('i', 'ignis', 500);
    app(SeasonClosingService::class)->cerrar();

    $this->get(route('hall-of-fame'))
        ->assertOk()
        ->assertSee('Alpha Season')
        ->assertSee('PrI')
        ->assertSee('10')
        ->assertSee('lingotes de Magnanita');
});

it('quien esta sancionado no entra en la vitrina', function () {
    ArenaSeason::create([
        'name' => 'Alpha Season', 'slug' => 'alpha', 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['2v2'], 'starts_at' => now()->subMonth(),
    ]);

    $limpio = jugadorConPuntos('j', 'ignis', 300);
    $sancionado = jugadorConPuntos('k', 'alsius', 900, activo: false);

    $cerrada = app(SeasonClosingService::class)->cerrar()['season'];

    expect(SeasonPlayerStat::query()->where('player_id', $sancionado->id)->value('is_hall_eligible'))->toBeFalsy();

    // Tiene mas puntos que nadie y aun asi no sale en el podio.
    $this->get(route('hall-of-fame'))
        ->assertOk()
        ->assertSee($limpio->character_name)
        ->assertDontSee($sancionado->character_name);
});

it('no se cierra una temporada si no hay ninguna abierta', function () {
    // El beforeEach ya archivo todas.
    expect(app(SeasonClosingService::class)->cerrar()['ok'])->toBeFalse();
});

it('cerrar archiva cualquier otra temporada que quedara abierta', function () {
    // Con dos abiertas, current() devuelve la mas reciente y la otra se queda
    // viva y escondida para siempre.
    ArenaSeason::create([
        'name' => 'Huerfana', 'slug' => 'huerfana', 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['2v2'], 'starts_at' => now()->subYear(),
    ]);
    ArenaSeason::create([
        'name' => 'Alpha Season', 'slug' => 'alpha', 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['2v2'], 'starts_at' => now()->subMonth(),
    ]);

    app(SeasonClosingService::class)->cerrar('Season 1');

    expect(ArenaSeason::query()->where('slug', 'huerfana')->value('status'))
        ->toBe(ArenaSeason::STATUS_ARCHIVED)
        ->and(ArenaSeason::query()->where('status', ArenaSeason::STATUS_ACTIVE)->count())->toBe(1);
});

it('el boton de cerrar exige escribir la palabra', function () {
    ArenaSeason::create([
        'name' => 'Alpha Season', 'slug' => 'alpha', 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['2v2'], 'starts_at' => now()->subMonth(),
    ]);

    $sesion = [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];

    $this->withSession($sesion)
        ->post(route('admin.season.close'), ['confirmacion' => 'vale'])
        ->assertSessionHasErrors('confirmacion');

    expect(ArenaSeason::current()->status)->toBe(ArenaSeason::STATUS_ACTIVE);

    $this->withSession($sesion)
        ->post(route('admin.season.close'), ['confirmacion' => 'CERRAR'])
        ->assertSessionHasNoErrors();

    expect(ArenaSeason::query()->where('slug', 'alpha')->value('status'))
        ->toBe(ArenaSeason::STATUS_ARCHIVED);
});

it('el nombre de la siguiente sale del anterior si no se dice otro', function () {
    ArenaSeason::create([
        'name' => 'Season 4', 'slug' => 's4', 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['2v2'], 'starts_at' => now()->subMonth(),
    ]);

    expect(app(SeasonClosingService::class)->cerrar()['siguiente']->name)->toBe('Season 5');
});

it('un nombre sin numero no acaba produciendo Temporada 2026- 10', function () {
    // Con la fecha por nombre, el cierre siguiente lee el "09" final de
    // "Temporada 2026-09" y propone "Temporada 2026- 10".
    ArenaSeason::query()->delete();

    ArenaSeason::create([
        'name' => 'Alpha', 'slug' => 'alpha', 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['2v2'], 'starts_at' => now()->subMonth(),
    ]);

    $siguiente = app(SeasonClosingService::class)->cerrar()['siguiente'];

    expect($siguiente->name)->toBe('Temporada 2')
        ->and($siguiente->name)->not->toContain('-');
});

it('el segundo clic de cerrar no archiva la temporada recien abierta', function () {
    ArenaSeason::create([
        'name' => 'Alpha Season', 'slug' => 'alpha', 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['2v2'], 'starts_at' => now()->subMonth(),
    ]);

    jugadorConPuntos('dc', 'ignis', 500);

    $cerrar = app(SeasonClosingService::class);

    expect($cerrar->cerrar('Season 1')['ok'])->toBeTrue();

    // El segundo clic llega sobre la temporada que acaba de nacer: vacia, de
    // un segundo de vida y con el podio en blanco.
    $segundo = $cerrar->cerrar();

    expect($segundo['ok'])->toBeFalse()
        ->and(ArenaSeason::query()->where('slug', 'season-1')->value('status'))
        ->toBe(ArenaSeason::STATUS_ACTIVE)
        // Y no ha nacido una tercera temporada basura detras.
        ->and(ArenaSeason::query()->where('status', ArenaSeason::STATUS_ACTIVE)->count())->toBe(1)
        ->and(SeasonPlayerStat::query()->where('season_id', ArenaSeason::current()->id)->exists())->toBeFalse();
});

it('una temporada con podio si se puede cerrar el mismo dia que se abrio', function () {
    // La guarda mira que este vacia, no solo que sea reciente: una temporada
    // corta y legitima tiene que poder cerrarse igual.
    ArenaSeason::create([
        'name' => 'Relampago', 'slug' => 'relampago', 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['2v2'], 'starts_at' => now()->subMinute(),
    ]);

    jugadorConPuntos('rel', 'ignis', 400);

    expect(app(SeasonClosingService::class)->cerrar('Siguiente 1')['ok'])->toBeTrue();
});

it('los cajones del podio usan la moneda configurada', function () {
    // Con "lingotes" escrito a mano en la plantilla, cambiar el premio a otra
    // cosa dejaba el titulo diciendo una moneda y los tres cajones otra.
    AppSetting::setValue('season_prize_currency', 'monedas de oro');

    jugadorConPuntos('mo', 'ignis', 700);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('monedas de oro')
        ->assertDontSee('lingotes');
});

it('el podio llega hasta el puesto premiado mas alto aunque haya huecos', function () {
    // Con el 2.o puesto a cero el reparto es [1, 3]: contar premios da dos, y
    // el tercero se quedaria vacio aunque ese jugador exista.
    AppSetting::setValue('season_prize_2', 0);

    jugadorConPuntos('p1', 'ignis', 900);
    jugadorConPuntos('p2', 'alsius', 600);
    $tercero = jugadorConPuntos('p3', 'syrtis', 300);

    $podio = app(SeasonPrizeService::class)->podio();

    expect($podio->firstWhere('puesto', 3)['player']?->id)->toBe($tercero->id);
});
