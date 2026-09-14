<?php

use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Un enfrentamiento caido se puede leer, se acabe de caer o no.
 *
 * La vista mandaba al lobby a los 3,5 segundos siempre que el estado fuera
 * 'cancelled' o 'void', asi que entrar a leer uno no servia de nada: te echaba.
 * Se nota mas desde que moderacion puede anular enfrentamientos ya puntuados,
 * porque esos son justo los que hay que mirar despues.
 *
 * El primer intento de arreglo miraba la hora -redirigir solo si acababa de
 * caer- y no valia: a un enfrentamiento recien anulado se entra a consultarlo
 * igual, y se comia el mismo salto. La marca del sondeo si distingue las dos
 * cosas, porque solo existe cuando la pagina se recargo sola.
 */
function jugadorHistorial(string $sufijo, string $reino): Player
{
    $user = User::create([
        'discord_id' => 'hist-' . $sufijo,
        'discord_username' => 'hist_' . $sufijo,
        'name' => 'Hist ' . $sufijo,
        'email' => 'hist-' . $sufijo . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Hist ' . $sufijo,
        'subclass' => 'knight',
        'realm' => $reino,
        'pl_points' => 10,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function enfrentamientoCaido(string $estado): array
{
    $mio = jugadorHistorial('mio-' . $estado, 'alsius');
    $rival = jugadorHistorial('rival-' . $estado, 'ignis');

    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-H' . strtoupper(substr(md5($estado), 0, 6)),
        'report_token' => strtoupper(substr(md5($estado), 0, 10)),
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
    ]);

    return ['match' => $match->fresh(), 'mio' => $mio];
}

it('el salto al lobby lo decide el navegador, no la hora del enfrentamiento', function () {
    // Recien anulado: da igual, porque quien entra puede venir a consultarlo.
    // El servidor no decide nada; manda la marca que deja el sondeo.
    $s = enfrentamientoCaido('void');

    $html = $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Encuentro Anulado')
        ->getContent();

    expect($html)->toContain("sessionStorage.getItem('arena:live-reload')");
    // Sin la marca se sale antes de programar nada.
    expect($html)->toContain('if (!enVivo) { return; }');
});

it('el aviso de redirigir nace oculto', function () {
    // Solo lo enciende el guion cuando confirma que la recarga fue en vivo.
    $s = enfrentamientoCaido('cancelled');

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Encuentro Cancelado')
        ->assertSee('<span data-lobby-notice hidden>', false);
});

it('un anulado se explica como anulado, no como cancelado', function () {
    $s = enfrentamientoCaido('void');

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Encuentro Anulado')
        ->assertSee('devolvió los puntos que repartió')
        ->assertDontSee('El combate se deshizo porque alguien se ausentó');
});

it('el enlace al lobby esta siempre, salte solo o no', function () {
    foreach (['void', 'cancelled'] as $estado) {
        $s = enfrentamientoCaido($estado);

        $this->actingAs($s['mio']->user)
            ->get(route('matches.show', $s['match']))
            ->assertOk()
            ->assertSee('Volver al Lobby');
    }
});

it('el sondeo deja la marca antes de recargar', function () {
    // La otra mitad del trato: sin esto el salto no ocurriria nunca, ni en el
    // caso en vivo, y el jugador se quedaria mirando un combate deshecho.
    $componente = file_get_contents(
        resource_path('views/components/arena-state-poller.blade.php')
    );

    expect($componente)->toContain("sessionStorage.setItem('arena:live-reload', '1')");

    $marca = strpos($componente, "setItem('arena:live-reload'");
    $recarga = strpos($componente, 'window.location.reload()');

    expect($marca)->toBeLessThan($recarga);
});
