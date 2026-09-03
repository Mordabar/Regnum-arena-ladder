<?php

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El panel del lobby se sirve suelto para que el sondeo lo cambie en su sitio
 * en vez de recargar la pagina entera.
 */
function fragmentUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'frag-' . $suffix,
        'discord_username' => 'frag_' . $suffix,
        'name' => 'Frag ' . $suffix,
        'email' => 'frag-' . $suffix . '@example.com',
    ]);
}

function fragmentPlayer(User $user, string $name): Player
{
    return Player::create([
        'user_id' => $user->id,
        'character_name' => $name,
        'subclass' => 'hunter',
        'realm' => 'syrtis',
        'race' => Player::defaultRace('syrtis'),
        'gender' => 'male',
        'pl_points' => 30,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

it('devuelve el panel suelto, sin el resto de la pagina', function () {
    $user = fragmentUser('a');
    fragmentPlayer($user, 'Sylwen');

    $response = $this->actingAs($user)->getJson(route('lobby.console'))->assertOk();

    $html = $response->json('html');

    expect($html)->toContain('arena-console')
        ->and($html)->toContain('data-champion-slot')
        ->and($html)->not->toContain('<!DOCTYPE html>')
        ->and($response->json('title'))->toBe('Lobby — Regnum Arena Ladder')
        // La cabecera viaja con el panel: dice en que punto esta el jugador, y
        // si no cambiara seguiria anunciando el estado anterior.
        ->and($response->json('head'))->toContain('data-console-head');
});

it('los modales del panel viajan aparte, porque el trozo suelto no tiene donde volcarlos', function () {
    // Sin esto, "Armar grupo premade" y las reglas de cola desaparecerian en
    // cuanto el sondeo repintara el panel.
    $user = fragmentUser('b');
    fragmentPlayer($user, 'Reliquia');

    $modals = $this->actingAs($user)->getJson(route('lobby.console'))->assertOk()->json('modals');

    expect($modals)->toContain('modal-arena-rules');
});

it('sin guerreros pide recargar, porque lo que cambia no es solo el panel', function () {
    $user = fragmentUser('c');

    $this->actingAs($user)->getJson(route('lobby.console'))
        ->assertOk()
        ->assertJson(['reload' => true]);
});

it('el panel no se sirve a quien no ha entrado', function () {
    $this->getJson(route('lobby.console'))->assertUnauthorized();
});

it('el lobby le dice al sondeo donde pedir el panel', function () {
    $user = fragmentUser('d');
    fragmentPlayer($user, 'Aldana');

    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        ->assertSee(str_replace('/', '\\/', route('lobby.console')), false)
        ->assertSee('data-console-modals', false);
});

it('la nube de invitaciones viaja con el panel', function () {
    // El bug: llegaba una invitacion, sonaba el aviso, y la tarjeta no
    // aparecia hasta recargar la pagina porque la nube vive fuera del panel.
    $anfitrion = fragmentUser('e');
    $lider = fragmentPlayer($anfitrion, 'Lider');

    $invitado = fragmentUser('f');
    $companero = fragmentPlayer($invitado, 'Companero');

    $this->actingAs($anfitrion)
        ->from(route('lobby'))
        ->post(route('party.create'), [
            'party_player_ids' => [$lider->id, $companero->id],
            'party_conjurer_roles' => [null, null],
        ])
        ->assertRedirect(route('lobby'));

    $invites = $this->actingAs($invitado)->getJson(route('lobby.console'))->assertOk()->json('invites');

    expect($invites)->toContain('data-arena-invite')
        ->and($invites)->toContain('Lider');
});

it('el hueco de las invitaciones existe aunque no haya ninguna', function () {
    // Sin hueco no hay donde poner la que llegue: el sondeo cambia lo de
    // dentro, no crea el contenedor.
    $user = fragmentUser('g');
    fragmentPlayer($user, 'Solitario');

    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('data-console-invites', false);

    expect($this->actingAs($user)->getJson(route('lobby.console'))->json('invites'))
        ->toContain('data-console-invites');
});

it('plegar una invitacion no la contesta', function () {
    // La aspa de antes borraba la tarjeta y dejaba la invitacion viva: el
    // jugador se quedaba sin poder contestarla y sin poder recibir otra party.
    $anfitrion = fragmentUser('h');
    $lider = fragmentPlayer($anfitrion, 'Anfitrion');

    $invitado = fragmentUser('i');
    $companero = fragmentPlayer($invitado, 'Invitado');

    $this->actingAs($anfitrion)
        ->from(route('lobby'))
        ->post(route('party.create'), [
            'party_player_ids' => [$lider->id, $companero->id],
            'party_conjurer_roles' => [null, null],
        ]);

    $this->actingAs($invitado)->get(route('lobby'))
        ->assertOk()
        // Plegar es un boton del navegador, no un envio: aceptar y rechazar
        // siguen siendo las dos unicas formas de contestar.
        ->assertSee('data-invite-fold', false)
        ->assertSee('data-invite-unfold', false)
        ->assertSee(route('party.reject', ['party' => $companero->partyMembers()->first()->party_id, 'member' => $companero->partyMembers()->first()->id]), false);
});
