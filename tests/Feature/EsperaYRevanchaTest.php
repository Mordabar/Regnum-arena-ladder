<?php

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchmakingService;
use App\Services\TestingLabService;
use App\Support\ArenaMode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Lo que se probo jugando y no salia bien.
 *
 * Tres cosas, y las tres son de ritmo, no de calidad del cruce:
 *
 *   1. El reparto era instantaneo: el primero que entraba se llevaba al primero
 *      que hubiera, aunque dos segundos despues llegara alguien que encajaba
 *      mucho mejor. Ahora las filas maduran unos segundos antes de entrar.
 *   2. Los bots del laboratorio desaparecian de la cola nada mas encolarlos, asi
 *      que no habia forma de mirar como reparte. Sale gratis de lo anterior.
 *   3. Se repetia rival al instante. Ahora repetir sale carisimo, pero no esta
 *      prohibido: antes eso que dejar a dos personas en cola.
 *
 * Y el cuarto, que es de otro sitio: el "borrar los bots que ya existan"
 * borraba siempre, con o sin marca.
 */
beforeEach(function () {
    // Todo esto se mide en 1v1, que es donde se probo y donde un "equipo" es una
    // persona: asi lo que falla no puede ser la composicion del equipo.
    foreach (ArenaMode::all() as $mode) {
        AppSetting::setValue(
            ArenaMode::settingKey($mode),
            $mode === ArenaMode::ONE_V_ONE ? '1' : '0',
            'modes',
            'boolean',
            true
        );
    }
});

function jugadorEnEspera(string $sufijo, string $realm, int $mmr, ?int $hace = null): Player
{
    $user = User::create([
        'discord_id' => 'esp-' . $sufijo,
        'discord_username' => 'esp_' . $sufijo,
        'name' => 'Esp ' . $sufijo,
        'email' => 'esp-' . $sufijo . '@example.com',
    ]);

    $player = Player::create([
        'user_id' => $user->id,
        'character_name' => 'Esp' . ucfirst($sufijo),
        'subclass' => 'hunter',
        'realm' => $realm,
        'pl_points' => 30,
        'mmr' => $mmr,
        'trust_score' => 100,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'is_active' => true,
    ]);

    Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => ArenaMode::ONE_V_ONE,
        'status' => 'waiting',
        'estimated_mmr' => $mmr,
        'joined_at' => $hace === null ? now() : now()->subSeconds($hace),
        'expires_at' => now()->addMinutes(30),
    ]);

    return $player;
}

/**
 * Un enfrentamiento ya ocurrido entre dos jugadores, para probar el descanso.
 */
function partidaPrevia(Player $a, Player $b, string $status, int $haceSegundos): ArenaMatch
{
    static $n = 8000;

    return ArenaMatch::create([
        'match_code' => 'ESP-' . (++$n),
        'report_token' => 'tok-esp-' . $n,
        'queue_mode' => 'random',
        'arena_mode' => ArenaMode::ONE_V_ONE,
        'team_a_realm' => $a->realm,
        'team_b_realm' => $b->realm,
        'team_a' => [['player_id' => $a->id, 'realm' => $a->realm, 'subclass' => 'hunter']],
        'team_b' => [['player_id' => $b->id, 'realm' => $b->realm, 'subclass' => 'hunter']],
        'zone' => 'frozen_bridge',
        'status' => $status,
        'estimated_mmr_avg' => 1000,
        'player_count' => 2,
        'created_at' => now()->subSeconds($haceSegundos),
    ]);
}

function empareja(bool $ignorarEspera = false): int
{
    return app(ArenaMatchmakingService::class)->processQueue(false, $ignorarEspera);
}

function rivalActualDe(Player $player): ?int
{
    $match = ArenaMatch::query()
        ->get()
        ->first(function (ArenaMatch $m) use ($player) {
            return in_array($player->id, $m->getTeamPlayerIds('team_a'), true)
                || in_array($player->id, $m->getTeamPlayerIds('team_b'), true);
        });

    if (!$match) {
        return null;
    }

    $rivales = in_array($player->id, $m2 = $match->getTeamPlayerIds('team_a'), true)
        ? $match->getTeamPlayerIds('team_b')
        : $m2;

    return (int) ($rivales[0] ?? 0);
}

