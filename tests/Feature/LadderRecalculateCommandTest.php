<?php

use App\Models\ArenaMatch;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function recalcPlayer(string $suffix, array $stats = []): Player
{
    $user = User::create([
        'discord_id' => 'recalc-' . $suffix,
        'discord_username' => 'recalc_' . $suffix,
        'name' => 'Recalc ' . $suffix,
        'email' => 'recalc-' . $suffix . '@example.com',
    ]);

    return Player::create(array_merge([
        'user_id' => $user->id,
        'character_name' => 'Recalc' . $suffix,
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

it('deja a cero a quien tiene puntos sin haber jugado nada', function () {
    // El caso real: la purga le regalo puntos y quedo en el ladder por delante
    // de gente que si habia ganado partidas.
    $fantasma = recalcPlayer('fantasma', ['pl_points' => 4.4, 'mmr' => 984, 'wins' => 0, 'losses' => 1, 'matches_played' => 1]);

    $this->artisan('ladder:recalcular')->assertSuccessful();

    $fantasma->refresh();
    expect((float) $fantasma->pl_points)->toBe(0.0)
        ->and($fantasma->mmr)->toBe(1000)
        ->and($fantasma->losses)->toBe(0)
        ->and($fantasma->matches_played)->toBe(0);
});

it('respeta a quien si tiene enfrentamientos', function () {
    $veterano = recalcPlayer('veterano', ['pl_points' => 999.0, 'mmr' => 5000, 'wins' => 40, 'losses' => 40, 'matches_played' => 80]);

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-9300',
        'report_token' => 'RECALC0001',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => [['player_id' => $veterano->id, 'character_name' => $veterano->character_name, 'subclass' => 'knight', 'realm' => 'ignis', 'discord_id' => '1']],
        'team_b' => [['player_id' => 999, 'character_name' => 'Rival', 'subclass' => 'hunter', 'realm' => 'alsius', 'discord_id' => '2']],
        'zone' => 'frozen_bridge',
        'status' => 'completed',
        'estimated_mmr_avg' => 1000,
        'player_count' => 2,
    ]);

    MatchResult::create([
        'match_id' => $match->id,
        'player_id' => $veterano->id,
        'result' => 'win',
        'pl_change' => 3.0,
        'mmr_change' => 16,
        'pl_before' => 0.0,
        'pl_after' => 3.0,
        'mmr_before' => 1000,
        'mmr_after' => 1016,
        'created_at' => now(),
    ]);

    $this->artisan('ladder:recalcular')->assertSuccessful();

    $veterano->refresh();
    // Se queda con lo que dice su ultima partida, ni mas ni menos.
    expect((float) $veterano->pl_points)->toBe(3.0)
        ->and($veterano->mmr)->toBe(1016)
        ->and($veterano->wins)->toBe(1)
        ->and($veterano->losses)->toBe(0)
        ->and($veterano->matches_played)->toBe(1);
});

it('en ensayo no toca nada', function () {
    $fantasma = recalcPlayer('ensayo', ['pl_points' => 7.2, 'matches_played' => 0]);

    $this->artisan('ladder:recalcular', ['--dry-run' => true])->assertSuccessful();

    $fantasma->refresh();
    expect((float) $fantasma->pl_points)->toBe(7.2);
});
