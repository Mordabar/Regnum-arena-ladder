<?php

use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Un enfrentamiento caido se puede leer si ya es historial.
 *
 * La vista mandaba al lobby a los 3,5 segundos siempre que el estado fuera
 * 'cancelled' o 'void'. Sirve cuando el combate se acaba de deshacer y el
 * jugador sigue en su flujo, pero desde el historial no dejaba leer nada: se
 * entraba y te echaba. Se nota mas desde que moderacion puede anular
 * enfrentamientos ya puntuados, porque esos son justo los que hay que mirar.
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

function enfrentamientoCaido(string $estado, $cuando, string $marca = ''): array
{
    $marca = $estado . $marca;
    $mio = jugadorHistorial('mio-' . $marca, 'alsius');
    $rival = jugadorHistorial('rival-' . $marca, 'ignis');

    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-H' . strtoupper(substr(md5($marca), 0, 6)),
        'report_token' => strtoupper(substr(md5($marca), 0, 10)),
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

    // updated_at es lo que distingue "se acaba de caer" de "esto es historial".
    // Va por el query builder a proposito: saveQuietly() calla los eventos pero
    // sigue poniendo la marca de tiempo, asi que el valor se perderia.
    DB::table('matches')->where('id', $match->id)->update(['updated_at' => $cuando]);

    return ['match' => $match->fresh(), 'mio' => $mio];
}

it('no echa al lobby al abrir un enfrentamiento anulado del historial', function () {
    $s = enfrentamientoCaido('void', now()->subDays(3));

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Encuentro Anulado')
        // Ni el redirect ni la promesa de redirigir.
        ->assertDontSee('data-auto-lobby-redirect', false)
        ->assertDontSee('Serás redirigido al lobby');
});

it('tampoco al abrir uno cancelado hace tiempo', function () {
    $s = enfrentamientoCaido('cancelled', now()->subHours(6));

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Encuentro Cancelado')
        ->assertDontSee('data-auto-lobby-redirect', false);
});

it('sigue devolviendo a la cola cuando el combate se acaba de caer', function () {
    // Aqui el jugador esta en mitad de su flujo: se le devuelve al lobby para
    // que vuelva a encolarse sin tener que buscar el boton.
    $s = enfrentamientoCaido('cancelled', now()->subMinute());

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Serás redirigido al lobby')
        ->assertSee('data-auto-lobby-redirect', false);
});

it('el enlace al lobby esta siempre, se redirija o no', function () {
    foreach ([now()->subMinute(), now()->subDays(3)] as $i => $cuando) {
        $s = enfrentamientoCaido("void", $cuando, (string) $i);

        $this->actingAs($s['mio']->user)
            ->get(route('matches.show', $s['match']))
            ->assertOk()
            ->assertSee('Volver al Lobby');

        $s['match']->delete();
    }
});