it('no empareja a quien acaba de entrar en cola', function () {
    AppSetting::setValue('matchmaking_hold_seconds', 30, 'runtime', 'integer', false);

    jugadorEnEspera('nuevo-a', 'ignis', 1000);
    jugadorEnEspera('nuevo-b', 'alsius', 1000);

    // Los dos encajan perfecto y aun asi no se cruzan: acaban de llegar y el
    // emparejador espera por si entra alguien mas.
    expect(empareja())->toBe(0)
        ->and(Queue::query()->where('status', 'waiting')->count())->toBe(2);
});

it('empareja en cuanto la cola ha reposado lo suyo', function () {
    AppSetting::setValue('matchmaking_hold_seconds', 30, 'runtime', 'integer', false);

    jugadorEnEspera('maduro-a', 'ignis', 1000, hace: 45);
    jugadorEnEspera('maduro-b', 'alsius', 1000, hace: 45);

    expect(empareja())->toBe(1)
        ->and(Queue::query()->where('status', 'waiting')->count())->toBe(0);
});

it('la espera es lo que hace que elija al mejor rival y no al primero', function () {
    // Es el caso exacto que se reporto: entra uno de 1200, entra otro de 800
    // -unico que hay- y sin espera se cruzan al instante. Dos segundos despues
    // llega el de 1200 bueno y ya no hay nada que hacer.
    AppSetting::setValue('matchmaking_hold_seconds', 30, 'runtime', 'integer', false);

    $alto = jugadorEnEspera('mejor-alto', 'ignis', 1200, hace: 40);
    jugadorEnEspera('mejor-bajo', 'alsius', 800, hace: 40);
    // Este llega el ultimo y aun asi entra al mismo reparto.
    jugadorEnEspera('mejor-par', 'alsius', 1205, hace: 31);

    expect(empareja())->toBe(1);

    $rival = Player::find(rivalActualDe($alto));

    expect($rival->mmr)->toBe(1205);
});

it('el boton de procesar a mano del panel no espera a nadie', function () {
    // El admin lo pulsa justo para ver que sale con lo que hay en cola AHORA.
    AppSetting::setValue('matchmaking_hold_seconds', 300, 'runtime', 'integer', false);

    jugadorEnEspera('manual-a', 'ignis', 1000);
    jugadorEnEspera('manual-b', 'alsius', 1000);

    expect(empareja(ignorarEspera: true))->toBe(1);
});

