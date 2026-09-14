<?php

/**
 * TESTS DE AUDITORIA - NO SON TESTS DE PRODUCTO.
 * Cada uno DEMUESTRA un fallo. Borrar tras leer el informe.
 */

use App\Models\ArenaMatch;
use App\Models\MatchAbandonmentReport;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\User;
use App\Services\ArenaAbandonmentService;
use App\Services\ArenaMatchResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function audPlayer(string $sufijo, string $reino = 'alsius'): Player
{
    $user = User::create([
        'discord_id' => 'aud-' . $sufijo,
        'discord_username' => 'aud_' . $sufijo,
        'name' => 'Aud ' . $sufijo,
        'email' => 'aud-' . $sufijo . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Aud' . ucfirst($sufijo),
        'subclass' => 'knight',
        'realm' => $reino,
        'pl_points' => 30,
        'mmr' => 1000,
        'trust_score' => 100,
        'penalty_strikes' => 0,
        'matches_played' => 0,
        'is_active' => true,
    ]);
}

function audCombate(string $marca): array
{
    $mio = audPlayer('mio' . $marca, 'alsius');
    $companero = audPlayer('comp' . $marca, 'alsius');
    $rival = audPlayer('riv' . $marca, 'ignis');
    $rival2 = audPlayer('riv2' . $marca, 'ignis');

    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    $match = ArenaMatch::create([
        'match_code' => 'AUD-' . strtoupper(substr(md5($marca), 0, 6)),
        'report_token' => strtoupper(substr(md5($marca . 'tk'), 0, 10)),
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'alsius',
        'team_b_realm' => 'ignis',
        'team_a' => [$pack($mio), $pack($companero)],
        'team_b' => [$pack($rival), $pack($rival2)],
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'estimated_mmr_avg' => 1000,
        'player_count' => 4,
        'started_at' => now()->subMinutes(10),
        'reported_at' => null,
    ]);

    return compact('match', 'mio', 'companero', 'rival', 'rival2');
}

