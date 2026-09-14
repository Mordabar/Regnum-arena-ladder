<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El rastro del arbitraje se ve en el propio enfrentamiento.
 *
 * Se guardaba todo -quien rechazo el reporte, con que motivo, con que capturas,
 * y que dijo moderacion al resolver- pero la pantalla del jugador no pintaba
 * nada de eso. Ante una queja no habia nada que enseñar sin entrar a la base de
 * datos. El panel de admin si lo mostraba; el hueco estaba solo del lado de
 * quien jugo la partida.
 */
function jugadorExpediente(string $sufijo, string $reino): Player
{
    $user = User::create([
        'discord_id' => 'exp-' . $sufijo,
        'discord_username' => 'exp_' . $sufijo,
        'name' => 'Exp ' . $sufijo,
        'email' => 'exp-' . $sufijo . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Exp' . ucfirst($sufijo),
        'subclass' => 'knight',
        'realm' => $reino,
        'pl_points' => 10,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

/**
 * Un enfrentamiento que se reporto, se rechazo y acabo anulado.
 *
 * @param  array<string, mixed>  $extra  Campos del reporte a pisar.
 */
function enfrentamientoConExpediente(array $extra = [], string $estado = 'void'): array
{
    $mio = jugadorExpediente('mio' . md5(serialize($extra) . $estado), 'alsius');
    $rival = jugadorExpediente('rival' . md5(serialize($extra) . $estado), 'ignis');

    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-X' . strtoupper(substr(md5(serialize($extra) . $estado), 0, 5)),
        'report_token' => strtoupper(substr(md5(serialize($extra) . $estado . 'tok'), 0, 10)),
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'alsius',
        'team_b_realm' => 'ignis',
        'team_a' => [$pack($mio)],
        'team_b' => [$pack($rival)],
        'zone' => 'frozen_bridge',
        'status' => $estado,
        'estimated_mmr_avg' => 1000,
        'player_count' => 2,
        'completed_at' => now()->subDay(),
    ]);

    MatchReport::create(array_merge([
        'match_id' => $match->id,
        'reported_by_player_id' => $rival->id,
        'reporting_team' => 'team_b',
        'claimed_winner_team' => 'team_b',
        'claimed_winner_realm' => 'ignis',
        'status' => 'voided',
        'final_screenshot_path' => 'match-reports/testing/exp/final.png',
        'encounter_screenshot_path' => 'match-reports/testing/exp/enc.png',
        'rejected_by_player_id' => $mio->id,
        'rejected_at' => now()->subHours(20),
        'rejection_note' => 'El marcador de la captura es de otra partida',
        'rejection_evidence_paths' => ['match-reports/testing/exp/rechazo-1.png'],
        'reviewed_at' => now()->subHours(2),
        'admin_note' => 'Las dos capturas son del mismo combate pero de rondas distintas',
    ], $extra));

    return ['match' => $match->fresh(), 'mio' => $mio, 'rival' => $rival];
}

it('enseña el motivo del rechazo y la nota del admin a quien jugo la partida', function () {
    $s = enfrentamientoConExpediente();

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Expediente')
        ->assertSee('El marcador de la captura es de otra partida')
        ->assertSee('Resolución de moderación')
        ->assertSee('Las dos capturas son del mismo combate pero de rondas distintas');
});

it('deja abrir las capturas que subio quien rechazo', function () {
    $s = enfrentamientoConExpediente();
    $reporte = $s['match']->report;

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee(route('matches.report.evidence', ['report' => $reporte, 'slot' => 'rejection-1']), false);
});

it('el rival tambien ve el expediente completo', function () {
    // Las dos partes tienen que poder leer lo mismo: si solo lo viera quien
    // rechazo, la queja del otro seguiria sin respuesta.
    $s = enfrentamientoConExpediente();

    $this->actingAs($s['rival']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('El marcador de la captura es de otra partida')
        ->assertSee('Las dos capturas son del mismo combate pero de rondas distintas');
});

it('dice quien rechazo, sin romper el anonimato', function () {
    // En un enfrentamiento cerrado los nombres ya se pueden enseñar.
    $s = enfrentamientoConExpediente();

    $this->actingAs($s['rival']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Rechazado por')
        ->assertSee($s['mio']->character_name);
});

it('a quien rechazo se le dice que fue el', function () {
    $s = enfrentamientoConExpediente();

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Rechazado por');
});

it('no inventa expediente donde no lo hubo', function () {
    // Un reporte confirmado sin mas no tiene nada que contar.
    $s = enfrentamientoConExpediente([
        'status' => 'confirmed',
        'rejected_by_player_id' => null,
        'rejected_at' => null,
        'rejection_note' => null,
        'rejection_evidence_paths' => null,
        'reviewed_at' => null,
        'admin_note' => null,
    ], 'completed');

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertDontSee('data-arbitration-record', false);
});

it('aguanta un rechazo sin motivo escrito', function () {
    $s = enfrentamientoConExpediente([
        'rejection_note' => null,
        'rejection_evidence_paths' => null,
        'admin_note' => null,
    ]);

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Sin motivo escrito')
        ->assertSee('Resuelto sin comentario');
});

it('se ve mientras el enfrentamiento sigue en disputa', function () {
    // Es el momento en que mas falta hace: la queja esta viva y el rechazado
    // tiene que poder leer de que se le acusa antes de que nadie resuelva.
    $s = enfrentamientoConExpediente([
        'status' => 'disputed',
        'reviewed_at' => null,
        'admin_note' => null,
    ], 'disputed');

    $this->actingAs($s['rival']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Expediente')
        ->assertSee('El marcador de la captura es de otra partida');
});
