<?php

use App\Models\ArenaMatch;
use App\Models\MatchAbandonmentReport;
use App\Models\Player;
use App\Models\User;
use App\Services\ArenaAbandonmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Abandono: avisar no castiga, confirmar si, y solo a quien se fue.
 *
 * Un boton que sancione con el clic de un jugador se convierte en un arma el
 * primer dia, asi que la sancion pasa unicamente por el panel.
 *
 * Y el aviso tampoco decide el combate: no toca su estado. Mandarlo a disputa
 * -que fue el primer intento- sacaba el match de 'in_progress', el rival ya no
 * podia reportar su victoria y a las 48 horas se auto-anulaba. El acusado no
 * era sancionado, pero quien iba perdiendo convertia su derrota en un cero a
 * cero pulsando un boton. El combate sigue su curso y el aviso va en paralelo.
 */
function jugadorAbandono(string $sufijo, string $reino = 'alsius'): Player
{
    $user = User::create([
        'discord_id' => 'aban-' . $sufijo,
        'discord_username' => 'aban_' . $sufijo,
        'name' => 'Aban ' . $sufijo,
        'email' => 'aban-' . $sufijo . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => 'Aban' . ucfirst($sufijo),
        'subclass' => 'knight',
        'realm' => $reino,
        'pl_points' => 30,
        'mmr' => 1000,
        'trust_score' => 100,
        'penalty_strikes' => 0,
        'matches_played' => 0,
        'is_active' => true,
    ]);
}

/**
 * Un 2v2 en curso: yo y mi compañero contra dos rivales.
 *
 * @return array{match: ArenaMatch, mio: Player, companero: Player, rival: Player, rival2: Player}
 */
function combateEnCurso(string $marca = ''): array
{
    $mio = jugadorAbandono('mio' . $marca, 'alsius');
    $companero = jugadorAbandono('comp' . $marca, 'alsius');
    $rival = jugadorAbandono('riv' . $marca, 'ignis');
    $rival2 = jugadorAbandono('riv2' . $marca, 'ignis');

    $pack = fn (Player $p) => [
        'player_id' => $p->id,
        'character_name' => $p->character_name,
        'subclass' => $p->subclass,
        'realm' => $p->realm,
        'discord_id' => (string) $p->user_id,
    ];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-A' . strtoupper(substr(md5($marca), 0, 5)),
        'report_token' => strtoupper(substr(md5($marca . 'tok'), 0, 10)),
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'alsius',
        'team_b_realm' => 'ignis',
        'team_a' => [$pack($mio), $pack($companero)],
        'team_b' => [$pack($rival), $pack($rival2)],
        'zone' => 'frozen_bridge',
        'status' => 'in_progress',
        'estimated_mmr_avg' => 1000,
        'player_count' => 4,
        'started_at' => now()->subMinutes(10),
    ]);

    return compact('match', 'mio', 'companero', 'rival', 'rival2');
}

it('avisar no cambia el estado del combate ni toca un solo punto', function () {
    // Mandarlo a disputa era un agujero: con el match fuera de 'in_progress'
    // el rival ya no podia reportar su victoria y a las 48 horas se
    // auto-anulaba, asi que el boton servia para borrar una derrota. El
    // combate sigue su curso y el aviso viaja en paralelo.
    $s = combateEnCurso('a');

    app(ArenaAbandonmentService::class)->report(
        $s['match'], $s['mio'], $s['rival']->id, 'Se desconecto al minuto dos'
    );

    expect($s['match']->fresh()->status)->toBe('in_progress');

    // Nadie ha perdido nada todavia: aun no se ha revisado.
    foreach (['mio', 'companero', 'rival', 'rival2'] as $quien) {
        $p = $s[$quien]->fresh();
        expect((float) $p->pl_points)->toBe(30.0);
        expect($p->trust_score)->toBe(100);
        expect($p->queue_locked_until)->toBeNull();
    }
});

it('se puede señalar al propio compañero', function () {
    $s = combateEnCurso('b');

    $aviso = app(ArenaAbandonmentService::class)->report(
        $s['match'], $s['mio'], $s['companero']->id, 'Me dejo solo contra los dos'
    );

    expect((int) $aviso->accused_player_id)->toBe($s['companero']->id);
    expect($aviso->status)->toBe('pending');
});

