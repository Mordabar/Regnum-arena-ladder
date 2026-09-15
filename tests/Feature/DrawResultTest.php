<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El empate se puede reportar y confirmar.
 *
 * La opcion existe en la pantalla desde hace meses -"Empate (Interrumpido /
 * Inconcluso)"- y en SQLite no se podia guardar: `$table->enum(...)` no crea un
 * ENUM ahi, pero si un CHECK, y la migracion que añadio 'draw' se salto SQLite
 * creyendo que la columna era texto libre. Al confirmar el reporte saltaba
 * "CHECK constraint failed" y la partida se quedaba muerta: nadie podia
 * confirmarla y se auto-anulaba al vencer.
 *
 * En MySQL -produccion- si funcionaba, y por eso nunca salto. Aqui no habia ni
 * un test de empate porque no se podia escribir uno que pasara.
 */
function jugadorEmpate(string $sufijo, string $reino): Player
{
    $user = User::create([
        'discord_id' => 'draw-' . $sufijo,
        'discord_username' => 'draw_' . $sufijo,
        'name' => 'Draw ' . $sufijo,
        'email' => 'draw-' . $sufijo . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Draw' . ucfirst($sufijo),
        'subclass' => 'knight',
        'realm' => $reino,
        'pl_points' => 30,
        'mmr' => 1000,
        'trust_score' => 100,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'is_active' => true,
    ]);
}

function combateParaEmpatar(): array
{
    $a1 = jugadorEmpate('a1', 'alsius');
    $a2 = jugadorEmpate('a2', 'alsius');
    $b1 = jugadorEmpate('b1', 'ignis');
    $b2 = jugadorEmpate('b2', 'ignis');

    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-DRAW',
        'report_token' => 'DRAWTEST01',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'alsius',
        'team_b_realm' => 'ignis',
        'team_a' => [$pack($a1), $pack($a2)],
        'team_b' => [$pack($b1), $pack($b2)],
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'estimated_mmr_avg' => 1000,
        'player_count' => 4,
        'started_at' => now()->subMinutes(10),
        'expires_at' => now()->addMinutes(20),
    ]);

    foreach ([$a1, $a2, $b1, $b2] as $p) {
        Queue::create([
            'player_id' => $p->id,
            'queue_type' => 'random',
            'arena_mode' => '2v2',
            'status' => 'accepted',
            'match_id' => (string) $match->id,
            'estimated_mmr' => 1000,
            'joined_at' => now()->subMinutes(15),
        ]);
    }

    return compact('match', 'a1', 'a2', 'b1', 'b2');
}

it('un reporte de empate se puede confirmar y cierra la partida', function () {
    $s = combateParaEmpatar();

    $reporte = MatchReport::create([
        'match_id' => $s['match']->id,
        'reported_by_player_id' => $s['a1']->id,
        'reporting_team' => 'team_a',
        'claimed_winner_team' => 'draw',
        'claimed_winner_realm' => null,
        'status' => 'pending_confirmation',
        'final_screenshot_path' => 'match-reports/testing/draw/final.png',
        'encounter_screenshot_path' => 'match-reports/testing/draw/enc.png',
    ]);

    // Esto es lo que reventaba: al puntuar se escriben filas con result='draw'.
    app(ArenaMatchResultService::class)->confirmReportForRival($reporte, 'Empate');

    $match = $s['match']->fresh();
    expect($match->status)->toBe('completed');
    // Un empate no tiene ganador, asi que `winner_team` se queda en null a
    // proposito. El empate vive en las filas de resultado.
    expect($match->winner_team)->toBeNull();
    expect($match->results()->count())->toBe(4);

    // Los cuatro con el mismo resultado.
    foreach ($match->results as $fila) {
        expect($fila->result)->toBe('draw');
    }

    // Y las colas quedan cerradas, como en cualquier partida terminada.
    expect(Queue::where('match_id', (string) $match->id)
        ->whereIn('status', ['matched', 'accepted'])->count())->toBe(0);
});

it('el empate no reparte ventaja a ningun equipo', function () {
    $s = combateParaEmpatar();

    $reporte = MatchReport::create([
        'match_id' => $s['match']->id,
        'reported_by_player_id' => $s['a1']->id,
        'reporting_team' => 'team_a',
        'claimed_winner_team' => 'draw',
        'claimed_winner_realm' => null,
        'status' => 'pending_confirmation',
        'final_screenshot_path' => 'match-reports/testing/draw/final.png',
        'encounter_screenshot_path' => 'match-reports/testing/draw/enc.png',
    ]);

    app(ArenaMatchResultService::class)->confirmReportForRival($reporte, 'Empate');

    // Nadie suma una victoria ni una derrota.
    foreach (['a1', 'a2', 'b1', 'b2'] as $quien) {
        $p = $s[$quien]->fresh();
        expect($p->wins)->toBe(0);
        expect($p->losses)->toBe(0);
        expect($p->matches_played)->toBe(1);
    }

    // Y el PL que se mueve es el mismo para los dos bandos.
    $porEquipo = $s['match']->fresh()->results
        ->groupBy(fn ($r) => in_array($r->player_id, [$s['a1']->id, $s['a2']->id], true) ? 'a' : 'b')
        ->map(fn ($filas) => round($filas->sum('pl_change'), 1));

    expect($porEquipo['a'])->toBe($porEquipo['b']);
});

it('el panel puede cerrar un combate en empate', function () {
    // La otra via que reventaba: force_complete con winner_team=draw.
    $s = combateParaEmpatar();

    app(ArenaMatchResultService::class)->forceComplete($s['match'], 'draw', null, 'Inconcluso');

    $match = $s['match']->fresh();
    expect($match->status)->toBe('completed');
    expect($match->winner_team)->toBeNull();
    expect($match->results()->count())->toBe(4);
    expect($match->results->pluck('result')->unique()->all())->toBe(['draw']);
});
