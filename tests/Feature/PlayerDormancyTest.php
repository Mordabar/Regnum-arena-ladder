<?php

use App\Models\AppSetting;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dormancyPlayer(string $suffix, ?string $lastSeen, bool $isActive = true): Player
{
    $user = User::create([
        'discord_id' => 'dorm-' . $suffix,
        'discord_username' => 'dorm_' . $suffix,
        'name' => 'Dorm ' . $suffix,
        'email' => 'dorm-' . $suffix . '@example.com',
    ]);

    // last_seen_at no es asignable en masa a proposito: solo lo escribe el
    // middleware de actividad, nunca datos que vengan de una peticion.
    $user->forceFill(['last_seen_at' => $lastSeen])->saveQuietly();

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Dorm' . $suffix,
        'subclass' => 'knight',
        'realm' => 'ignis',
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => $isActive,
    ]);
}

it('marca sin actividad a quien lleva mas de dos semanas sin entrar', function () {
    $ausente = dormancyPlayer('viejo', now()->subDays(15));
    $reciente = dormancyPlayer('nuevo', now()->subDays(3));

    expect($ausente->isDormant())->toBeTrue()
        ->and($reciente->isDormant())->toBeFalse();

    $dormidos = Player::query()->dormant()->pluck('id');
    expect($dormidos)->toContain($ausente->id)
        ->and($dormidos)->not->toContain($reciente->id);
});

it('cuenta como sin actividad a quien nunca registro una visita', function () {
    $nunca = dormancyPlayer('nunca', null);

    expect($nunca->isDormant())->toBeTrue()
        ->and(Player::query()->dormant()->pluck('id'))->toContain($nunca->id);
});

it('respeta el umbral configurado en el panel', function () {
    $player = dormancyPlayer('umbral', now()->subDays(10));

    expect($player->isDormant())->toBeFalse();

    AppSetting::setValue('inactive_after_days', 7, 'runtime', 'integer', false);

    expect(Player::dormancyDays())->toBe(7)
        ->and($player->fresh()->isDormant())->toBeTrue();
});

it('no confunde estar sin actividad con estar deshabilitado', function () {
    // Un personaje deshabilitado a mano que acaba de entrar NO esta dormido, y
    // uno habilitado que lleva un mes fuera SI lo esta. Son ejes distintos:
    // mezclarlos impediria volver a jugar a quien regresa.
    $deshabilitadoPresente = dormancyPlayer('off', now(), false);
    $habilitadoAusente = dormancyPlayer('on', now()->subMonth(), true);

    expect($deshabilitadoPresente->isDormant())->toBeFalse()
        ->and($habilitadoAusente->isDormant())->toBeTrue();

    $dormidos = Player::query()->dormant()->pluck('id');
    expect($dormidos)->not->toContain($deshabilitadoPresente->id)
        ->and($dormidos)->toContain($habilitadoAusente->id);

    expect(Player::query()->seenRecently()->pluck('id'))
        ->toContain($deshabilitadoPresente->id)
        ->not->toContain($habilitadoAusente->id);
});

it('anota la ultima visita al navegar y no reescribe en cada peticion', function () {
    $player = dormancyPlayer('visita', now()->subDays(20));
    $user = $player->user;

    $this->actingAs($user)->get(route('home'))->assertSuccessful();

    $user->refresh();
    expect($user->last_seen_at)->not->toBeNull()
        ->and($user->last_seen_at->diffInMinutes(now()))->toBeLessThan(1);

    // Segunda peticion inmediata: no debe volver a escribir.
    $marca = $user->last_seen_at->copy()->subMinutes(5);
    $user->forceFill(['last_seen_at' => $marca])->saveQuietly();

    $this->actingAs($user)->get(route('home'))->assertSuccessful();

    expect($user->fresh()->last_seen_at->timestamp)->toBe($marca->timestamp);
});

function dormancyAdminSession(): array
{
    return [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];
}