it('confirmar castiga solo a quien abandono', function () {
    $s = combateEnCurso('c');
    $servicio = app(ArenaAbandonmentService::class);

    $aviso = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');
    $servicio->confirm($aviso->fresh(), null, 'Confirmado por el video');

    expect($s['match']->fresh()->status)->toBe('abandoned');

    // El que se fue paga: PL, confianza, strike y bloqueo de cola.
    $culpable = $s['rival']->fresh();
    expect((float) $culpable->pl_points)->toBe(28.0);
    expect($culpable->trust_score)->toBe(85);
    expect($culpable->penalty_strikes)->toBe(1);
    expect($culpable->queue_locked_until)->not->toBeNull();

    // Nadie mas se toca: ni su compañero ni los rivales.
    foreach (['mio', 'companero', 'rival2'] as $quien) {
        $p = $s[$quien]->fresh();
        expect((float) $p->pl_points)->toBe(30.0);
        expect($p->trust_score)->toBe(100);
        expect($p->penalty_strikes)->toBe(0);
        expect($p->queue_locked_until)->toBeNull();
    }
});

it('un abandono no reparte victoria a nadie', function () {
    $s = combateEnCurso('d');
    $servicio = app(ArenaAbandonmentService::class);

    $aviso = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');
    $servicio->confirm($aviso->fresh());

    $match = $s['match']->fresh();
    expect($match->winner_team)->toBeNull();
    expect($match->winner_realm)->toBeNull();
    expect($match->results()->count())->toBe(0);
});

it('no deja el PL en negativo', function () {
    $s = combateEnCurso('e');
    $s['rival']->update(['pl_points' => 0.5]);
    $servicio = app(ArenaAbandonmentService::class);

    $aviso = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');
    $servicio->confirm($aviso->fresh());

    expect((float) $s['rival']->fresh()->pl_points)->toBe(0.0);
});

it('no se puede reportar a uno mismo', function () {
    $s = combateEnCurso('f');

    expect(fn () => app(ArenaAbandonmentService::class)->report(
        $s['match'], $s['mio'], $s['mio']->id, 'Yo me fui'
    ))->toThrow(RuntimeException::class, 'No puedes reportarte a ti mismo.');
});

it('no se puede reportar a alguien de fuera del enfrentamiento', function () {
    $s = combateEnCurso('g');
    $ajeno = jugadorAbandono('ajeno');

    expect(fn () => app(ArenaAbandonmentService::class)->report(
        $s['match'], $s['mio'], $ajeno->id, 'Ese no estaba'
    ))->toThrow(RuntimeException::class, 'Ese jugador no esta en este enfrentamiento.');
});

