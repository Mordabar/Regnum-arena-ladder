<?php

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lookUser(string $suffix): User
{
    return User::create([
        'discord_id' => 'look-' . $suffix,
        'discord_username' => 'look_' . $suffix,
        'name' => 'Look ' . $suffix,
        'email' => 'look-' . $suffix . '@example.com',
    ]);
}

it('cada reino ofrece sus razas y ninguna se repite fuera de sitio', function () {
    // Los humanos son tres razas distintas aunque compartan cuerpo: la ropa
    // cambia con el reino. Los lamai si son comunes a los tres.
    expect(array_keys(Player::RACES))->toBe(['alsius', 'ignis', 'syrtis']);

    foreach (Player::RACES as $realm => $races) {
        expect($races)->toHaveCount(4)
            ->and(array_key_exists('lamai', $races))->toBeTrue();
    }

    expect(Player::raceBelongsToRealm('dwarf', 'alsius'))->toBeTrue()
        ->and(Player::raceBelongsToRealm('dwarf', 'ignis'))->toBeFalse()
        ->and(Player::raceBelongsToRealm('molok', 'ignis'))->toBeTrue()
        ->and(Player::raceBelongsToRealm(null, 'ignis'))->toBeFalse();
});

it('la raza por defecto de un reino es su variante humana', function () {
    expect(Player::defaultRace('alsius'))->toBe('nordo')
        ->and(Player::defaultRace('ignis'))->toBe('esquelio')
        ->and(Player::defaultRace('syrtis'))->toBe('alturian');
});

it('crear un guerrero guarda raza y sexo', function () {
    $user = lookUser('a');

    $this->actingAs($user)->post(route('player.register'), [
        'character_name' => 'Grimbold',
        'subclass' => 'barbarian',
        'realm' => 'alsius',
        'race' => 'dwarf',
        'gender' => 'female',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $player = Player::where('character_name', 'Grimbold')->firstOrFail();

    expect($player->race)->toBe('dwarf')
        ->and($player->gender)->toBe('female')
        ->and($player->raceName())->toBe('Enano')
        ->and($player->genderName())->toBe('Femenino');
});

it('rechaza una raza que no es de ese reino', function () {
    // Un enano no es de Ignis. Se comprueba en el servidor y no solo
    // escondiendo opciones en el formulario.
    $user = lookUser('b');

    $this->actingAs($user)
        ->from(route('player.create'))
        ->post(route('player.register'), [
            'character_name' => 'Impostor',
            'subclass' => 'knight',
            'realm' => 'ignis',
            'race' => 'dwarf',
            'gender' => 'male',
        ])
        ->assertRedirect(route('player.create'))
        ->assertSessionHasErrors('race');

    expect(Player::where('character_name', 'Impostor')->exists())->toBeFalse();
});

it('rechaza un sexo inventado', function () {
    $this->actingAs(lookUser('c'))
        ->from(route('player.create'))
        ->post(route('player.register'), [
            'character_name' => 'Raro',
            'subclass' => 'knight',
            'realm' => 'ignis',
            'race' => 'esquelio',
            'gender' => 'otro',
        ])
        ->assertSessionHasErrors('gender');
});

it('el asistente muestra solo las razas del reino elegido y todas las opciones', function () {
    $response = $this->actingAs(lookUser('d'))->get(route('player.create'));

    $response->assertOk();

    // Las 10 razas viajan al HTML; el paso 1 decide cuales se ven.
    foreach (Player::RACES as $realm => $races) {
        foreach ($races as $key => $label) {
            $response->assertSee('value="' . $key . '"', false);
        }
        $response->assertSee('data-race-of="' . $realm . '"', false);
    }

    foreach (Player::GENDERS as $key => $label) {
        $response->assertSee('value="' . $key . '"', false)->assertSee($label);
    }
});

it('el visor recibe raza y sexo en todas las pantallas', function () {
    $user = lookUser('e');
    $player = Player::create([
        'user_id' => $user->id,
        'character_name' => 'Visible',
        'subclass' => 'warlock',
        'realm' => 'syrtis',
        'race' => 'half_elf',
        'gender' => 'female',
        'pl_points' => 5,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);

    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('data-champion-race="half_elf"', false)
        ->assertSee('data-champion-gender="female"', false)
        ->assertSee('Semielfo');
});

it('un personaje sin raza guardada no rompe el visor', function () {
    // Filas anteriores a la migracion o creadas a mano: el componente cae en la
    // variante humana del reino en vez de mandar un valor vacio.
    $user = lookUser('f');
    $player = Player::create([
        'user_id' => $user->id,
        'character_name' => 'Antiguo',
        'subclass' => 'knight',
        'realm' => 'ignis',
        'pl_points' => 0,
        'mmr' => 1000,
        'trust_score' => 100,
        'is_active' => true,
    ]);
    $player->forceFill(['race' => null, 'gender' => null])->saveQuietly();

    $this->actingAs($user)->get(route('lobby'))
        ->assertOk()
        ->assertSee('data-champion-race="esquelio"', false)
        ->assertSee('data-champion-gender="male"', false);
});

it('el modulo 3d conoce las mismas razas y subclases que el servidor', function () {
    // Si el servidor guarda una raza que el visor no sabe dibujar, el jugador
    // ve otro maniqui distinto al que eligio.
    $js = file_get_contents(public_path('js/arena-champion.js'));

    foreach (Player::RACES as $races) {
        foreach (array_keys($races) as $race) {
            expect($js)->toContain($race . ':');
        }
    }

    foreach (array_keys(Player::SUBCLASSES) as $subclass) {
        expect($js)->toContain($subclass . ':');
    }

    foreach (array_keys(Player::GENDERS) as $gender) {
        expect($js)->toContain($gender . ':');
    }
});
