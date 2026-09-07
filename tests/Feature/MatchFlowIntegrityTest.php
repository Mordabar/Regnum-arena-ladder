<?php

use App\Models\ArenaMatch;
use App\Models\Party;
use App\Models\PartyMember;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMaintenanceService;
use App\Services\ArenaMatchmakingService;
use App\Services\ArenaMatchResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regresiones del flujo de enfrentamientos halladas en la auditoria adversarial.
 * Cada test aqui fallaba antes del arreglo correspondiente.
 */

function flowUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'flow-' . $suffix,
        'discord_username' => 'flow_' . $suffix,
        'name' => 'Flow ' . $suffix,
        'email' => $suffix . '@flow.test',
    ]);
}

function flowPlayer(string $suffix, string $realm, ?User $user = null, string $subclass = 'knight'): Player
{
    return Player::create([
        'user_id' => ($user ?? flowUser($suffix))->id,
        'character_name' => 'Flow ' . $suffix,
        'subclass' => $subclass,
        'realm' => $realm,
        'pl_points' => 0,
        'mmr' => 1000,
        'matches_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function flowQueue(Player $player, string $mode = '2v2'): Queue
{
    return Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => $mode,
        'status' => 'waiting',
        'estimated_mmr' => $player->mmr,
        'joined_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
}

/** Crea un match 2v2 real pasando por el matchmaking. */
function flowMatch(string $tag): ArenaMatch
{
    foreach (range(1, 2) as $i) {
        flowQueue(flowPlayer($tag . '-ign-' . $i, 'ignis'));
        flowQueue(flowPlayer($tag . '-als-' . $i, 'alsius'));
    }

    expect(app(ArenaMatchmakingService::class)->processQueue())->toBe(1);

    return ArenaMatch::query()->latest('id')->firstOrFail();
}

it('no permite cancelar un match ya en curso rechazandolo', function () {
    // Antes: quien iba perdiendo hacia POST /matches/reject sobre un match
    // in_progress y lo cancelaba, esquivando la derrota sin penalizacion.
    $match = flowMatch('cancel');
    $match->update(['status' => 'in_progress']);

    app(ArenaMatchmakingService::class)->cancelMatch($match, 'player_rejected', null, false);

    expect($match->fresh()->status)->toBe('in_progress');
});

it('no permite cancelar un match en disputa', function () {
    // Un jugador no puede borrar su propia disputa antes de que el admin la vea.
    $match = flowMatch('disputa');
    $match->update(['status' => 'disputed']);

    app(ArenaMatchmakingService::class)->cancelMatch($match, 'player_rejected', null, false);

    expect($match->fresh()->status)->toBe('disputed');
});

it('rechaza por HTTP el intento de cancelar un match en curso', function () {
    $match = flowMatch('http-cancel');
    $match->update(['status' => 'in_progress']);

    $playerId = (int) $match->getTeamPlayerIds('team_a')[0];
    $player = Player::findOrFail($playerId);

    $this->actingAs($player->user)
        ->post(route('matches.reject'), ['match_id' => $match->id, 'player_id' => $player->id])
        ->assertSessionHasErrors();

    expect($match->fresh()->status)->toBe('in_progress');
});

it('no arma un equipo con dos personajes del mismo usuario', function () {
    // La rama premade ya lo validaba; la random no, y es alcanzable.
    $sharedUser = flowUser('doble-cuenta');
    flowQueue(flowPlayer('doble-a', 'ignis', $sharedUser));
    flowQueue(flowPlayer('doble-b', 'ignis', $sharedUser, 'hunter'));
    flowQueue(flowPlayer('rival-a', 'alsius'));
    flowQueue(flowPlayer('rival-b', 'alsius', null, 'hunter'));

    expect(app(ArenaMatchmakingService::class)->processQueue())->toBe(0);
    expect(ArenaMatch::query()->count())->toBe(0);
});

it('no empareja a un jugador con la cola bloqueada', function () {
    // cancelMatch reencola sin revalidar sanciones: el filtro va en el matchmaking.
    $locked = flowPlayer('bloqueado', 'ignis');
    $locked->update(['queue_locked_until' => now()->addHours(12), 'queue_lock_reason' => 'abandonment']);

    flowQueue($locked);
    flowQueue(flowPlayer('libre-ign', 'ignis', null, 'hunter'));
    flowQueue(flowPlayer('libre-als-a', 'alsius'));
    flowQueue(flowPlayer('libre-als-b', 'alsius', null, 'hunter'));

    expect(app(ArenaMatchmakingService::class)->processQueue())->toBe(0);
});

it('impide que un usuario tenga dos colas activas usando dos personajes', function () {
    // El chequeo era por personaje en el camino premade y por usuario en join().
    $user = flowUser('dos-colas');
    $first = flowPlayer('dos-colas-a', 'ignis', $user);
    $second = flowPlayer('dos-colas-b', 'ignis', $user, 'hunter');

    $this->actingAs($user)
        ->post(route('queue.join'), [
            'player_id' => $first->id,
            'queue_type' => 'random',
            'arena_mode' => '2v2',
        ])
        ->assertSessionHasNoErrors();

    // Segundo personaje de la MISMA cuenta: debe rechazarse.
    $this->actingAs($user)
        ->post(route('queue.join'), [
            'player_id' => $second->id,
            'queue_type' => 'random',
            'arena_mode' => '2v2',
        ])
        ->assertSessionHasErrors();

    expect(Queue::query()->whereIn('player_id', [$first->id, $second->id])
        ->whereIn('status', ['waiting', 'matched', 'accepted'])->count())->toBe(1);
});

it('libera una party atrapada en queued aunque le falten filas de cola', function () {
    // Antes se exigia coincidencia EXACTA del conjunto de jugadores: si faltaba
    // una cola (p.ej. el admin saco a un miembro), la party quedaba "buscando"
    // para siempre, sin colas y sin salida posible desde la interfaz.
    $leader = flowPlayer('atrapada-lider', 'ignis');
    $mate = flowPlayer('atrapada-mate', 'ignis', null, 'hunter');

    $party = Party::create([
        'leader_player_id' => $leader->id,
        'status' => 'queued',
        'realm' => 'ignis',
        'arena_mode' => '2v2',
    ]);

    foreach ([[$leader, true], [$mate, true]] as $index => [$player, $accepted]) {
        PartyMember::create([
            'party_id' => $party->id,
            'player_id' => $player->id,
            'is_accepted_invite' => $accepted,
            'is_leader' => $index === 0,
        ]);
    }

    // Solo UNA de las dos colas existe y ademas expirada: el conjunto no coincide.
    $queue = flowQueue($leader);
    $queue->update(['queue_type' => 'premade', 'team_id' => 'huerfano', 'expires_at' => now()->subMinute()]);

    app(ArenaMaintenanceService::class)->cleanupStaleWaitingQueues();

    expect($party->fresh()->status)->toBe('ready');
});

it('no toca una party queued cuyos miembros estan dentro de un match vivo', function () {
    $leader = flowPlayer('viva-lider', 'ignis');
    $mate = flowPlayer('viva-mate', 'ignis', null, 'hunter');

    $party = Party::create([
        'leader_player_id' => $leader->id,
        'status' => 'queued',
        'realm' => 'ignis',
        'arena_mode' => '2v2',
    ]);

    foreach ([$leader, $mate] as $index => $player) {
        PartyMember::create([
            'party_id' => $party->id,
            'player_id' => $player->id,
            'is_accepted_invite' => true,
            'is_leader' => $index === 0,
        ]);
        flowQueue($player)->update(['status' => 'matched']);
    }

    app(ArenaMaintenanceService::class)->releaseQueuedPartiesWithoutQueues();

    expect($party->fresh()->status)->toBe('queued');
});

it('no puntua un match cancelado aunque quede un reporte pendiente', function () {
    $match = flowMatch('sin-puntos');
    $match->update(['status' => 'cancelled']);

    expect(fn () => app(ArenaMatchResultService::class)->forceComplete($match, 'team_a'))
        ->toThrow(RuntimeException::class);

    expect($match->results()->count())->toBe(0);
});

it('no manda a disputa un match ya puntuado', function () {
    // Si lo hiciera, sus puntos seguirian en el ladder pero el match
    // desapareceria de los listados, sin forma de revertirlos.
    $match = flowMatch('ya-puntuado');
    $match->update(['status' => 'in_progress']);

    app(ArenaMatchResultService::class)->forceComplete($match, 'team_a');
    expect($match->fresh()->results()->count())->toBe(4);

    expect(fn () => app(ArenaMatchResultService::class)->markDisputed($match->fresh()))
        ->toThrow(RuntimeException::class);
});

it('no castiga dos veces por el mismo abandono', function () {
    $match = flowMatch('doble-castigo');
    $match->update(['status' => 'in_progress']);

    $offenderId = (int) $match->getTeamPlayerIds('team_a')[0];
    $service = app(ArenaMatchResultService::class);

    $service->applyAbandonmentWalkover($match->fresh(), $offenderId);
    $afterFirst = Player::findOrFail($offenderId);
    $strikesFirst = (int) $afterFirst->penalty_strikes;
    $trustFirst = (int) $afterFirst->trust_score;

    // Reenvio del mismo formulario (doble clic, reintento, dos admins).
    $service->applyAbandonmentWalkover($match->fresh(), $offenderId);
    $afterSecond = Player::findOrFail($offenderId);

    expect((int) $afterSecond->penalty_strikes)->toBe($strikesFirst)
        ->and((int) $afterSecond->trust_score)->toBe($trustFirst);
});

it('exige un ganador explicito al cerrar a mano un match sin reporte', function () {
    // Antes caia en 'team_a' por defecto y repartia PL a un equipo elegido
    // por el orden de las columnas.
    $match = flowMatch('sin-ganador');
    $match->update(['status' => 'disputed']);

    $this->withSession([
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ])->post(route('admin.matches.resolve', $match), ['action' => 'force_complete'])
        ->assertSessionHasErrors();

    expect($match->fresh()->results()->count())->toBe(0);
});
