<?php

use App\Models\AppSetting;
use App\Models\ArenaSeason;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\TestingLabService;
use App\Support\ArenaMode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArenaSeason::query()->update(['status' => ArenaSeason::STATUS_ARCHIVED]);
    ArenaSeason::create([
        'name' => 'En juego', 'slug' => 'en-juego-' . uniqid(), 'status' => ArenaSeason::STATUS_ACTIVE,
        'enabled_modes' => ['1v1'], 'starts_at' => now()->subWeek(),
    ]);
    foreach (ArenaMode::all() as $modo) {
        AppSetting::setValue(ArenaMode::settingKey($modo), $modo === '1v1' ? '1' : '0', 'modes', 'boolean', true);
    }
});

function botAmistoso(string $suffix, string $realm): Player
{
    $user = User::create([
        'discord_id' => TestingLabService::LAB_DISCORD_PREFIX . $suffix,
        'discord_username' => 'bot_' . $suffix, 'name' => 'Bot ' . $suffix,
        'email' => $suffix . '@' . TestingLabService::LAB_EMAIL_DOMAIN,
    ]);

    return Player::create([
        'user_id' => $user->id, 'character_name' => 'Bot' . $suffix, 'subclass' => 'knight',
        'realm' => $realm, 'pl_points' => 0, 'mmr' => 1000, 'trust_score' => 100, 'is_active' => true,
    ]);
}

it('el laboratorio mete bots en cola como amistosos o como competitivos', function () {
    botAmistoso('am1', 'ignis');
    botAmistoso('am2', 'ignis');
    botAmistoso('am3', 'ignis');

    $this->withSession(sesionDeAdmin())->post(route('admin.testing.enqueue-realm'), [
        'realm' => 'ignis', 'count' => 2, 'arena_mode' => '1v1', 'kind' => 'friendly',
    ])->assertSessionHasNoErrors();

    expect(Queue::query()->where('status', 'waiting')->pluck('is_ranked')->unique()->all())->toBe([false]);

    $this->withSession(sesionDeAdmin())->post(route('admin.testing.enqueue-realm'), [
        'realm' => 'ignis', 'count' => 1, 'arena_mode' => '1v1', 'kind' => 'ranked',
    ])->assertSessionHasNoErrors();

    expect(Queue::query()->where('is_ranked', true)->count())->toBe(1);
});

it('un bot suelto tambien se encola con el tipo elegido', function () {
    $bot = botAmistoso('am4', 'syrtis');

    $this->withSession(sesionDeAdmin())->post(route('admin.testing.toggle-bot'), [
        'player_id' => $bot->id, 'arena_mode' => '1v1', 'kind' => 'friendly',
    ])->assertSessionHasNoErrors();

    expect(Queue::query()->where('player_id', $bot->id)->value('is_ranked'))->toBeFalse();
});

it('la pagina del laboratorio ofrece el tipo de partida', function () {
    botAmistoso('am5', 'ignis');

    $this->withSession(sesionDeAdmin())->get(route('admin.testing'))
        ->assertOk()
        ->assertSee('Amistoso')
        ->assertSee('name="kind"', false);
});
