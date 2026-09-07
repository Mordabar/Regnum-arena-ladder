<?php

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deja cambiar nombre, raza y sexo, pero nunca el reino ni la subclase', function () {
    $user = User::create([
        'discord_id' => 'player-update-user',
        'discord_username' => 'rename-user',
        'name' => 'Rename User',
        'email' => 'rename-user@example.com',
    ]);

    $player = Player::create([
        'user_id' => $user->id,
        'character_name' => 'Original Name',
        'subclass' => 'hunter',
        'realm' => 'ignis',
        'pl_points' => 0,
        'mmr' => 1000,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->put(route('player.update', $player), [
            'character_name' => 'Nuevo Nombre',
            'race' => 'molok',
            'gender' => 'female',
            // Estos dos no se leen: son los que deciden contra quien peleas y
            // como, y cambiarlos seria otro personaje con el historial de este.
            'subclass' => 'warlock',
            'realm' => 'syrtis',
        ])
        ->assertRedirect(route('lobby'))
        ->assertSessionHas('success');

    $player->refresh();

    expect($player->character_name)->toBe('Nuevo Nombre')
        ->and($player->race)->toBe('molok')
        ->and($player->gender)->toBe('female')
        ->and($player->subclass)->toBe('hunter')
        ->and($player->realm)->toBe('ignis');
});

it('no acepta una raza de otro reino', function () {
    $user = User::create([
        'discord_id' => 'player-race-user',
        'discord_username' => 'race-user',
        'name' => 'Race User',
        'email' => 'race-user@example.com',
    ]);

    $player = Player::create([
        'user_id' => $user->id,
        'character_name' => 'Ignita',
        'subclass' => 'knight',
        'realm' => 'ignis',
        'race' => 'esquelio',
        'gender' => 'male',
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->put(route('player.update', $player), [
            'character_name' => 'Ignita',
            // Enano es de Alsius.
            'race' => 'dwarf',
            'gender' => 'male',
        ])
        ->assertSessionHasErrors('race');

    expect($player->fresh()->race)->toBe('esquelio');
});