/** Reporte de resultado ya subido por el rival, esperando confirmacion. */
function audReporteDeResultado(array $s, Player $reportero, string $ganador): MatchReport
{
    $report = MatchReport::create([
        'match_id' => $s['match']->id,
        'reported_by_player_id' => $reportero->id,
        'reporting_team' => $s['match']->getTeamSideForPlayer($reportero->id),
        'claimed_winner_team' => $ganador,
        'claimed_winner_realm' => $ganador === 'team_a' ? 'alsius' : 'ignis',
        'status' => 'pending_confirmation',
        'encounter_screenshot_path' => 'x.png',
        'final_screenshot_path' => 'x.png',
        'evidence_paths' => ['x.png'],
    ]);

    $s['match']->update([
        'reported_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    return $report;
}

it('AUDIT-1: el que va perdiendo borra la derrota reportando un abandono falso', function () {
    $s = audCombate('esc');

    // El rival (team_b) gana y lo reporta con capturas.
    audReporteDeResultado($s, $s['rival'], 'team_b');

    // El perdedor, en vez de confirmar o rechazar, avisa de un abandono falso.
    app(ArenaAbandonmentService::class)->report(
        $s['match']->fresh(), $s['mio'], $s['rival']->id, 'Se fue a mitad, mentira'
    );

    expect($s['match']->fresh()->status)->toBe('disputed');

    // Pasa el plazo de confirmacion Y el plazo de disputa. Nadie mira nada.
    $this->travel(50)->hours();
    app(ArenaMatchResultService::class)->sweepPostMatchState();

    $match = $s['match']->fresh();

    // El reporte legitimo del ganador NUNCA se puntuo: el barrido de
    // confirmaciones vencidas solo mira 'in_progress'. La disputa venció y el
    // enfrentamiento se anulo solo.
    expect($match->status)->toBe('void');
    expect($match->results()->count())->toBe(0);

    // Nadie gano ni perdio PL. El falso denunciante no paga nada.
    foreach (['mio', 'companero', 'rival', 'rival2'] as $q) {
        expect((float) $s[$q]->fresh()->pl_points)->toBe(30.0);
        expect($s[$q]->fresh()->penalty_strikes)->toBe(0);
    }
});

it('AUDIT-2: se confirma un abandono sobre un enfrentamiento YA puntuado', function () {
    $s = audCombate('dob');

    $report = audReporteDeResultado($s, $s['rival'], 'team_b');

    // El perdedor avisa de abandono -> disputed
    $aviso = app(ArenaAbandonmentService::class)->report(
        $s['match']->fresh(), $s['mio'], $s['rival']->id, 'Se desconecto'
    );

    // confirmReport NO mira el estado del match: puntua igual estando en disputa.
    app(ArenaMatchResultService::class)->confirmReport($report->fresh(), $s['mio']);

    $plTrasPuntuar = (float) $s['rival']->fresh()->pl_points;
    expect($s['match']->fresh()->results()->count())->toBe(4);

    // Y ahora el admin confirma el abandono pendiente. No comprueba resultados.
    app(ArenaAbandonmentService::class)->confirm($aviso->fresh(), null, 'ok');

    $match = $s['match']->fresh();

    // Estado incoherente: 'abandoned' PERO con MatchResults repartidos y PL
    // movido para los cuatro jugadores.
    expect($match->status)->toBe('abandoned');
    expect($match->results()->count())->toBe(4);

    // El acusado paga DOS veces: conserva el resultado del combate y encima
    // se le resta el castigo de abandono.
    expect((float) $s['rival']->fresh()->pl_points)->toBe($plTrasPuntuar - 2.0);
});

it('AUDIT-3: dos admins a la vez cobran la sancion dos veces', function () {
    $s = audCombate('rac');
    $servicio = app(ArenaAbandonmentService::class);

    $aviso = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');

    // Dos peticiones concurrentes: cada una cargo su propia instancia ANTES de
    // que la otra escribiera. No hay lockForUpdate ni UPDATE condicional.
    $copiaA = MatchAbandonmentReport::find($aviso->id);
    $copiaB = MatchAbandonmentReport::find($aviso->id);

    $servicio->confirm($copiaA);
    $servicio->confirm($copiaB);

    $culpable = $s['rival']->fresh();

    // -2 PL por confirmacion = -4, y dos strikes por el mismo abandono.
    expect((float) $culpable->pl_points)->toBe(26.0);
    expect($culpable->penalty_strikes)->toBe(2);
});

it('AUDIT-4: descartar resucita a in_progress con el plazo ya vencido y el match muere', function () {
    $s = audCombate('pla');
    $servicio = app(ArenaAbandonmentService::class);

    // Enfrentamiento en curso con su ventana de caza abierta.
    $s['match']->update(['expires_at' => now()->addMinutes(20)]);

    $aviso = $servicio->report($s['match']->fresh(), $s['mio'], $s['rival']->id, 'Creo que se fue');

    // El admin tarda en mirarlo (lo normal) y lo descarta: era falso.
    $this->travel(3)->hours();
    $servicio->dismiss($aviso->fresh(), null, 'Estaba jugando');

    $match = $s['match']->fresh();
    expect($match->status)->toBe('in_progress');

    // Pero expires_at nunca se renovo: sigue siendo la hora de hace 3 horas.
    expect($match->expires_at->isPast())->toBeTrue();

    // El siguiente barrido lo anula al instante. Los jugadores recuperan un
    // combate "en curso" que muere antes de poder reportarlo.
    app(ArenaMatchResultService::class)->sweepPostMatchState();
    expect($s['match']->fresh()->status)->toBe('void');
});

it('AUDIT-5: un solo jugador bloquea el enfrentamiento con un aviso por cada rival', function () {
    $s = audCombate('spa');
    $servicio = app(ArenaAbandonmentService::class);

    // El unico indice unico es (match, avisador, acusado): el mismo jugador
    // puede señalar a los otros tres.
    $servicio->report($s['match']->fresh(), $s['mio'], $s['rival']->id, 'Se fue uno');
    $servicio->report($s['match']->fresh(), $s['mio'], $s['rival2']->id, 'Se fue otro');
    $servicio->report($s['match']->fresh(), $s['mio'], $s['companero']->id, 'Y mi compi');

    expect(MatchAbandonmentReport::where('match_id', $s['match']->id)->count())->toBe(3);

    // El admin descarta dos: el match sigue congelado en disputa por el tercero.
    $avisos = MatchAbandonmentReport::where('match_id', $s['match']->id)->get();
    $servicio->dismiss($avisos[0], null, 'falso');
    $servicio->dismiss($avisos[1], null, 'falso');

    expect($s['match']->fresh()->status)->toBe('disputed');
});

it('AUDIT-6: el indice unico no impide avisos duplicados con avisador NULL', function () {
    $s = audCombate('nul');

    MatchAbandonmentReport::create([
        'match_id' => $s['match']->id,
        'reported_by_player_id' => null,
        'accused_player_id' => $s['rival']->id,
        'status' => 'pending',
    ]);

    MatchAbandonmentReport::create([
        'match_id' => $s['match']->id,
        'reported_by_player_id' => null,
        'accused_player_id' => $s['rival']->id,
        'status' => 'pending',
    ]);

    expect(MatchAbandonmentReport::where('match_id', $s['match']->id)->count())->toBe(2);
});

it('AUDIT-7: borrar el cruce deja huerfanos los avisos de abandono', function () {
    $s = audCombate('orf');

    $aviso = MatchAbandonmentReport::create([
        'match_id' => $s['match']->id,
        'reported_by_player_id' => $s['mio']->id,
        'accused_player_id' => $s['rival']->id,
        'status' => 'pending',
    ]);

    // match_abandonment_reports no declara clave foranea: borrar el match no
    // arrastra sus avisos (match_results y match_reports si tienen cascade).
    $s['match']->delete();

    expect(MatchAbandonmentReport::find($aviso->id))->not->toBeNull();
    expect(fn () => app(ArenaAbandonmentService::class)->confirm($aviso->fresh()))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