it('no se avisa dos veces del mismo jugador', function () {
    $s = combateEnCurso('h');
    $servicio = app(ArenaAbandonmentService::class);

    $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');

    expect(fn () => $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Otra vez'))
        ->toThrow(RuntimeException::class, 'Ya reportaste a ese jugador');
});

it('confirmar dos veces no castiga dos veces', function () {
    // Doble clic, reintento tras un timeout, dos admins a la vez.
    $s = combateEnCurso('i');
    $servicio = app(ArenaAbandonmentService::class);

    $aviso = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');
    $servicio->confirm($aviso->fresh());
    $servicio->confirm($aviso->fresh());

    $culpable = $s['rival']->fresh();
    expect((float) $culpable->pl_points)->toBe(28.0);
    expect($culpable->penalty_strikes)->toBe(1);
});

it('dos avisos contra el mismo jugador se resuelven de una', function () {
    // El compañero del que se fue y un rival avisan por separado. Confirmar
    // uno no puede cobrar la sancion dos veces.
    $s = combateEnCurso('j');
    $servicio = app(ArenaAbandonmentService::class);

    $primero = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');
    $segundo = $servicio->report($s['match'], $s['rival2'], $s['rival']->id, 'Mi compañero se fue');

    $servicio->confirm($primero->fresh());

    expect($segundo->fresh()->status)->toBe('confirmed');
    expect($s['rival']->fresh()->penalty_strikes)->toBe(1);
    expect((float) $s['rival']->fresh()->pl_points)->toBe(28.0);
});

it('descartar el aviso deja el combate donde estaba', function () {
    $s = combateEnCurso('k');
    $servicio = app(ArenaAbandonmentService::class);

    $aviso = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Creo que se fue');
    $servicio->dismiss($aviso->fresh(), null, 'Estaba jugando, se ve en el video');

    // Nunca salio de 'in_progress', asi que no hay nada que resucitar -ni el
    // plazo vencido que eso arrastraba.
    expect($s['match']->fresh()->status)->toBe('in_progress');
    expect($aviso->fresh()->status)->toBe('dismissed');

    // Y nadie pago nada.
    expect((float) $s['rival']->fresh()->pl_points)->toBe(30.0);
    expect($s['rival']->fresh()->trust_score)->toBe(100);
});

it('no se reporta un abandono de un combate que no ha empezado', function () {
    $s = combateEnCurso('l');
    $s['match']->update(['status' => 'pending_acceptance']);

    expect(fn () => app(ArenaAbandonmentService::class)->report(
        $s['match']->fresh(), $s['mio'], $s['rival']->id, 'Se fue'
    ))->toThrow(RuntimeException::class, 'mientras el combate esta en curso');
});

it('los cruces que nunca empezaron no salen en el historial', function () {
    // 'cancelled' solo puede pasar antes de empezar: nadie acepto, o alguien
    // rechazo. No hubo pelea, asi que no es historial de nadie.
    $s = combateEnCurso('m');
    $s['match']->update(['status' => 'cancelled']);

    App\Models\Queue::create([
        'player_id' => $s['mio']->id,
        'queue_type' => 'random',
        'arena_mode' => '2v2',
        'status' => 'cancelled',
        'match_id' => (string) $s['match']->id,
        'estimated_mmr' => 1000,
        'joined_at' => now()->subHour(),
    ]);

    $this->actingAs($s['mio']->user)
        ->get(route('matches.index'))
        ->assertOk()
        ->assertDontSee($s['match']->match_code);
});

it('un abandonado si sale en el historial', function () {
    $s = combateEnCurso('n');
    $s['match']->update(['status' => 'abandoned']);

    App\Models\Queue::create([
        'player_id' => $s['mio']->id,
        'queue_type' => 'random',
        'arena_mode' => '2v2',
        'status' => 'accepted',
        'match_id' => (string) $s['match']->id,
        'estimated_mmr' => 1000,
        'joined_at' => now()->subHour(),
    ]);

    $this->actingAs($s['mio']->user)
        ->get(route('matches.index'))
        ->assertOk()
        ->assertSee($s['match']->match_code);
});

it('el boton de reportar abandono sale durante el combate', function () {
    $s = combateEnCurso('o');

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Reportar abandono')
        ->assertSee('tu aviso no sanciona a nadie por sí solo')
        // Los otros tres, y no uno mismo.
        ->assertSee('name="accused_player_id" value="' . $s['companero']->id . '"', false)
        ->assertSee('name="accused_player_id" value="' . $s['rival']->id . '"', false)
        ->assertDontSee('name="accused_player_id" value="' . $s['mio']->id . '"', false);
});

it('el formulario de abandono llega hasta la sancion', function () {
    $s = combateEnCurso('p');

    $this->actingAs($s['mio']->user)
        ->post(route('matches.abandonment.report'), [
            'match_id' => $s['match']->id,
            'player_id' => $s['mio']->id,
            'accused_player_id' => $s['rival']->id,
            'note' => 'Se desconecto y no volvio en todo el combate',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // El combate sigue en curso: el aviso se anota, no lo congela.
    expect($s['match']->fresh()->status)->toBe('in_progress');
    expect(MatchAbandonmentReport::where('match_id', $s['match']->id)->count())->toBe(1);
});

it('el formulario exige un motivo escrito', function () {
    $s = combateEnCurso('q');

    $this->actingAs($s['mio']->user)
        ->post(route('matches.abandonment.report'), [
            'match_id' => $s['match']->id,
            'player_id' => $s['mio']->id,
            'accused_player_id' => $s['rival']->id,
            'note' => 'no',
        ])
        ->assertSessionHasErrors('note');

    expect($s['match']->fresh()->status)->toBe('in_progress');
});

it('nadie reporta en nombre de otro', function () {
    // player_id se valida contra los personajes de quien ha iniciado sesion.
    $s = combateEnCurso('r');

    $this->actingAs($s['mio']->user)
        ->post(route('matches.abandonment.report'), [
            'match_id' => $s['match']->id,
            'player_id' => $s['rival']->id,
            'accused_player_id' => $s['companero']->id,
            'note' => 'Intento reportar haciendome pasar por el rival',
        ])
        ->assertNotFound();
});

it('un cruce que nadie acepto se borra, no se queda como cancelado', function () {
    // "Si no confirman no hay match": no es historial de nadie y no tiene por
    // que ocupar una fila.
    $s = combateEnCurso('s');
    $s['match']->update(['status' => 'pending_acceptance']);
    $id = $s['match']->id;

    foreach (['mio', 'companero', 'rival', 'rival2'] as $quien) {
        App\Models\Queue::create([
            'player_id' => $s[$quien]->id,
            'queue_type' => 'random',
            'arena_mode' => '2v2',
            'status' => 'matched',
            'match_id' => (string) $id,
            'estimated_mmr' => 1000,
            'joined_at' => now()->subMinutes(5),
        ]);
    }

    app(App\Services\ArenaMatchmakingService::class)
        ->cancelMatch($s['match']->fresh(), 'timeout', null, false);

    expect(ArenaMatch::find($id))->toBeNull();

    // Y ninguna cola se queda apuntando a una fila que ya no existe.
    expect(App\Models\Queue::where('match_id', (string) $id)->count())->toBe(0);
});

it('no borra un cruce que ya tenia reporte', function () {
    // Salvaguarda: si hay reporte es que si hubo partida, pase lo que pase con
    // el estado. Ahi se marca, no se borra.
    $s = combateEnCurso('t');
    $s['match']->update(['status' => 'accepted']);
    $id = $s['match']->id;

    App\Models\MatchReport::create([
        'match_id' => $id,
        'reported_by_player_id' => $s['mio']->id,
        'reporting_team' => 'team_a',
        'claimed_winner_team' => 'team_a',
        'claimed_winner_realm' => 'alsius',
        'status' => 'pending_confirmation',
        'final_screenshot_path' => 'match-reports/testing/aban/final.png',
        'encounter_screenshot_path' => 'match-reports/testing/aban/enc.png',
    ]);

    app(App\Services\ArenaMatchmakingService::class)
        ->cancelMatch($s['match']->fresh(), 'timeout', null, false);

    expect(ArenaMatch::find($id))->not->toBeNull();
    expect(ArenaMatch::find($id)->status)->toBe('cancelled');
});

function sesionAdminAbandono(): array
{
    return [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => 1,
        'arena_admin.username' => 'admin',
        'arena_admin.display_name' => 'admin',
    ];
}

it('el panel enseña los avisos y deja confirmarlos', function () {
    $s = combateEnCurso('u');
    $aviso = app(ArenaAbandonmentService::class)
        ->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue al minuto dos');

    $this->withSession(sesionAdminAbandono())
        ->get(route('admin.matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Avisos de abandono')
        ->assertSee('Se fue al minuto dos')
        ->assertSee($s['rival']->character_name);

    $this->withSession(sesionAdminAbandono())
        ->post(route('admin.matches.resolve', $s['match']), [
            'action' => 'confirm_abandonment',
            'abandonment_id' => $aviso->id,
            'note' => 'Se ve en el video',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($s['match']->fresh()->status)->toBe('abandoned');
    expect((float) $s['rival']->fresh()->pl_points)->toBe(28.0);
    expect((float) $s['mio']->fresh()->pl_points)->toBe(30.0);
});

it('el panel deja descartar un aviso sin sancionar', function () {
    $s = combateEnCurso('v');
    $aviso = app(ArenaAbandonmentService::class)
        ->report($s['match'], $s['mio'], $s['rival']->id, 'Creo que se fue');

    $this->withSession(sesionAdminAbandono())
        ->post(route('admin.matches.resolve', $s['match']), [
            'action' => 'dismiss_abandonment',
            'abandonment_id' => $aviso->id,
            'note' => 'Estaba jugando',
        ])
        ->assertSessionHasNoErrors();

    expect($s['match']->fresh()->status)->toBe('in_progress');
    expect((float) $s['rival']->fresh()->pl_points)->toBe(30.0);
    expect($s['rival']->fresh()->trust_score)->toBe(100);
});

it('no se resuelve un aviso de otro enfrentamiento', function () {
    // Un id colado a mano en el formulario no puede resolver un aviso ajeno.
    $uno = combateEnCurso('w');
    $otro = combateEnCurso('x');

    $avisoAjeno = app(ArenaAbandonmentService::class)
        ->report($otro['match'], $otro['mio'], $otro['rival']->id, 'Se fue');

    $this->withSession(sesionAdminAbandono())
        ->post(route('admin.matches.resolve', $uno['match']), [
            'action' => 'confirm_abandonment',
            'abandonment_id' => $avisoAjeno->id,
        ])
        ->assertSessionHasErrors();

    expect($avisoAjeno->fresh()->status)->toBe('pending');
    expect((float) $otro['rival']->fresh()->pl_points)->toBe(30.0);
});

it('marcar interrumpido no castiga a nadie', function () {
    $s = combateEnCurso('y');

    $this->withSession(sesionAdminAbandono())
        ->post(route('admin.matches.resolve', $s['match']), [
            'action' => 'interrupted',
            'note' => 'Entro un tercero a molestar',
        ])
        ->assertSessionHasNoErrors();

    expect($s['match']->fresh()->status)->toBe('cancelled');

    foreach (['mio', 'companero', 'rival', 'rival2'] as $quien) {
        $p = $s[$quien]->fresh();
        expect((float) $p->pl_points)->toBe(30.0);
        expect($p->penalty_strikes)->toBe(0);
        expect($p->queue_locked_until)->toBeNull();
    }
});

it('el acusado puede leer de que se le acusa y con que pruebas', function () {
    $s = combateEnCurso('z');
    app(ArenaAbandonmentService::class)
        ->report($s['match'], $s['mio'], $s['rival']->id, 'Se desconecto y no volvio');

    $this->actingAs($s['rival']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Aviso de abandono')
        ->assertSee('Se desconecto y no volvio')
        ->assertSee('Pendiente de revisión. Nadie ha sido sancionado todavía.');
});

it('quien aviso ve en que quedo su aviso', function () {
    $s = combateEnCurso('z2');
    $servicio = app(ArenaAbandonmentService::class);
    $aviso = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');
    $servicio->confirm($aviso->fresh(), null, 'Se ve en el video que se desconecta');

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Moderación confirmó el abandono')
        ->assertSee('Se ve en el video que se desconecta');
});

it('un aviso descartado se lee como descartado', function () {
    $s = combateEnCurso('z3');
    $servicio = app(ArenaAbandonmentService::class);
    $aviso = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Creo que se fue');
    $servicio->dismiss($aviso->fresh(), null, 'Estaba jugando');

    $this->actingAs($s['rival']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertSee('Moderación descartó el aviso. Nadie fue sancionado.');
});

it('sin avisos no aparece el bloque', function () {
    $s = combateEnCurso('z4');

    $this->actingAs($s['mio']->user)
        ->get(route('matches.show', $s['match']))
        ->assertOk()
        ->assertDontSee('data-abandonment-record', false);
});

it('confirmar sigue funcionando si el combate se auto-anulo mientras esperaba', function () {
    // El admin tarda en mirarlo y el barrido de vencimiento anula el combate
    // antes. El aviso sigue vivo y tiene que poder resolverse: si no, quien
    // abandono se libra por haber tardado nosotros.
    $s = combateEnCurso('z5');
    $servicio = app(ArenaAbandonmentService::class);

    $aviso = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');

    $s['match']->fresh()->update(['status' => 'void']);

    $servicio->confirm($aviso->fresh(), null, 'Confirmado tarde');

    expect($s['match']->fresh()->status)->toBe('abandoned');
    expect((float) $s['rival']->fresh()->pl_points)->toBe(28.0);
    expect((float) $s['mio']->fresh()->pl_points)->toBe(30.0);
});

it('un aviso vivo impide que el cruce se borre por error', function () {
    // Salvaguarda del borrado: si alguien denuncio un abandono es que el
    // combate se estaba jugando, pase lo que pase con el estado.
    $s = combateEnCurso('z6');
    $servicio = app(ArenaAbandonmentService::class);
    $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');

    $s['match']->fresh()->update(['status' => 'pending_acceptance']);
    $id = $s['match']->id;

    app(App\Services\ArenaMatchmakingService::class)
        ->cancelMatch(ArenaMatch::find($id), 'timeout', null, false);

    expect(ArenaMatch::find($id))->not->toBeNull();
    expect(MatchAbandonmentReport::where('match_id', $id)->count())->toBe(1);
});

/*
 * Guardias nacidos de la auditoria adversarial. Cada uno vigila un agujero
 * concreto que llego a existir; escritos al reves que los del arbitro, para
 * que fallen si el fallo vuelve.
 */

it('reportar un abandono no impide al rival subir su reporte', function () {
    // El agujero: el aviso mandaba el match a 'disputed', submitReport() solo
    // acepta 'in_progress', y a las 48h se auto-anulaba. Quien iba perdiendo
    // convertia su derrota en un cero a cero pulsando un boton.
    $s = combateEnCurso('g1');

    app(ArenaAbandonmentService::class)
        ->report($s['match'], $s['mio'], $s['rival']->id, 'Aviso para escaparme de la derrota');

    // El rival, que gano, sigue pudiendo reportar.
    $s['match']->fresh()->update(['expires_at' => now()->addMinutes(20)]);

    expect($s['match']->fresh()->status)->toBe('in_progress');

    Illuminate\Support\Facades\Storage::fake(App\Models\MatchReport::EVIDENCE_DISK);

    $this->actingAs($s['rival']->user)
        ->post(route('matches.report'), [
            'match_id' => $s['match']->id,
            'player_id' => $s['rival']->id,
            'claimed_winner_team' => 'team_b',
            'evidence_files' => [
                Illuminate\Http\UploadedFile::fake()->image('final.png'),
            ],
        ])
        ->assertSessionHasNoErrors();

    expect($s['match']->fresh()->report)->not->toBeNull();
});

it('confirmar un abandono devuelve los puntos si el combate llego a puntuar', function () {
    // El agujero: se podia confirmar sobre un match ya puntuado, y quedaban
    // cuatro filas de resultados en un enfrentamiento marcado como abandonado.
    // El acusado pagaba dos veces y los otros tres conservaban puntos.
    $s = combateEnCurso('g2');
    $servicio = app(ArenaAbandonmentService::class);

    $aviso = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');

    // El combate se reporta y se confirma mientras el aviso espera.
    $reporte = App\Models\MatchReport::create([
        'match_id' => $s['match']->id,
        'reported_by_player_id' => $s['rival']->id,
        'reporting_team' => 'team_b',
        'claimed_winner_team' => 'team_b',
        'claimed_winner_realm' => 'ignis',
        'status' => 'pending_confirmation',
        'final_screenshot_path' => 'match-reports/testing/aban/final.png',
        'encounter_screenshot_path' => 'match-reports/testing/aban/enc.png',
    ]);
    app(App\Services\ArenaMatchResultService::class)->confirmReportForRival($reporte, 'ok');

    expect($s['match']->fresh()->results()->count())->toBeGreaterThan(0);

    $servicio->confirm($aviso->fresh(), null, 'Se fue, confirmado');

    // Ni una fila de resultados sobrevive, y solo el acusado queda por debajo.
    expect($s['match']->fresh()->status)->toBe('abandoned');
    expect($s['match']->fresh()->results()->count())->toBe(0);
    expect((float) $s['rival']->fresh()->pl_points)->toBe(28.0);
    expect((float) $s['mio']->fresh()->pl_points)->toBe(30.0);
    expect((float) $s['companero']->fresh()->pl_points)->toBe(30.0);
    expect((float) $s['rival2']->fresh()->pl_points)->toBe(30.0);
});

it('dos admins a la vez no cobran la sancion dos veces', function () {
    // El agujero: la comprobacion de estado se hacia sobre el modelo en
    // memoria y fuera de la transaccion, asi que dos peticiones que lo leyeron
    // antes de que la otra escribiera sancionaban las dos.
    $s = combateEnCurso('g3');
    $servicio = app(ArenaAbandonmentService::class);

    $aviso = $servicio->report($s['match'], $s['mio'], $s['rival']->id, 'Se fue');

    // Dos copias leidas ANTES de que ninguna resuelva: es lo que tienen dos
    // peticiones concurrentes.
    $copiaA = MatchAbandonmentReport::find($aviso->id);
    $copiaB = MatchAbandonmentReport::find($aviso->id);

    $servicio->confirm($copiaA);
    $servicio->confirm($copiaB);

    $culpable = $s['rival']->fresh();
    expect((float) $culpable->pl_points)->toBe(28.0);
    expect($culpable->penalty_strikes)->toBe(1);
    expect($culpable->trust_score)->toBe(85);
});
