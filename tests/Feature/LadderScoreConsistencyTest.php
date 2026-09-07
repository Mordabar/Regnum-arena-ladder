<?php

use App\Models\ArenaMatch;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\User;
use App\Services\ArenaMatchResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Cada fila de resultado tiene que cuadrar consigo misma: lo que dice que
 * cambio y la diferencia entre el antes y el despues son el mismo numero.
 *
 * Cuando no cuadraba, quien la leia para deshacer -la purga del laboratorio-
 * devolvia puntos que nadie llego a perder, y aparecian jugadores en el ladder
 * con puntuacion sin haber ganado una sola partida.
 */
function consistencyPlayer(string $suffix, string $realm, float $pl, int $mmr): Player
{
    $user = User::create([
        'discord_id' => 'cons-' . $suffix,
        'discord_username' => 'cons_' . $suffix,
        'name' => 'Cons ' . $suffix,
        'email' => 'cons-' . $suffix . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Cons' . $suffix,
        'subclass' => 'knight',
        'realm' => $realm,
        'race' => Player::defaultRace($realm),
        'gender' => 'male',
        'pl_points' => $pl,
        'mmr' => $mmr,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

it('lo que la fila dice que cambio es lo que de verdad cambio', function () {
    // El perdedor entra a cero PL: la formula quiere restarle, el suelo lo
    // impide, y la fila tiene que contar lo segundo.
    $ganador = consistencyPlayer('gana', 'ignis', 50.0, 1200);
    $perdedor = consistencyPlayer('pierde', 'alsius', 0.0, 1000);

    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-9200',
        'report_token' => 'CONSIST001',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => [$pack($ganador)],
        'team_b' => [$pack($perdedor)],
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'estimated_mmr_avg' => 1100,
        'player_count' => 2,
    ]);

    app(ArenaMatchResultService::class)->forceComplete($match, 'team_a');

    foreach (MatchResult::where('match_id', $match->id)->get() as $fila) {
        expect(round((float) $fila->pl_after - (float) $fila->pl_before, 1))
            ->toBe(round((float) $fila->pl_change, 1));
        expect((int) $fila->mmr_after - (int) $fila->mmr_before)
            ->toBe((int) $fila->mmr_change);
    }

    $filaPerdedor = MatchResult::where('match_id', $match->id)->where('player_id', $perdedor->id)->first();

    // Se le quiso restar, no se pudo, y las dos cosas quedan anotadas.
    expect((float) $filaPerdedor->pl_change)->toBe(0.0)
        ->and($filaPerdedor->scoring_context['pl_change_theoretical'])->toBeLessThan(0);

    $perdedor->refresh();
    expect((float) $perdedor->pl_points)->toBe(0.0);
});

it('nadie sube de puesto en el ladder por perder', function () {
    // La comprobacion que le importa a quien mira la tabla: si tu unica partida
    // la perdiste, no puedes tener puntos.
    $ganador = consistencyPlayer('arriba', 'ignis', 10.0, 1100);
    $perdedor = consistencyPlayer('abajo', 'alsius', 0.0, 1000);

    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-9201',
        'report_token' => 'CONSIST002',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => [$pack($ganador)],
        'team_b' => [$pack($perdedor)],
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'estimated_mmr_avg' => 1050,
        'player_count' => 2,
    ]);

    app(ArenaMatchResultService::class)->forceComplete($match, 'team_a');
    $perdedor->refresh();

    expect((float) $perdedor->pl_points)->toBe(0.0)
        ->and($perdedor->losses)->toBe(1)
        ->and($perdedor->wins)->toBe(0);
});
