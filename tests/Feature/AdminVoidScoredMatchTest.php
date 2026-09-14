<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\User;
use App\Services\ArenaMatchResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sesionPanelAnulacion(): array
{
    return [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];
}

/**
 * Anular un enfrentamiento que ya repartio puntos.
 *
 * Moderacion se quedaba sin salida cuando una partida se cerraba por un fallo:
 * forceComplete solo sabe cambiar el ganador, y aqui el problema es que no
 * hubo partida. Anular la rechazaba por estar puntuada, asi que esos puntos
 * contaban en el ladder para siempre.
 */
function jugadorAnulacion(string $sufijo): Player
{
    $user = User::create([
        'discord_id' => 'anular-' . $sufijo,
        'discord_username' => 'anular_' . $sufijo,
        'name' => 'Anular ' . $sufijo,
        'email' => 'anular-' . $sufijo . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Anular ' . $sufijo,
        'subclass' => 'knight',
        'realm' => 'ignis',
        'pl_points' => 0,
        'mmr' => 1000,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function enfrentamientoAnulacion(string $codigo, Player $a, Player $b): ArenaMatch
{
    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user->discord_id,
    ];

    return ArenaMatch::create([
        'match_code' => $codigo,
        'report_token' => strtoupper(substr(md5($codigo), 0, 10)),
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => [$pack($a)],
        'team_b' => [$pack($b)],
        'zone' => 'frozen_bridge',
        'status' => 'completed',
        'winner_team' => 'team_a',
        'winner_realm' => 'ignis',
        'player_count' => 2,
        'estimated_mmr_avg' => 1000,
        'completed_at' => now(),
    ]);
}

function resultadoAnulacion(ArenaMatch $match, Player $p, string $resultado, float $pl, int $mmr, float $plAntes, int $mmrAntes, $cuando = null): MatchResult
{
    return MatchResult::create([
        'match_id' => $match->id,
        'player_id' => $p->id,
        'result' => $resultado,
        'pl_change' => $pl,
        'mmr_change' => $mmr,
        'pl_before' => $plAntes,
        'pl_after' => round($plAntes + $pl, 1),
        'mmr_before' => $mmrAntes,
        'mmr_after' => $mmrAntes + $mmr,
        'created_at' => $cuando ?? now(),
    ]);
}

it('anula un enfrentamiento ya puntuado y devuelve los puntos', function () {
    $ganador = jugadorAnulacion('gana');
    $perdedor = jugadorAnulacion('pierde');

    $match = enfrentamientoAnulacion('ARENA-VOID1', $ganador, $perdedor);
    resultadoAnulacion($match, $ganador, 'win', 12.5, 20, 0.0, 1000);
    resultadoAnulacion($match, $perdedor, 'loss', -8.0, -15, 30.0, 1050);

    $ganador->update(['pl_points' => 12.5, 'mmr' => 1020, 'wins' => 1, 'matches_played' => 1]);
    $perdedor->update(['pl_points' => 22.0, 'mmr' => 1035, 'losses' => 1, 'matches_played' => 1]);

    MatchReport::create([
        'match_id' => $match->id,
        'reported_by_player_id' => $ganador->id,
        'reporting_team' => 'team_a',
        'claimed_winner_team' => 'team_a',
        'claimed_winner_realm' => 'ignis',
        'status' => 'confirmed',
        'final_screenshot_path' => 'match-reports/testing/anular/uno.png',
        'encounter_screenshot_path' => 'match-reports/testing/anular/uno.png',
    ]);

    app(ArenaMatchResultService::class)->markVoid($match->fresh(), null, 'La partida nunca se jugo');

    $match->refresh();
    expect($match->status)->toBe('void');
    expect($match->winner_team)->toBeNull();
    expect($match->results()->count())->toBe(0);
    expect($match->report->fresh()->status)->toBe('voided');

    // Cada uno vuelve a donde estaba antes de esta partida.
    expect((float) $ganador->fresh()->pl_points)->toBe(0.0);
    expect($ganador->fresh()->mmr)->toBe(1000);
    expect($ganador->fresh()->wins)->toBe(0);
    expect($ganador->fresh()->matches_played)->toBe(0);

    expect((float) $perdedor->fresh()->pl_points)->toBe(30.0);
    expect($perdedor->fresh()->mmr)->toBe(1050);
    expect($perdedor->fresh()->losses)->toBe(0);
});

it('corrige el rastro de las partidas jugadas despues de la anulada', function () {
    $jugador = jugadorAnulacion('con-historial');
    $rival = jugadorAnulacion('rival-historial');

    // La partida con el fallo, y despues otra que si conto.
    $mala = enfrentamientoAnulacion('ARENA-VOID2', $jugador, $rival);
    resultadoAnulacion($mala, $jugador, 'win', 10.0, 20, 0.0, 1000, now()->subHours(2));
    resultadoAnulacion($mala, $rival, 'loss', -5.0, -12, 0.0, 1000, now()->subHours(2));

    $buena = enfrentamientoAnulacion('ARENA-VOID3', $jugador, $rival);
    $posterior = resultadoAnulacion($buena, $jugador, 'win', 6.0, 14, 10.0, 1020, now()->subHour());

    $jugador->update(['pl_points' => 16.0, 'mmr' => 1034, 'wins' => 2, 'matches_played' => 2]);

    app(ArenaMatchResultService::class)->markVoid($mala->fresh(), null, 'Bug');

    // La partida buena sigue existiendo, pero su recorrido ya no arrastra los
    // puntos de la anulada.
    $posterior->refresh();
    expect((float) $posterior->pl_before)->toBe(0.0);
    expect((float) $posterior->pl_after)->toBe(6.0);
    expect($posterior->mmr_before)->toBe(1000);
    expect($posterior->mmr_after)->toBe(1014);

    $jugador->refresh();
    expect((float) $jugador->pl_points)->toBe(6.0);
    expect($jugador->mmr)->toBe(1014);
    expect($jugador->wins)->toBe(1);
    expect($jugador->matches_played)->toBe(1);
});

it('el panel deja anular un enfrentamiento cerrado y avisa de que devuelve puntos', function () {
    $ganador = jugadorAnulacion('panel-gana');
    $perdedor = jugadorAnulacion('panel-pierde');

    $match = enfrentamientoAnulacion('ARENA-VOID4', $ganador, $perdedor);
    resultadoAnulacion($match, $ganador, 'win', 9.0, 18, 0.0, 1000);
    resultadoAnulacion($match, $perdedor, 'loss', -4.0, -10, 0.0, 1000);
    $ganador->update(['pl_points' => 9.0, 'mmr' => 1018, 'wins' => 1, 'matches_played' => 1]);

    $this->withSession(sesionPanelAnulacion())
        ->get(route('admin.matches.show', $match))
        ->assertOk()
        ->assertSee('Anular y devolver los puntos');

    $this->withSession(sesionPanelAnulacion())
        ->post(route('admin.matches.resolve', $match), [
            'action' => 'void',
            'note' => 'Enfrentamiento con fallo',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($match->fresh()->status)->toBe('void');
    expect((float) $ganador->fresh()->pl_points)->toBe(0.0);
    expect($ganador->fresh()->wins)->toBe(0);
});

it('anular sigue funcionando en uno que nunca repartio puntos', function () {
    $a = jugadorAnulacion('sin-puntos-a');
    $b = jugadorAnulacion('sin-puntos-b');

    $match = enfrentamientoAnulacion('ARENA-VOID5', $a, $b);
    $match->update(['status' => 'in_progress', 'winner_team' => null, 'completed_at' => null]);

    app(ArenaMatchResultService::class)->markVoid($match->fresh(), null, 'Nadie reporto');

    expect($match->fresh()->status)->toBe('void');
    expect((float) $a->fresh()->pl_points)->toBe(0.0);
});

it('no deja los puntos en negativo al anular', function () {
    $jugador = jugadorAnulacion('suelo');

    $match = enfrentamientoAnulacion('ARENA-VOID6', $jugador, jugadorAnulacion('suelo-rival'));
    // Una fila que dio mas de lo que el jugador tiene ahora: puede pasar si
    // moderacion toco algo por el camino.
    resultadoAnulacion($match, $jugador, 'win', 40.0, 60, 0.0, 1000);
    $jugador->update(['pl_points' => 5.0, 'mmr' => 1010, 'wins' => 1, 'matches_played' => 1]);

    app(ArenaMatchResultService::class)->markVoid($match->fresh(), null, 'Bug');

    expect((float) $jugador->fresh()->pl_points)->toBe(0.0);
    expect($jugador->fresh()->mmr)->toBeGreaterThanOrEqual(100);
});
