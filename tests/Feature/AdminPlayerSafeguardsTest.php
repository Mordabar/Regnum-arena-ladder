<?php

use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function asArenaAdminSession(): array
{
    return [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];
}

function makeAdminUser(): User
{
    return User::create([
        'discord_id' => 'admin-user',
        'discord_username' => 'admin',
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'is_admin' => true,
    ]);
}

function makeManagedPlayer(): Player
{
    $user = User::create([
        'discord_id' => 'managed-user',
        'discord_username' => 'managed',
        'name' => 'Managed User',
        'email' => 'managed@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Managed Player',
        'subclass' => 'hunter',
        'realm' => 'syrtis',
        'pl_points' => 5.0,
        'mmr' => 800,
        'matches_played' => 3,
        'wins' => 2,
        'losses' => 1,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

it('prevents admins from deactivating a player with an active queue state', function () {
    $admin = makeAdminUser();
    $player = makeManagedPlayer();

    Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'status' => 'waiting',
        'estimated_mmr' => 800,
        'joined_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->withSession(asArenaAdminSession())
        ->actingAs($admin)
        ->from(route('admin.players.index'))
        ->post(route('admin.players.update', $player), [
            'action' => 'toggle_active',
        ])
        ->assertRedirect(route('admin.players.index'))
        ->assertSessionHasErrors(['error']);

    expect($player->fresh()->is_active)->toBeTrue();
});

it('allows admins to deactivate a player when no active queue exists', function () {
    $admin = makeAdminUser();
    $player = makeManagedPlayer();

    $this->withSession(asArenaAdminSession())
        ->actingAs($admin)
        ->from(route('admin.players.index'))
        ->post(route('admin.players.update', $player), [
            'action' => 'toggle_active',
        ])
        ->assertRedirect();

    expect($player->fresh()->is_active)->toBeFalse();
});

it('stores and clears manual queue lock metadata from the admin player actions', function () {
    $admin = makeAdminUser();
    $player = makeManagedPlayer();

    $this->withSession(asArenaAdminSession())
        ->actingAs($admin)
        ->from(route('admin.players.index'))
        ->post(route('admin.players.update', $player), [
            'action' => 'lock_12h',
        ])
        ->assertRedirect();

    $player->refresh();

    expect($player->queue_locked_until)->not->toBeNull();
    expect($player->queue_lock_reason)->toBe('manual_lock');
    expect($player->last_penalty_type)->toBe('manual_lock');

    $this->withSession(asArenaAdminSession())
        ->actingAs($admin)
        ->from(route('admin.players.index'))
        ->post(route('admin.players.update', $player), [
            'action' => 'unlock_queue',
        ])
        ->assertRedirect();

    $player->refresh();

    expect($player->queue_locked_until)->toBeNull();
    expect($player->queue_lock_reason)->toBeNull();
});
