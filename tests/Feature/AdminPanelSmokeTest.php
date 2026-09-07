<?php

use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Todas las pantallas del panel admin tienen que abrir.
 *
 * Existe porque una directiva Blade mal formada tumbo el testing aislado en
 * produccion sin que nada lo detectara: `php artisan view:cache` compila las
 * plantillas pero no las ejecuta, asi que un error de sintaxis en el PHP
 * generado solo aparece al renderizar. Estas pruebas si renderizan.
 */

function adminPanelSession(): array
{
    return [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];
}

function adminPanelPlayer(string $suffix, string $realm = 'ignis'): Player
{
    $user = User::create([
        'discord_id' => 'panel-' . $suffix,
        'discord_username' => 'panel_' . $suffix,
        'name' => 'Panel ' . $suffix,
        'email' => $suffix . '@panel.test',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Panel ' . $suffix,
        'subclass' => 'knight',
        'realm' => $realm,
        'pl_points' => 12.5,
        'mmr' => 1050,
        'matches_played' => 3,
        'wins' => 2,
        'losses' => 1,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

it('abre todas las pantallas del panel con datos vacios', function (string $route) {
    $this->withSession(adminPanelSession())
        ->get(route($route))
        ->assertOk();
})->with([
    'admin.dashboard',
    'admin.inbox',
    'admin.matches.index',
    'admin.players.index',
    'admin.settings',
    'admin.testing',
    'admin.zones',
]);

it('abre todas las pantallas del panel con datos reales', function (string $route) {
    // Con contenido las vistas recorren bucles y relaciones que el estado
    // vacio nunca toca.
    $ignis = adminPanelPlayer('ign-a');
    adminPanelPlayer('ign-b');
    adminPanelPlayer('als-a', 'alsius');

    ArenaMatch::create([
        'match_code' => 'ARENA-4242',
        'report_token' => 'PANELTEST1',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => [['player_id' => $ignis->id, 'character_name' => $ignis->character_name, 'subclass' => 'knight', 'realm' => 'ignis', 'discord_id' => '1']],
        'team_b' => [['player_id' => $ignis->id + 2, 'character_name' => 'Panel als-a', 'subclass' => 'knight', 'realm' => 'alsius', 'discord_id' => '3']],
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $this->withSession(adminPanelSession())
        ->get(route($route))
        ->assertOk();
})->with([
    'admin.dashboard',
    'admin.inbox',
    'admin.matches.index',
    'admin.players.index',
    'admin.settings',
    'admin.testing',
    'admin.zones',
]);

it('abre el detalle de un match desde el panel', function () {
    $player = adminPanelPlayer('detalle');

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-5151',
        'report_token' => 'PANELTEST2',
        'queue_mode' => 'premade',
        'arena_mode' => '3v3',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'syrtis',
        'team_a' => [['player_id' => $player->id, 'character_name' => $player->character_name, 'subclass' => 'knight', 'realm' => 'ignis', 'discord_id' => '1']],
        'team_b' => [['player_id' => $player->id + 1, 'character_name' => 'Rival', 'subclass' => 'hunter', 'realm' => 'syrtis', 'discord_id' => '2']],
        'zone' => 'emerald_pass',
        'status' => 'disputed',
    ]);

    $this->withSession(adminPanelSession())
        ->get(route('admin.matches.show', $match))
        ->assertOk()
        // El mapa dejo de traer sus propios scripts: ahora los pone el layout,
        // y el del panel es otro distinto al del sitio.
        ->assertSee('data-arena-map', false)
        ->assertSee('window.arenaLoadMap', false);
});

it('protege el panel de quien no ha iniciado sesion', function (string $route) {
    $this->get(route($route))->assertRedirect(route('admin.login'));
})->with([
    'admin.dashboard',
    'admin.players.index',
    'admin.settings',
    'admin.testing',
]);

/**
 * El panel tiene su propia maquetacion compilada. Si alguna pantalla vuelve a
 * `layouts.arena` pierde la navegacion lateral y se queda dependiendo del CDN
 * de Tailwind, que es justo lo que se quiso evitar.
 */
it('sirve todas las pantallas con la maquetacion del panel', function (string $route) {
    $response = $this->withSession(adminPanelSession())->get(route($route));

    $response->assertOk();
    $response->assertSee('ap-shell', escape: false);
    $response->assertSee('css/admin.css', escape: false);
})->with([
    'admin.dashboard',
    'admin.inbox',
    'admin.matches.index',
    'admin.players.index',
    'admin.settings',
    'admin.zones',
    'admin.testing',
]);

it('deja el entorno de pruebas alcanzable desde el menu lateral', function () {
    // Estaba fuera de todos los menus: solo se llegaba escribiendo la URL.
    $this->withSession(adminPanelSession())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(route('admin.testing'), escape: false);
});

it('no filtra el usuario admin por defecto en la pantalla de acceso', function () {
    $response = $this->get(route('admin.login'));

    $response->assertOk();
    $response->assertDontSee('Usuario por defecto');
});

/**
 * El formulario de decision oculta con JavaScript los campos que no aplican a
 * la accion elegida. Si el script no llega a ejecutarse, todo tiene que quedar
 * VISIBLE: con el selector de jugador oculto por defecto, elegir "abandono"
 * sancionaria al primero de la lista sin que el moderador lo viera.
 */
it('deja visibles todos los campos de decision cuando no hay javascript', function () {
    $player = adminPanelPlayer('nojs');
    $rival = adminPanelPlayer('nojs-rival', 'syrtis');

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-9100',
        'report_token' => 'NOJS000001',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'syrtis',
        'team_a' => [['player_id' => $player->id, 'character_name' => $player->character_name, 'subclass' => 'knight', 'realm' => 'ignis', 'discord_id' => (string) $player->user_id]],
        'team_b' => [['player_id' => $rival->id, 'character_name' => $rival->character_name, 'subclass' => 'knight', 'realm' => 'syrtis', 'discord_id' => (string) $rival->user_id]],
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'estimated_mmr_avg' => 1000,
        'started_at' => now(),
    ]);

    $html = $this->withSession(adminPanelSession())
        ->get(route('admin.matches.show', $match))
        ->assertOk()
        ->getContent();

    // El bloque del selector de jugador no puede llevar el atributo hidden.
    expect($html)->toContain('data-ap-when="abandonment_walkover support_infraction"');
    expect($html)->not->toContain('data-ap-when="abandonment_walkover support_infraction" hidden');
});

/**
 * El menu lateral y la cabecera necesitan los mismos contadores. Se calculan
 * una sola vez por peticion: repetirlos eran dos consultas de mas en cada carga
 * de cada pantalla del panel.
 */
it('cuenta los pendientes una sola vez por peticion', function () {
    $queries = [];
    Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->withSession(adminPanelSession())->get(route('admin.settings'))->assertOk();

    $pendingCountQueries = collect($queries)
        ->filter(fn (string $sql) => str_contains($sql, 'count(*)')
            && (str_contains($sql, 'match_reports') || str_contains($sql, 'matches')))
        ->count();

    expect($pendingCountQueries)->toBe(2);
});
