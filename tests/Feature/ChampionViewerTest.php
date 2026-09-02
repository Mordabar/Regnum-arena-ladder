<?php

use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Support\ChampionModels;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function viewerUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'viewer-' . $suffix,
        'discord_username' => 'viewer_' . $suffix,
        'name' => 'Viewer ' . $suffix,
        'email' => 'viewer-' . $suffix . '@example.com',
    ]);
}

function viewerPlayer(User $user, string $name, string $realm = 'ignis', string $subclass = 'knight'): Player
{
    return Player::create([
        'user_id' => $user->id,
        'character_name' => $name,
        'subclass' => $subclass,
        'realm' => $realm,
        'pl_points' => 10,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
}

it('el lobby monta el visor con el reino y la subclase del guerrero', function () {
    $user = viewerUser('a');
    viewerPlayer($user, 'Primero', 'alsius', 'conjurer');

    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('data-champion-viewer', false)
        ->assertSee('data-champion-id="hub-stage"', false)
        ->assertSee('data-champion-realm="alsius"', false)
        ->assertSee('data-champion-subclass="conjurer"', false);
});

it('sin guerreros el lobby no monta ningun visor', function () {
    // Un lienzo 3D vacio no aporta nada y cuesta una descarga de Three.js.
    // Se busca el atributo con su valor, no el selector suelto: la cadena
    // "data-champion-viewer" tambien aparece dentro del script que monta los
    // visores, y buscarla a secas daria un falso positivo en cualquier pagina.
    $this->actingAs(viewerUser('b'))->get(route('lobby'))
        ->assertOk()
        ->assertDontSee('class="arena-champion', false)
        ->assertDontSee('hub-stage', false)
        ->assertSee('Crear mi primer guerrero');
});

it('el asistente de creacion ofrece los tres reinos y las seis subclases', function () {
    $response = $this->actingAs(viewerUser('c'))->get(route('player.create'));

    $response->assertOk()->assertSee('data-champion-id="create-preview"', false);

    foreach (array_keys(Player::REALMS) as $realm) {
        $response->assertSee('value="' . $realm . '"', false);
    }
    foreach (Player::SUBCLASSES as $key => $label) {
        $response->assertSee('value="' . $key . '"', false)->assertSee($label);
    }
});

it('el asistente explica cada subclase con lo que hace, no solo su nombre', function () {
    // Quien llega nuevo no sabe que es un "marksman"; si sabe si quiere pegar
    // de lejos.
    $response = $this->actingAs(viewerUser('c2'))->get(route('player.create'));

    foreach (Player::SUBCLASS_NOTES as $note) {
        $response->assertSee($note);
    }
});

it('con los cinco slots llenos el asistente no se abre', function () {
    $user = viewerUser('d');
    foreach (range(1, 5) as $i) {
        viewerPlayer($user, 'Slot' . $i);
    }

    $this->actingAs($user)->get(route('player.create'))
        ->assertRedirect(route('lobby'))
        ->assertSessionHas('error');
});

it('un eliminado libera el slot y deja volver al asistente', function () {
    $user = viewerUser('e');
    foreach (range(1, 5) as $i) {
        viewerPlayer($user, 'Ocupa' . $i, 'ignis', 'knight');
    }
    $primero = $user->players()->first();
    $primero->update([
        'is_active' => false,
        'deactivated_reason' => Player::DEACTIVATED_BY_PLAYER,
        'deactivated_at' => now(),
        'character_name' => $primero->character_name . Player::DELETED_NAME_SUFFIX,
    ]);

    $this->actingAs($user)->get(route('player.create'))->assertOk();
});

it('la cola muestra en 3d al guerrero que esta esperando', function () {
    $user = viewerUser('f');
    $player = viewerPlayer($user, 'Esperando', 'syrtis', 'marksman');

    Queue::create([
        'player_id' => $player->id,
        'queue_type' => 'random',
        'arena_mode' => '2v2',
        'status' => 'waiting',
        'estimated_mmr' => $player->mmr,
        'joined_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('data-champion-id="queue-stage"', false)
        ->assertSee('data-champion-realm="syrtis"', false)
        ->assertSee('data-champion-subclass="marksman"', false);
});

it('el visor se sirve desde este dominio, nunca desde un CDN', function () {
    // Misma regla que con Tailwind: si el CDN cae o esta bloqueado, la pagina
    // no puede quedarse sin su capa 3D.
    foreach (['js/arena-champion.js', 'js/three.min.js', 'js/three-gltf-loader.js'] as $asset) {
        expect(file_exists(public_path($asset)))->toBeTrue("Falta {$asset}");
    }

    $module = file_get_contents(public_path('js/arena-champion.js'));
    expect($module)->not->toContain('cdn.')
        ->and($module)->not->toContain('unpkg')
        ->and($module)->not->toContain('jsdelivr')
        ->and($module)->toContain("'/js/three.min.js'");

    $layout = file_get_contents(resource_path('views/layouts/arena.blade.php'));
    expect($layout)->toContain("asset('js/arena-champion.js')");
});

it('el listado de modelos solo nombra los archivos que existen de verdad', function () {
    expect(ChampionModels::available())->toBeArray();

    $file = public_path(ChampionModels::DIRECTORY . '/ignis-knight.glb');
    file_put_contents($file, 'glb de prueba');

    try {
        expect(ChampionModels::available())->toContain('ignis-knight');
    } finally {
        @unlink($file);
    }

    expect(ChampionModels::available())->not->toContain('ignis-knight');
});

it('el emblema del reino se ve sin javascript y sin webgl', function () {
    // El respaldo se pinta desde el servidor y lo retira el visor cuando ya
    // tiene algo mejor que ensenar. Al reves habria un hueco negro.
    $user = viewerUser('g');
    viewerPlayer($user, 'Respaldo', 'syrtis', 'hunter');

    $html = $this->actingAs($user)->get(route('lobby'))->getContent();

    // El respaldo se emite sin `hidden`: lo oculta el visor desde JavaScript, y
    // solo cuando ya tiene el guerrero montado.
    expect($html)->toContain('data-champion-fallback')
        ->and($html)->not->toMatch('/data-champion-fallback[^>]*hidden/');

    // Y arranca en 'idle': el aviso de "no disponible" solo aparece cuando el
    // visor da el 3D por imposible, no mientras descarga la libreria.
    expect($html)->toContain('data-champion-state="idle"');
});
