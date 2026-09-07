<?php

use App\Models\ArenaMatch;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Las herramientas del panel para arreglar el ranking.
 *
 * Antes esto solo se podia desde la consola del servidor, y el laboratorio de
 * pruebas solo sabia borrar sus propias partidas: las jugadas entre personajes
 * de verdad no habia forma de quitarlas sin dejar las cifras descuadradas.
 */
function maintPlayer(string $suffix, array $stats = []): Player
{
    $user = User::create([
        'discord_id' => 'maint-' . $suffix,
        'discord_username' => 'maint_' . $suffix,
        'name' => 'Maint ' . $suffix,
        'email' => 'maint-' . $suffix . '@example.com',
    ]);

    return Player::create(array_merge([
        'user_id' => $user->id,
        'character_name' => 'Maint' . $suffix,
        'subclass' => 'knight',
        'realm' => 'ignis',
        'race' => Player::defaultRace('ignis'),
        'gender' => 'male',
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ], $stats));
}

function maintMatch(string $code, Player $a, Player $b): ArenaMatch
{
    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    return ArenaMatch::create([
        'match_code' => $code,
        'report_token' => strtoupper(substr(md5($code), 0, 10)),
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => $a->realm,
        'team_b_realm' => $b->realm,
        'team_a' => [$pack($a)],
        'team_b' => [$pack($b)],
        'zone' => 'frozen_bridge',
        'status' => 'completed',
        'estimated_mmr_avg' => 1000,
        'player_count' => 2,
    ]);
}

it('borra los enfrentamientos elegidos y devuelve lo que repartieron', function () {
    $ganador = maintPlayer('gana', ['pl_points' => 3.0, 'mmr' => 1016, 'wins' => 1, 'matches_played' => 1]);
    $rival = maintPlayer('pierde', ['losses' => 1, 'matches_played' => 1]);
    $match = maintMatch('ARENA-7001', $ganador, $rival);

    MatchResult::create([
        'match_id' => $match->id, 'player_id' => $ganador->id, 'result' => 'win',
        'pl_change' => 3.0, 'mmr_change' => 16, 'pl_before' => 0.0, 'pl_after' => 3.0,
        'mmr_before' => 1000, 'mmr_after' => 1016, 'created_at' => now(),
    ]);

    $this->withSession(adminPanelSession())
        ->delete(route('admin.matches.destroy'), ['match_ids' => [$match->id]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(ArenaMatch::count())->toBe(0);

    $ganador->refresh();
    expect((float) $ganador->pl_points)->toBe(0.0)
        ->and($ganador->mmr)->toBe(1000)
        ->and($ganador->wins)->toBe(0)
        ->and($ganador->matches_played)->toBe(0);
});

it('el recalculo endereza a quien tiene puntos sin partidas', function () {
    // El caso que se vio en el ladder: puntuacion sin una sola partida jugada.
    $fantasma = maintPlayer('fantasma', ['pl_points' => 5.4, 'mmr' => 1000]);

    $this->withSession(adminPanelSession())
        ->post(route('admin.ladder.recalculate'))
        ->assertRedirect();

    expect((float) $fantasma->refresh()->pl_points)->toBe(0.0);
});

it('revisar sin tocar deja las cifras como estaban', function () {
    $fantasma = maintPlayer('ensayo', ['pl_points' => 5.4]);

    $this->withSession(adminPanelSession())
        ->post(route('admin.ladder.recalculate'), ['dry_run' => 1])
        ->assertRedirect()
        ->assertSessionHas('ladder_preview');

    expect((float) $fantasma->refresh()->pl_points)->toBe(5.4);
});

it('reiniciar el ranking exige escribir la palabra', function () {
    $jugador = maintPlayer('protegido', ['pl_points' => 9.0, 'matches_played' => 3]);
    maintMatch('ARENA-7002', $jugador, maintPlayer('otro'));

    $this->withSession(adminPanelSession())
        ->post(route('admin.ladder.reset'), ['confirmacion' => 'si'])
        ->assertSessionHasErrors('confirmacion');

    expect(ArenaMatch::count())->toBe(1)
        ->and((float) $jugador->refresh()->pl_points)->toBe(9.0);
});

it('reiniciar el ranking lo deja todo a cero sin borrar personajes', function () {
    $jugador = maintPlayer('reinicio', ['pl_points' => 9.0, 'mmr' => 1200, 'wins' => 3, 'matches_played' => 3]);
    maintMatch('ARENA-7003', $jugador, maintPlayer('rival'));

    $this->withSession(adminPanelSession())
        ->post(route('admin.ladder.reset'), ['confirmacion' => 'REINICIAR'])
        ->assertRedirect();

    $jugador->refresh();
    expect(ArenaMatch::count())->toBe(0)
        ->and(Player::count())->toBe(2)
        ->and((float) $jugador->pl_points)->toBe(0.0)
        ->and($jugador->mmr)->toBe(1000)
        ->and($jugador->matches_played)->toBe(0);
});

it('el panel no obedece a quien no ha iniciado sesion', function () {
    $this->post(route('admin.ladder.reset'), ['confirmacion' => 'REINICIAR'])
        ->assertRedirect(route('admin.login'));

    $this->delete(route('admin.matches.destroy'), ['match_ids' => ['x']])
        ->assertRedirect(route('admin.login'));
});