it('los bots encolados se quedan en cola para poder mirar como reparte', function () {
    AppSetting::setValue('matchmaking_hold_seconds', 30, 'runtime', 'integer', false);

    $sesion = [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];

    app(TestingLabService::class)->seedRoster(['ignis' => 3, 'alsius' => 3]);

    foreach (['ignis', 'alsius'] as $realm) {
        $this->withSession($sesion)
            ->post(route('admin.testing.enqueue-realm'), [
                'realm' => $realm,
                'count' => 3,
                'arena_mode' => ArenaMode::ONE_V_ONE,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    // Antes se cruzaban al vuelo y desaparecian de la lista. Ahora se quedan los
    // seis esperando, que es lo que permite ver el reparto entero de una pasada.
    expect(Queue::query()->where('status', 'waiting')->count())->toBe(6)
        ->and(ArenaMatch::count())->toBe(0);

    // Y el boton de procesar los reparte cuando el admin quiere.
    expect(empareja(ignorarEspera: true))->toBe(3);
});

it('prefiere a cualquier otro antes que repetir el rival de hace un momento', function () {
    AppSetting::setValue('matchmaking_hold_seconds', 0, 'runtime', 'integer', false);
    AppSetting::setValue('rematch_rest_minutes', 2, 'runtime', 'integer', false);

    $uno = jugadorEnEspera('rev-uno', 'ignis', 1000);
    $recien = jugadorEnEspera('rev-recien', 'alsius', 1000);
    // Encaja peor por MMR, pero es cara nueva.
    $otro = jugadorEnEspera('rev-otro', 'alsius', 1150);

    // La partida de hace un minuto sigue EN CURSO, no completed: es el caso que
    // el historico de 24 h no veia y por el que se repetia rival al instante.
    partidaPrevia($uno, $recien, 'in_progress', 60);

    empareja();

    $rivalNuevo = ArenaMatch::query()
        ->where('status', '!=', 'in_progress')
        ->get()
        ->flatMap(fn (ArenaMatch $m) => array_merge($m->getTeamPlayerIds('team_a'), $m->getTeamPlayerIds('team_b')))
        ->reject(fn (int $id) => $id === $uno->id);

    expect($rivalNuevo->values()->all())->toBe([$otro->id]);
});

it('repite rival antes que dejar a dos personas en cola', function () {
    // La regla es "que varie el rival", no "que no juegue". Si de verdad no hay
    // nadie mas, se repite y punto.
    AppSetting::setValue('matchmaking_hold_seconds', 0, 'runtime', 'integer', false);
    AppSetting::setValue('rematch_rest_minutes', 2, 'runtime', 'integer', false);

    $uno = jugadorEnEspera('solo-uno', 'ignis', 1000);
    $dos = jugadorEnEspera('solo-dos', 'alsius', 1000);

    partidaPrevia($uno, $dos, 'completed', 60);

    expect(empareja())->toBe(1)
        ->and(Queue::query()->where('status', 'waiting')->count())->toBe(0);
});

it('pasado el descanso el mismo rival vuelve a ser tan bueno como cualquiera', function () {
    AppSetting::setValue('matchmaking_hold_seconds', 0, 'runtime', 'integer', false);
    AppSetting::setValue('rematch_rest_minutes', 2, 'runtime', 'integer', false);

    $uno = jugadorEnEspera('viejo-uno', 'ignis', 1000);
    $antiguo = jugadorEnEspera('viejo-dos', 'alsius', 1000);
    jugadorEnEspera('viejo-tres', 'alsius', 1150);

    partidaPrevia($uno, $antiguo, 'void', 300);

    empareja();

    expect(rivalActualDe($uno->fresh()))->toBe($antiguo->id);
});

it('con el descanso en cero no hay recargo por repetir', function () {
    AppSetting::setValue('matchmaking_hold_seconds', 0, 'runtime', 'integer', false);
    AppSetting::setValue('rematch_rest_minutes', 0, 'runtime', 'integer', false);

    $uno = jugadorEnEspera('cero-uno', 'ignis', 1000);
    $mismo = jugadorEnEspera('cero-dos', 'alsius', 1000);
    jugadorEnEspera('cero-tres', 'alsius', 1150);

    partidaPrevia($uno, $mismo, 'in_progress', 10);

    empareja();

    expect(rivalActualDe($uno->fresh()))->toBe($mismo->id);
});

it('el check de borrar los bots que ya existan se respeta en los dos sentidos', function () {
    $sesion = [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];

    $lab = app(TestingLabService::class);
    $lab->seedRoster(['ignis' => 2]);
    $primeros = $lab->testPlayerIds()->sort()->values();

    expect($primeros)->toHaveCount(2);

    // Sin marcar: el checkbox no viaja solo, viaja el hidden con "0". Antes
    // boolean() devolvia el default y borraba igual.
    $this->withSession($sesion)
        ->post(route('admin.testing.seed'), [
            'ignis_count' => 0,
            'syrtis_count' => 2,
            'alsius_count' => 0,
            'replace_existing' => '0',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $trasSumar = $lab->testPlayerIds()->sort()->values();

    expect($trasSumar)->toHaveCount(4)
        ->and($trasSumar->intersect($primeros)->count())->toBe(2);

    // Y marcando, borra de verdad.
    $this->withSession($sesion)
        ->post(route('admin.testing.seed'), [
            'ignis_count' => 1,
            'syrtis_count' => 0,
            'alsius_count' => 0,
            'replace_existing' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $trasReemplazar = $lab->testPlayerIds();

    expect($trasReemplazar)->toHaveCount(1)
        ->and($trasReemplazar->intersect($trasSumar)->count())->toBe(0);
});
