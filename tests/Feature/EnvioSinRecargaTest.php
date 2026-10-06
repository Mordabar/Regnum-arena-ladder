<?php

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sinRecargaJugador(string $s): Player
{
    $user = User::create([
        'discord_id' => 'sr-' . $s, 'discord_username' => 'sr_' . $s,
        'name' => 'SR ' . $s, 'email' => 'sr-' . $s . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id, 'character_name' => 'Sin' . $s, 'subclass' => 'hunter',
        'realm' => 'syrtis', 'race' => Player::defaultRace('syrtis'), 'gender' => 'male',
        'pl_points' => 30, 'mmr' => 1000, 'trust_score' => 100, 'is_active' => true,
    ]);
}

it('un formulario enviado por detras recibe JSON con los avisos en vez de una redireccion', function () {
    $jugador = sinRecargaJugador('a');

    // Salir de una cola en la que no esta: el servidor contesta con un error y
    // vuelve atras, que es justo lo que antes recargaba la pagina.
    $respuesta = $this->actingAs($jugador->user)
        ->from(route('lobby'))
        ->withHeaders(['X-Arena-Sin-Recarga' => '1', 'X-Arena-Aqui' => '/lobby'])
        ->post(route('queue.leave'), ['player_id' => $jugador->id]);

    $respuesta->assertOk()->assertJsonPath('misma_ruta', true);
    expect($respuesta->json('redirect'))->toContain('/lobby')
        ->and($respuesta->json('avisos'))->not->toBeEmpty()
        ->and($respuesta->json('avisos.0.tipo'))->toBeIn(['error', 'success', 'warning', 'info']);
});

it('el aviso no se queda en la sesion si ya se entrego por JSON', function () {
    $jugador = sinRecargaJugador('b');

    $this->actingAs($jugador->user)
        ->from(route('lobby'))
        ->withHeaders(['X-Arena-Sin-Recarga' => '1', 'X-Arena-Aqui' => '/lobby'])
        ->post(route('queue.leave'), ['player_id' => $jugador->id])
        ->assertOk();

    // Si siguiera en la sesion saldria otra vez en la proxima pagina.
    $this->actingAs($jugador->user)->get(route('lobby'))
        ->assertOk()
        ->assertDontSee('arena-flash', false);
    expect(session()->has('error') || session()->has('success'))->toBeFalse();
});

it('si la accion lleva a otra pagina el aviso se conserva para esa pagina', function () {
    $jugador = sinRecargaJugador('c');

    // Se dice que se esta en otra ruta: el destino (/lobby) es distinto.
    $respuesta = $this->actingAs($jugador->user)
        ->from(route('lobby'))
        ->withHeaders(['X-Arena-Sin-Recarga' => '1', 'X-Arena-Aqui' => '/matches/1'])
        ->post(route('queue.leave'), ['player_id' => $jugador->id]);

    $respuesta->assertOk()->assertJsonPath('misma_ruta', false);
    expect($respuesta->json('avisos'))->toBe([]);
});

it('sin la cabecera la redireccion es la de siempre', function () {
    $jugador = sinRecargaJugador('d');

    $this->actingAs($jugador->user)
        ->from(route('lobby'))
        ->post(route('queue.leave'), ['player_id' => $jugador->id])
        ->assertRedirect();
});

it('las acciones del lobby se envian sin recargar y la pagina guarda el sitio', function () {
    $jugador = sinRecargaJugador('e');

    $this->actingAs($jugador->user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('data-sin-recarga', false)
        ->assertSee('arenaGuardarLugar', false)
        ->assertSee('arenaRecargar', false);
});

it('la pagina del combate se repinta en su sitio: el sondeo ya no recarga entera', function () {
    // El poller sin panel de lobby recargaba la pagina completa en cada cambio
    // de estado. Ahora pide la pagina y cambia el contenido.
    $fuente = file_get_contents(resource_path('views/components/arena-state-poller.blade.php'));

    expect($fuente)->toContain('const refreshPage')
        ->and($fuente)->toContain('window.arenaRefrescarPagina')
        ->and($fuente)->toContain("getElementById('contenido')");
});

it('los scripts de la pagina del combate se registran para volver a pasar tras un repintado', function () {
    $fuente = file_get_contents(resource_path('views/matches/show_v3.blade.php'));

    // Si volvieran a escucharse con DOMContentLoaded, el formulario del
    // reporte dejaria de funcionar tras el primer cambio de estado.
    expect($fuente)->not->toContain("document.addEventListener('DOMContentLoaded'")
        ->and($fuente)->toContain('window.ArenaBoot.register');
});
