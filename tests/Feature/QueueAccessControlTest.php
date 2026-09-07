<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects guests away from protected queue and party actions', function () {
    // Los invitados van directo al OAuth de Discord, no a /login: asi lo
    // configura bootstrap/app.php con redirectGuestsTo(). La ruta /login existe
    // pero solo reenvia al mismo sitio, asi que se evita el salto intermedio.
    $authRedirect = route('auth.discord');

    $this->post(route('queue.join'), [])
        ->assertRedirect($authRedirect);

    $this->post(route('party.create'), [])
        ->assertRedirect($authRedirect);

    $this->post(route('matches.accept'), [])
        ->assertRedirect($authRedirect);
});

it('keeps /login pointing at the discord oauth entrypoint', function () {
    // Si alguien cambia el destino de /login, el test de arriba dejaria de
    // reflejar el flujo real de autenticacion.
    $this->get(route('login'))->assertRedirect(route('auth.discord'));
});