/** El formulario de reglas manda todos los campos de golpe: si falta uno, falla la validacion. */
function dormancySettingsPayload(int $days): array
{
    return [
        // Los interruptores de modalidad viajan siempre en el formulario: si
        // faltan, guardar el umbral apagaria las colas de paso.
        'mode_2v2_enabled' => '1',
        'mode_3v3_enabled' => '1',
        'season_name' => 'Alpha Season',
        'home_tagline' => 'Conquest PvP por reino y subclase',
        'rules_excerpt' => 'Random y premade con ladder automatico.',
        'support_contact' => '',
        'discord_invite_url' => '',
        'discord_server_label' => '',
        'accept_window_minutes' => 5,
        'hunt_window_minutes' => 30,
        'report_confirmation_window_minutes' => 15,
        'dispute_auto_void_hours' => 48,
        'premade_daily_limit' => 3,
        'random_vs_premade_pl_bonus_pct' => 25,
        'random_vs_premade_mmr_bonus_pct' => 18,
        'premade_vs_random_pl_win_penalty_pct' => 20,
        'premade_vs_random_mmr_win_penalty_pct' => 14,
        'abandonment_lock_hours' => 12,
        'support_infraction_lock_hours' => 24,
        'abandonment_trust_penalty' => 15,
        'support_infraction_trust_penalty' => 25,
        'penalty_max_lock_hours' => 96,
        'inactive_after_days' => $days,
    ];
}

it('guarda el umbral que escribe el admin en el formulario', function () {
    // Antes el campo se leia y se validaba, pero no se persistia: el admin veia
    // "Configuracion guardada" y al recargar seguia en 14.
    $this->withSession(dormancyAdminSession())
        ->post(route('admin.settings.update'), dormancySettingsPayload(21))
        ->assertRedirect();

    expect(Player::dormancyDays())->toBe(21);
});

it('el listado del panel filtra y etiqueta a los que no aparecen', function () {
    $ausente = dormancyPlayer('panel-viejo', now()->subDays(30));
    $presente = dormancyPlayer('panel-nuevo', now()->subHours(2));

    $todos = $this->withSession(dormancyAdminSession())->get(route('admin.players.index'));
    $todos->assertOk()
        ->assertSee($ausente->character_name)
        ->assertSee($presente->character_name)
        ->assertSee('Sin actividad');

    $filtrado = $this->withSession(dormancyAdminSession())
        ->get(route('admin.players.index', ['status' => 'dormant']));

    $filtrado->assertOk()
        ->assertSee($ausente->character_name)
        ->assertDontSee($presente->character_name);
});

it('el backfill usa la ultima entrada en cola y no la fecha de registro', function () {
    // Quien lleva anos registrado pero encolo anteayer no es un ausente. Dar a
    // todos la fecha de alta marcaria media base como "sin actividad" el mismo
    // dia del despliegue.
    $veterano = dormancyPlayer('veterano', null);
    $veterano->user->forceFill(['created_at' => now()->subYear()])->saveQuietly();

    App\Models\Queue::create([
        'player_id' => $veterano->id,
        'queue_type' => 'random',
        'arena_mode' => '2v2',
        'status' => 'cancelled',
        'estimated_mmr' => 1000,
        'joined_at' => now()->subDays(2),
        'expires_at' => now()->subDays(2)->addMinutes(30),
    ]);

    $fantasma = dormancyPlayer('fantasma', null);
    $fantasma->user->forceFill(['created_at' => now()->subYear()])->saveQuietly();

    // Volver a lanzar la migracion solo rellena las filas que siguen en null:
    // la columna ya existe y el guard de hasColumn la deja intacta.
    $migracion = require database_path('migrations/2026_09_01_000001_add_last_seen_at_to_users.php');
    $migracion->up();

    expect($veterano->user->fresh()->last_seen_at->toDateString())
        ->toBe(now()->subDays(2)->toDateString())
        ->and($fantasma->user->fresh()->last_seen_at->toDateString())
        ->toBe(now()->subYear()->toDateString());

    expect($veterano->fresh()->isDormant())->toBeFalse()
        ->and($fantasma->fresh()->isDormant())->toBeTrue();
});
