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
        ->assertOk();
});

it('protege el panel de quien no ha iniciado sesion', function (string $route) {
    $this->get(route($route))->assertRedirect(route('admin.login'));
})->with([
    'admin.dashboard',
    'admin.players.index',
    'admin.settings',
    'admin.testing',
]);
