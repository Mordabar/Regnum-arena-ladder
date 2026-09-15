<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * La columna `status` tiene que aceptar todos los estados que la aplicacion usa.
 *
 * En produccion no los aceptaba: `matches.status` era un ENUM heredado sin
 * 'disputed', y MySQL respondia "Data truncated for column 'status'" al
 * rechazar un reporte. Ningun test lo vio porque el banco corre en SQLite, que
 * guarda cualquier texto. Esto escribe estado por estado y lo relee, asi que en
 * una base MySQL el fallo sale aqui y no en manos de un jugador.
 */
function jugadorDeEstados(string $sufijo): Player
{
    $user = User::create([
        'discord_id' => 'estados-' . $sufijo,
        'discord_username' => 'estados_' . $sufijo,
        'name' => 'Estados ' . $sufijo,
        'email' => 'estados-' . $sufijo . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Estados ' . $sufijo,
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

function enfrentamientoDeEstados(): ArenaMatch
{
    $a = jugadorDeEstados('a');
    $b = jugadorDeEstados('b');
    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user->discord_id,
    ];

    return ArenaMatch::create([
        'match_code' => 'ARENA-ESTADOS',
        'report_token' => 'ESTADOS001',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => [$pack($a)],
        'team_b' => [$pack($b)],
        'zone' => 'frozen_bridge',
        'status' => 'pending_acceptance',
        'player_count' => 2,
        'estimated_mmr_avg' => 1000,
    ]);
}

it('guarda cualquiera de los estados de un enfrentamiento', function () {
    $match = enfrentamientoDeEstados();

    foreach (array_keys(ArenaMatch::STATUSES) as $estado) {
        $match->update(['status' => $estado]);

        expect($match->fresh()->status)->toBe($estado);
    }
});

it('guarda cualquiera de los estados de un reporte', function () {
    $match = enfrentamientoDeEstados();

    $reporte = MatchReport::create([
        'match_id' => $match->id,
        'reported_by_player_id' => collect($match->team_a)->first()['player_id'],
        'reporting_team' => 'team_a',
        'claimed_winner_team' => 'team_a',
        'claimed_winner_realm' => 'ignis',
        'status' => 'pending_confirmation',
        'final_screenshot_path' => 'match-reports/testing/estados/uno.png',
        'encounter_screenshot_path' => 'match-reports/testing/estados/uno.png',
    ]);

    foreach (array_keys(MatchReport::STATUSES) as $estado) {
        $reporte->update(['status' => $estado]);

        expect($reporte->fresh()->status)->toBe($estado);
    }
});

it('no deja la columna como un ENUM al que le falten estados', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        // SQLite guarda texto libre; la comprobacion real solo aplica a MySQL.
        expect(Schema::hasColumn('matches', 'status'))->toBeTrue();

        return;
    }

    $porTabla = [
        'matches' => array_keys(ArenaMatch::STATUSES),
        'match_reports' => array_keys(MatchReport::STATUSES),
    ];

    foreach ($porTabla as $tabla => $estados) {
        $tipo = strtolower((string) (collect(Schema::getColumns($tabla))->firstWhere('name', 'status')['type'] ?? ''));

        // Con `continue` a secas, el caso bueno -la columna ya es texto- no
        // afirmaba nada, y el test salia como "arriesgado" en MySQL: pasaba sin
        // comprobar una sola cosa, que es la forma mas silenciosa de no probar
        // nada. Se afirma tambien el caso bueno.
        if (!str_starts_with($tipo, 'enum(')) {
            expect($tipo)->not->toBe('');

            continue;
        }

        preg_match_all("/'([^']*)'/", $tipo, $coincidencias);

        expect(array_diff($estados, $coincidencias[1] ?? []))->toBe([]);
    }
});
