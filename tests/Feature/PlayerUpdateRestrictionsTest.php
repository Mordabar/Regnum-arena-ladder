<?php

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('only allows renaming a character and keeps the subclass immutable', function () {
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
            'subclass' => 'warlock',
        ])
        ->assertRedirect(route('lobby'))
        ->assertSessionHas('success');

    $player->refresh();

    expect($player->character_name)->toBe('Nuevo Nombre');
    expect($player->subclass)->toBe('hunter');
});
