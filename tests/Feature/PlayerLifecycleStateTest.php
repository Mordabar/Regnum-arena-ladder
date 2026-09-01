<?php

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lifecycleUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'life-' . $suffix,
        'discord_username' => 'life_' . $suffix,
        'name' => 'Life ' . $suffix,
        'email' => 'life-' . $suffix . '@example.com',
    ]);
}

function lifecyclePlayer(User $user, string $name, int $matches = 0): Player
{
    return Player::create([
        'user_id' => $user->id,
        'character_name' => $name,
        'subclass' => 'knight',
        'realm' => 'ignis',
        'pl_points' => 12.5,
        'mmr' => 1000,
        'matches_played' => $matches,
        'wins' => $matches,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function lifecycleAdminSession(): array
{
    return [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];
}

it('borrar un personaje con partidas lo marca eliminado y conserva sus datos', function () {
    $user = lifecycleUser('a');
    $veterano = lifecyclePlayer($user, 'Veterano', 7);
    lifecyclePlayer($user, 'Segundo');

    $this->actingAs($user)->delete(route('player.destroy', $veterano))->assertRedirect();

    $veterano->refresh();

    expect($veterano->exists)->toBeTrue()
        ->and($veterano->is_active)->toBeFalse()
        ->and($veterano->deactivated_reason)->toBe(Player::DEACTIVATED_BY_PLAYER)
        ->and($veterano->deactivated_at)->not->toBeNull()
        // Sus numeros siguen ahi: eliminar no borra el historial.
        ->and($veterano->matches_played)->toBe(7)
        ->and((float) $veterano->pl_points)->toBe(12.5)
        ->and($veterano->character_name)->toBe('Veterano' . Player::DELETED_NAME_SUFFIX)
        ->and($veterano->statusLabel())->toBe('Eliminado');
});

it('borrar un personaje sin partidas si lo elimina de verdad', function () {
    $user = lifecycleUser('b');
    $novato = lifecyclePlayer($user, 'Novato');
    lifecyclePlayer($user, 'Segundo');

    $this->actingAs($user)->delete(route('player.destroy', $novato))->assertRedirect();

    expect(Player::query()->find($novato->id))->toBeNull();
});

it('un personaje eliminado desaparece del lobby y del ranking de su dueno', function () {
    $user = lifecycleUser('c');
    $borrado = lifecyclePlayer($user, 'Fantasma', 3);
    lifecyclePlayer($user, 'Segundo');

    $this->actingAs($user)->delete(route('player.destroy', $borrado))->assertRedirect();

    // La primera carga lleva el aviso de "eliminado", que nombra al personaje.
    // Lo que importa es la siguiente: ahi ya no debe quedar rastro de el.
    $this->actingAs($user)->get(route('lobby'))->assertOk();
    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        ->assertDontSee('Fantasma');

    $this->get(route('ladder.index'))->assertOk()->assertDontSee('Fantasma');
});

it('el nombre queda libre para volver a crear el personaje', function () {
    $user = lifecycleUser('c2');
    $original = lifecyclePlayer($user, 'Renace', 3);
    lifecyclePlayer($user, 'Segundo');

    $this->actingAs($user)->delete(route('player.destroy', $original))->assertRedirect();

    $this->actingAs($user)
        ->post(route('player.register'), [
            'character_name' => 'Renace',
            'subclass' => 'hunter',
            'realm' => 'ignis',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $nuevo = Player::query()->where('character_name', 'Renace')->firstOrFail();

    expect($nuevo->id)->not->toBe($original->id)
        ->and($nuevo->matches_played)->toBe(0);
});

it('recuperar un eliminado es cosa del admin, no del jugador', function () {
    $user = lifecycleUser('c3');
    $veterano = lifecyclePlayer($user, 'Vuelve', 3);
    lifecyclePlayer($user, 'Segundo');

    $this->actingAs($user)->delete(route('player.destroy', $veterano))->assertRedirect();

    // El jugador ya no tiene ninguna via: la ruta de reactivar no existe.
    expect(fn () => route('player.reactivate', $veterano))
        ->toThrow(Symfony\Component\Routing\Exception\RouteNotFoundException::class);

    $this->withSession(lifecycleAdminSession())
        ->post(route('admin.players.update', $veterano), ['action' => 'restore_deleted'])
        ->assertRedirect();

    $veterano->refresh();

    expect($veterano->is_active)->toBeTrue()
        ->and($veterano->character_name)->toBe('Vuelve')
        ->and($veterano->deactivated_reason)->toBeNull()
        ->and($veterano->deactivated_at)->toBeNull()
        ->and($veterano->statusLabel())->toBe('Activo');
});

it('el admin no puede recuperar si el nombre ya lo ocupa otro personaje', function () {
    $user = lifecycleUser('c4');
    $original = lifecyclePlayer($user, 'Choque', 3);
    lifecyclePlayer($user, 'Segundo');

    $this->actingAs($user)->delete(route('player.destroy', $original))->assertRedirect();

    // El jugador rehizo el personaje con el mismo nombre mientras tanto.
    lifecyclePlayer($user, 'Choque');

    $this->withSession(lifecycleAdminSession())
        ->from(route('admin.players.index'))
        ->post(route('admin.players.update', $original), ['action' => 'restore_deleted'])
        ->assertRedirect()
        ->assertSessionHasErrors('error');

    expect($original->fresh()->is_active)->toBeFalse();
});

it('un eliminado no ocupa slot de personaje', function () {
    $user = lifecycleUser('c5');
    foreach (range(1, 5) as $i) {
        lifecyclePlayer($user, 'Slot' . $i, $i === 1 ? 3 : 0);
    }

    // Con los 5 slots llenos no deja crear.
    $this->actingAs($user)
        ->post(route('player.register'), ['character_name' => 'Sexto', 'subclass' => 'knight', 'realm' => 'ignis'])
        ->assertRedirect();
    expect(Player::query()->where('character_name', 'Sexto')->exists())->toBeFalse();

    $this->actingAs($user)->delete(route('player.destroy', $user->players()->first()))->assertRedirect();

    $this->actingAs($user)
        ->post(route('player.register'), ['character_name' => 'Sexto', 'subclass' => 'knight', 'realm' => 'ignis'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Player::query()->where('character_name', 'Sexto')->exists())->toBeTrue();
});

it('apagar desde el panel se llama deshabilitado, no eliminado', function () {
    $player = lifecyclePlayer(lifecycleUser('d'), 'DelPanel', 2);

    $this->withSession(lifecycleAdminSession())
        ->post(route('admin.players.update', $player), ['action' => 'toggle_active'])
        ->assertRedirect();

    $player->refresh();

    expect($player->is_active)->toBeFalse()
        ->and($player->deactivated_reason)->toBe(Player::DEACTIVATED_BY_ADMIN)
        ->and($player->statusLabel())->toBe('Deshabilitado')
        // El panel no le toca el nombre: eso es cosa de quien lo borra.
        ->and($player->character_name)->toBe('DelPanel');

    $this->withSession(lifecycleAdminSession())
        ->post(route('admin.players.update', $player), ['action' => 'toggle_active'])
        ->assertRedirect();

    expect($player->fresh()->deactivated_reason)->toBeNull();
});

it('la inactividad por no entrar nunca cambia si el personaje puede jugar', function () {
    // Es la distincion que pidio el usuario: llevar semanas sin entrar es una
    // metrica del ladder, no un castigo. Solo eliminar o deshabilitar apagan.
    $user = lifecycleUser('e');
    $user->forceFill(['last_seen_at' => now()->subMonths(6)])->saveQuietly();
    $player = lifecyclePlayer($user, 'Ausente', 4);

    expect($player->isDormant())->toBeTrue()
        ->and($player->is_active)->toBeTrue()
        ->and($player->statusLabel())->toBe('Activo');

    // Y de verdad puede volver a encolar: nada le bloquea por estar dormido.
    $this->actingAs($user)
        ->post(route('queue.join'), [
            'player_id' => $player->id,
            'queue_type' => 'random',
        ])
        ->assertRedirect();

    expect(App\Models\Queue::query()->where('player_id', $player->id)->where('status', 'waiting')->exists())
        ->toBeTrue();
});

it('el panel separa deshabilitados de eliminados', function () {
    $eliminado = lifecyclePlayer(lifecycleUser('f'), 'Borrado', 1);
    $eliminado->update([
        'is_active' => false,
        'deactivated_reason' => Player::DEACTIVATED_BY_PLAYER,
        'deactivated_at' => now(),
    ]);

    $deshabilitado = lifecyclePlayer(lifecycleUser('g'), 'Apagado', 1);
    $deshabilitado->update([
        'is_active' => false,
        'deactivated_reason' => Player::DEACTIVATED_BY_ADMIN,
        'deactivated_at' => now(),
    ]);

    $this->withSession(lifecycleAdminSession())
        ->get(route('admin.players.index', ['status' => 'deleted']))
        ->assertOk()
        ->assertSee('Borrado')
        ->assertDontSee('Apagado');

    $this->withSession(lifecycleAdminSession())
        ->get(route('admin.players.index', ['status' => 'disabled']))
        ->assertOk()
        ->assertSee('Apagado')
        ->assertDontSee('Borrado');
});

it('el backfill reetiqueta a los que quedaron marcados como INACTIVO', function () {
    // Filas anteriores al cambio: la unica pista de quien las apago es el
    // sufijo que se le ponia al nombre.
    $borrado = lifecyclePlayer(lifecycleUser('h'), 'Antiguo [INACTIVO]', 5);
    $borrado->update(['is_active' => false]);

    $apagado = lifecyclePlayer(lifecycleUser('i'), 'PorAdmin', 5);
    $apagado->update(['is_active' => false]);

    $migracion = require database_path('migrations/2026_09_01_000002_add_deactivation_reason_to_players.php');
    $migracion->up();

    expect($borrado->fresh()->deactivated_reason)->toBe(Player::DEACTIVATED_BY_PLAYER)
        ->and($borrado->fresh()->character_name)->toBe('Antiguo [ELIMINADO]')
        ->and($apagado->fresh()->deactivated_reason)->toBe(Player::DEACTIVATED_BY_ADMIN)
        ->and($apagado->fresh()->character_name)->toBe('PorAdmin');
});
