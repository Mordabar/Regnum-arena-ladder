<?php

use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\MatchLineupService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function duelPlayer(string $suffix, string $realm, string $subclass, string $name): Player
{
    $user = User::create([
        'discord_id' => 'duel-' . $suffix,
        'discord_username' => 'duel_' . $suffix,
        'name' => 'Duel ' . $suffix,
        'email' => 'duel-' . $suffix . '@example.com',
    ]);

    return Player::create([
        'user_id' => $user->id,
        'character_name' => $name,
        'subclass' => $subclass,
        'realm' => $realm,
        'pl_points' => 20,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

function duelPack(Player $player): array
{
    return [
        'player_id' => $player->id,
        'character_name' => $player->character_name,
        'subclass' => $player->subclass,
        'realm' => $player->realm,
        'discord_id' => (string) $player->user_id,
    ];
}

/**
 * Un cruce 2v2 recien encontrado: nadie ha aceptado todavia.
 *
 * @return array{match: ArenaMatch, mine: Player, mate: Player, foes: array<int, Player>}
 */
function duelScenario(string $status = 'pending_acceptance'): array
{
    $mine = duelPlayer('mine', 'alsius', 'conjurer', 'Nyxaria');
    $mate = duelPlayer('mate', 'alsius', 'knight', 'Selharil');
    $foeA = duelPlayer('foe-a', 'ignis', 'barbarian', 'Kruul');
    $foeB = duelPlayer('foe-b', 'ignis', 'marksman', 'Vessa');

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-4242',
        'report_token' => 'DUELTEST',
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => 'alsius',
        'team_b_realm' => 'ignis',
        'team_a' => [duelPack($mine), duelPack($mate)],
        'team_b' => [duelPack($foeA), duelPack($foeB)],
        'zone' => 'frozen_bridge',
        'status' => $status,
        'estimated_mmr_avg' => 1000,
        'player_count' => 4,
        'expires_at' => now()->addMinutes(5),
    ]);

    foreach ([$mine, $mate, $foeA, $foeB] as $player) {
        Queue::create([
            'player_id' => $player->id,
            'queue_type' => 'random',
            'arena_mode' => '2v2',
            'status' => 'matched',
            'match_id' => (string) $match->id,
            'estimated_mmr' => $player->mmr,
            'joined_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(20),
        ]);
    }

    return ['match' => $match, 'mine' => $mine, 'mate' => $mate, 'foes' => [$foeA, $foeB]];
}

it('el aviso de cruce sale sobre la cola, sin abrir otra pagina', function () {
    $s = duelScenario();

    $this->actingAs($s['mine']->user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('data-duel-panel', false)
        ->assertSee('¡Combate encontrado!')
        ->assertSee('ARENA-4242')
        ->assertSee('Zona 1 - Frozen Bridge');
});

it('el aviso respeta el anonimato del rival', function () {
    // La subclase si se ve, porque hace falta para preparar la pelea. El nombre
    // no, hasta que termina el enfrentamiento.
    $s = duelScenario();

    $response = $this->actingAs($s['mine']->user)->get(route('lobby'));

    $response->assertSee('Nyxaria')
        ->assertSee('Selharil')
        ->assertSee('Guerrero Anónimo')
        ->assertSee('Bárbaro')
        ->assertSee('Tirador')
        ->assertDontSee('Kruul')
        ->assertDontSee('Vessa');
});

it('aceptar desde el aviso deja al jugador en la cola', function () {
    $s = duelScenario();

    $this->actingAs($s['mine']->user)
        ->post(route('matches.accept'), [
            'match_id' => $s['match']->id,
            'player_id' => $s['mine']->id,
            'from' => 'queue',
        ])
        ->assertRedirect(route('lobby', ['mode' => '2v2']));

    expect(Queue::where('player_id', $s['mine']->id)->first()->status)->toBe('accepted');
});

it('sin el marcador de origen se sigue yendo a la pagina del enfrentamiento', function () {
    // El boton de la pagina del match no cambia de comportamiento.
    $s = duelScenario();

    $this->actingAs($s['mine']->user)
        ->post(route('matches.accept'), [
            'match_id' => $s['match']->id,
            'player_id' => $s['mine']->id,
        ])
        ->assertRedirect(route('matches.show', $s['match']));
});

it('quien ya acepto ve a quien falta, no otro boton de aceptar', function () {
    $s = duelScenario();
    Queue::where('player_id', $s['mine']->id)->update(['status' => 'accepted']);

    $this->actingAs($s['mine']->user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('Esperando a los demás')
        // Ojo con buscar "data-duel-accept" a secas: el contador de aceptados
        // se llama "data-duel-accepted" y daria un falso positivo.
        ->assertDontSee('Aceptar combate')
        ->assertSee('Ver el enfrentamiento');
});

it('no hay aviso cuando el cruce ya arranco', function () {
    $s = duelScenario('in_progress');

    $this->actingAs($s['mine']->user)->get(route('lobby'))
        ->assertOk()
        ->assertDontSee('data-duel-panel', false);
});

it('no hay aviso cuando el cruce ya expiro', function () {
    // Con el reloj a cero lo que toca es que el sistema lo cancele, no pedirle
    // al jugador que acepte algo que ya no existe.
    $s = duelScenario();
    $s['match']->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($s['mine']->user)->get(route('lobby'))
        ->assertOk()
        ->assertDontSee('data-duel-panel', false);
});

it('se puede aceptar y rechazar sin javascript', function () {
    // Los dos son formularios POST de verdad, con sus campos: si el navegador
    // no ejecuta scripts, el jugador no se queda atrapado en el aviso.
    $s = duelScenario();

    $html = $this->actingAs($s['mine']->user)->get(route('lobby'))->getContent();

    expect($html)->toContain('action="' . route('matches.accept') . '"')
        ->and($html)->toContain('action="' . route('matches.reject') . '"')
        ->and($html)->toContain('name="match_id" value="' . $s['match']->id . '"')
        ->and($html)->toContain('name="player_id" value="' . $s['mine']->id . '"');
});

it('el servicio cuenta las aceptaciones y destapa los nombres al final', function () {
    $s = duelScenario();
    Queue::where('player_id', $s['mate']->id)->update(['status' => 'accepted']);

    $service = app(MatchLineupService::class);
    $lineup = $service->forViewer($s['match']->fresh(), [$s['mine']->id]);

    expect($lineup['accepted_count'])->toBe(1)
        ->and($lineup['viewer_accepted'])->toBeFalse()
        ->and($lineup['own'][0]['is_viewer'])->toBeTrue()
        ->and($lineup['rival'][0]['name'])->toBe('Guerrero Anónimo')
        ->and($lineup['names_revealed'])->toBeFalse();

    $s['match']->update(['status' => 'completed']);
    $revealed = $service->forViewer($s['match']->fresh(), [$s['mine']->id]);

    expect($revealed['names_revealed'])->toBeTrue()
        ->and($revealed['rival'][0]['name'])->toBe('Kruul');
});

it('el servicio ignora a quien no juega este cruce', function () {
    $s = duelScenario();
    $extrano = duelPlayer('otro', 'syrtis', 'hunter', 'Ajeno');

    expect(app(MatchLineupService::class)->forViewer($s['match'], [$extrano->id]))->toBeNull();
});

it('la ventana del mapa llega con el panel, no con la pagina', function () {
    // El bug: el cruce aparece por el sondeo, sin recargar, y la ventana del
    // mapa se pintaba fuera del panel. El boton de la zona llegaba solo, sin
    // nada que abrir, tanto en movil como en escritorio.
    $s = duelScenario();

    $this->actingAs($s['mine']->user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('data-modal-open="modal-queue-zone-map"', false)
        ->assertSee('id="modal-queue-zone-map"', false);

    $panel = $this->actingAs($s['mine']->user)->getJson(route('lobby.console'))->assertOk();

    expect($panel->json('html'))->toContain('data-modal-open="modal-queue-zone-map"')
        ->and($panel->json('modals'))->toContain('id="modal-queue-zone-map"')
        ->and($panel->json('modals'))->toContain('data-arena-map');
});

it('sin cruce no hay ventana del mapa que abrir', function () {
    $solo = duelPlayer('solo', 'syrtis', 'hunter', 'Aranor');

    $this->actingAs($solo->user)->get(route('lobby'))
        ->assertOk()
        ->assertDontSee('modal-queue-zone-map', false);
});

it('el mapa no arrastra a Leaflet en cada visita al lobby', function () {
    // Se descarga cuando alguien abre un mapa, no al entrar al lobby.
    $solo = duelPlayer('ligero', 'syrtis', 'knight', 'Belen');

    // La direccion sigue escrita en el cargador; lo que no puede haber es una
    // etiqueta que la descargue al abrir la pagina.
    $this->actingAs($solo->user)->get(route('lobby'))
        ->assertOk()
        ->assertDontSee('<script src="https://unpkg.com/leaflet', false)
        ->assertDontSee('<link rel="stylesheet" href="https://unpkg.com/leaflet', false);
});
