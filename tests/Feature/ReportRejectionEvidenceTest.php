<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function jugadorDisputa(string $sufijo, string $realm, string $subclass): Player
{
    $user = User::create([
        'discord_id' => 'disputa-' . $sufijo,
        'discord_username' => 'disputa_' . $sufijo,
        'name' => 'Disputa ' . $sufijo,
        'email' => 'disputa-' . $sufijo . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Disputa ' . $sufijo,
        'subclass' => $subclass,
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 1000,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function alineacionDisputa(Player ...$jugadores): array
{
    return collect($jugadores)->map(fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => $p->user->discord_id,
        'conjurer_role' => null,
    ])->all();
}

/**
 * Un enfrentamiento en curso con su reporte esperando respuesta, y las filas de
 * cola que de verdad tendria: sin ellas no se puede comprobar que los jugadores
 * quedan libres.
 *
 * @return array{0: ArenaMatch, 1: MatchReport, 2: Player, 3: Player}
 */
function enfrentamientoConReportePendiente(string $sufijo, ?int $minutosParaResponder = 15): array
{
    $reportero = jugadorDisputa($sufijo . '-a', 'ignis', 'knight');
    $rival = jugadorDisputa($sufijo . '-b', 'alsius', 'hunter');

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-' . strtoupper($sufijo),
        'report_token' => strtoupper($sufijo) . 'TOKEN',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'team_a' => alineacionDisputa($reportero),
        'team_b' => alineacionDisputa($rival),
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'player_count' => 2,
        'estimated_mmr_avg' => 1000,
        'started_at' => now(),
        // El barrido solo mira los que ya tienen reporte subido; sin esto el
        // enfrentamiento cae en el barrido de "nadie reporto" y se anula.
        'reported_at' => now(),
        'expires_at' => $minutosParaResponder === null ? null : now()->addMinutes($minutosParaResponder),
    ]);

    foreach ([$reportero, $rival] as $jugador) {
        Queue::create([
            'player_id' => $jugador->id,
            'queue_type' => 'random',
            'arena_mode' => '2v2',
            'status' => 'accepted',
            'match_id' => $match->id,
            'estimated_mmr' => $jugador->mmr,
            'joined_at' => now()->subMinutes(30),
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    $report = MatchReport::create([
        'match_id' => $match->id,
        'reported_by_player_id' => $reportero->id,
        'reporting_team' => 'team_a',
        'claimed_winner_team' => 'team_a',
        'claimed_winner_realm' => 'ignis',
        'status' => 'pending_confirmation',
        'evidence_paths' => ['match-reports/testing/' . $sufijo . '/uno.png'],
        'final_screenshot_path' => 'match-reports/testing/' . $sufijo . '/uno.png',
        'encounter_screenshot_path' => 'match-reports/testing/' . $sufijo . '/uno.png',
    ]);

    return [$match, $report, $reportero, $rival];
}

it('da el reporte por bueno cuando el rival deja pasar su plazo', function () {
    [$match, $report, $reportero, $rival] = enfrentamientoConReportePendiente('silencio');

    // Se agota el plazo sin que el rival diga nada.
    $match->update(['expires_at' => now()->subMinute()]);

    try {
        app(ArenaMatchResultService::class)->confirmReport($report->fresh(), $rival);
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toContain('se dio por bueno');
    }

    $report->refresh();
    $match->refresh();

    expect($report->status)->toBe('confirmed');
    expect($match->status)->not->toBe('disputed');
    expect($match->results()->count())->toBe(2);

    // Y el ganador es el que decia el reporte, no otro.
    expect($reportero->fresh()->wins)->toBe(1);
    expect($rival->fresh()->losses)->toBe(1);
});

it('el barrido de plazos vencidos tampoco manda nada a disputa', function () {
    [$match, $report] = enfrentamientoConReportePendiente('barrido');
    $match->update(['expires_at' => now()->subMinutes(5)]);

    $resumen = app(ArenaMatchResultService::class)->sweepPostMatchState();

    expect($report->fresh()->status)->toBe('confirmed');
    expect($match->fresh()->status)->not->toBe('disputed');
    expect($resumen)->toBeArray();
});

it('solo entra en disputa cuando el rival rechaza de verdad', function () {
    [$match, $report, , $rival] = enfrentamientoConReportePendiente('rechazo');

    app(ArenaMatchResultService::class)->rejectReport($report, $rival, 'Esa no fue la partida');

    expect($report->fresh()->status)->toBe('rejected');
    expect($match->fresh()->status)->toBe('disputed');
    // Sin puntos: los reparte moderacion al resolver.
    expect($match->results()->count())->toBe(0);
});

it('guarda las capturas que aporta quien rechaza', function () {
    Storage::fake('arena_reports');

    [$match, $report, , $rival] = enfrentamientoConReportePendiente('pruebas');

    $respuesta = $this->actingAs($rival->user)->post(route('matches.report.reject'), [
        'report_id' => $report->id,
        'player_id' => $rival->id,
        'rejection_note' => 'Ganamos nosotros, aqui esta el final',
        'rejection_files' => [
            UploadedFile::fake()->image('mi-final-1.png', 1280, 720),
            UploadedFile::fake()->image('mi-final-2.png', 1280, 720),
        ],
    ]);

    $respuesta->assertRedirect(route('lobby', ['mode' => $match->fresh()->arena_mode]));

    $report->refresh();

    expect($report->status)->toBe('rejected');
    expect($report->rejection_evidence_paths)->toHaveCount(2);
    expect($report->rejectionEvidenceItems())->toHaveCount(2);

    foreach ($report->rejectionEvidencePaths() as $ruta) {
        Storage::disk('arena_reports')->assertExists($ruta);
    }

    // Las del rechazo no se mezclan con las del reporte.
    expect($report->evidenceItems())->toHaveCount(1);
});

it('sirve las capturas del rechazo a los del enfrentamiento y a nadie mas', function () {
    Storage::fake('arena_reports');

    [, $report, $reportero, $rival] = enfrentamientoConReportePendiente('acceso');
    $ajeno = jugadorDisputa('ajeno', 'syrtis', 'warlock');

    $this->actingAs($rival->user)->post(route('matches.report.reject'), [
        'report_id' => $report->id,
        'player_id' => $rival->id,
        'rejection_note' => 'No fue asi',
        'rejection_files' => [UploadedFile::fake()->image('prueba.png', 800, 600)],
    ]);

    $url = $report->fresh()->evidenceUrl('rejection-1');

    expect($url)->not->toBeNull();

    // Quien reporto tiene que poder ver con que le rebaten.
    $this->actingAs($reportero->user)->get($url)->assertOk();
    $this->actingAs($ajeno->user)->get($url)->assertForbidden();
});

it('deja rechazar sin capturas, para quien no tomo ninguna', function () {
    Storage::fake('arena_reports');

    [$match, $report, , $rival] = enfrentamientoConReportePendiente('sin-pruebas');

    $this->actingAs($rival->user)->post(route('matches.report.reject'), [
        'report_id' => $report->id,
        'player_id' => $rival->id,
        'rejection_note' => 'No tengo captura pero no fue asi',
    ])->assertRedirect();

    $report->refresh();

    expect($report->status)->toBe('rejected');
    expect($report->rejection_evidence_paths)->toBeNull();
    expect($report->rejectionEvidenceItems())->toBe([]);
    expect($match->fresh()->status)->toBe('disputed');
});

it('no admite mas de tres capturas en el rechazo', function () {
    Storage::fake('arena_reports');

    [, $report, , $rival] = enfrentamientoConReportePendiente('demasiadas');

    $this->actingAs($rival->user)->post(route('matches.report.reject'), [
        'report_id' => $report->id,
        'player_id' => $rival->id,
        'rejection_note' => 'Cuatro capturas',
        'rejection_files' => [
            UploadedFile::fake()->image('1.png'),
            UploadedFile::fake()->image('2.png'),
            UploadedFile::fake()->image('3.png'),
            UploadedFile::fake()->image('4.png'),
        ],
    ])->assertSessionHasErrors('rejection_files');

    expect($report->fresh()->status)->toBe('pending_confirmation');
});

it('la red de seguridad manda a disputa cuando el silencio no se puede puntuar', function () {
    // El caso que el barrido no sabia atender: el plazo vence, pero puntuar
    // falla porque el enfrentamiento ya tiene resultados. Antes el reporte se
    // quedaba en memoria como confirmado, la guardia del rescate lo veia asi y
    // se iba sin hacer nada: el enfrentamiento no llegaba nunca a moderacion y
    // los dos jugadores seguian atrapados en su fila de cola.
    [$match, $report, $reportero] = enfrentamientoConReportePendiente('atascado');
    $match->update(['expires_at' => now()->subMinutes(5)]);

    \App\Models\MatchResult::create([
        'match_id' => $match->id,
        'player_id' => $reportero->id,
        'result' => 'win',
        'pl_change' => 3.0,
        'mmr_change' => 16,
        'pl_before' => 0.0,
        'pl_after' => 3.0,
        'mmr_before' => 1000,
        'mmr_after' => 1016,
        'created_at' => now(),
    ]);

    app(ArenaMatchResultService::class)->sweepPostMatchState();

    expect($match->fresh()->status)->toBe('disputed');
    expect($report->fresh()->status)->toBe('disputed');

    // Y sobre todo: los jugadores quedan libres para volver a encolar.
    expect(Queue::where('match_id', $match->id)->whereIn('status', ['matched', 'accepted'])->count())->toBe(0);
});

it('exige un motivo para rechazar', function () {
    [, $report, , $rival] = enfrentamientoConReportePendiente('sin-motivo');

    $this->actingAs($rival->user)->post(route('matches.report.reject'), [
        'report_id' => $report->id,
        'player_id' => $rival->id,
        'rejection_note' => '',
    ])->assertSessionHasErrors('rejection_note');

    expect($report->fresh()->status)->toBe('pending_confirmation');
});

it('al borrar a un jugador tambien se van las capturas de su rechazo', function () {
    Storage::fake('arena_reports');

    [, $report, , $rival] = enfrentamientoConReportePendiente('borrado');

    $this->actingAs($rival->user)->post(route('matches.report.reject'), [
        'report_id' => $report->id,
        'player_id' => $rival->id,
        'rejection_note' => 'Con prueba',
        'rejection_files' => [UploadedFile::fake()->image('prueba.png', 800, 600)],
    ]);

    $ruta = $report->fresh()->rejectionEvidencePaths()[0];
    Storage::disk('arena_reports')->assertExists($ruta);

    app(\App\Services\PlayerCleanupService::class)->purgePlayer($rival->fresh());

    Storage::disk('arena_reports')->assertMissing($ruta);
});

it('rechaza igual en una base a la que le falta la migracion', function () {
    Storage::fake('arena_reports');

    // Exactamente lo que pasa al desplegar sin correr las migraciones: el
    // UPDATE reventaba y el rechazo entero se perdia. El jugador pulsaba,
    // volvia al lobby y el enfrentamiento seguia igual, sin pasar a disputa.
    Schema::table('match_reports', function ($tabla) {
        $tabla->dropColumn('rejection_evidence_paths');
    });

    [$match, $report, , $rival] = enfrentamientoConReportePendiente('sin-migrar');

    $this->actingAs($rival->user)->post(route('matches.report.reject'), [
        'report_id' => $report->id,
        'player_id' => $rival->id,
        'rejection_note' => 'No fue asi, y la base no esta migrada',
        'rejection_files' => [UploadedFile::fake()->image('prueba.png', 800, 600)],
    ])->assertSessionHasNoErrors();

    expect($report->fresh()->status)->toBe('rejected');
    expect($match->fresh()->status)->toBe('disputed');
});

it('devuelve el formulario de rechazo abierto y con el motivo del fallo dentro', function () {
    // El fallo se quedaba invisible: el aviso salia arriba del todo y el
    // formulario volvia cerrado, asi que desde el panel de combate parecia que
    // el boton no hacia nada.
    [, $report, , $rival] = enfrentamientoConReportePendiente('visible');

    $respuesta = $this->actingAs($rival->user)
        ->from(route('lobby'))
        ->followingRedirects()
        ->post(route('matches.report.reject'), [
            'report_id' => $report->id,
            'player_id' => $rival->id,
            'rejection_note' => 'no',
        ]);

    $respuesta->assertOk();
    // El motivo del fallo, dentro del propio formulario.
    $respuesta->assertSee('Cuenta un poco mas', false);
    // Y abierto, no escondido otra vez.
    expect($respuesta->getContent())->not->toContain('data-reject-form enctype="multipart/form-data" hidden');
    // Con lo que habia escrito, para no tener que teclearlo de nuevo.
    $respuesta->assertSee('>no</textarea>', false);
});
