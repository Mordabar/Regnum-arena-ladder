<?php

use App\Models\Player;
use App\Models\User;
use App\Support\ChampionModels;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

dataset('forbidden race classes', [
    ['alsius', 'dwarf', 'warlock'], ['alsius', 'utghar', 'hunter'],
    ['ignis', 'dark_elf', 'marksman'], ['ignis', 'molok', 'conjurer'],
    ['syrtis', 'wood_elf', 'barbarian'], ['syrtis', 'half_elf', 'warlock'],
]);

it('rechaza combinaciones imposibles aunque se envie el formulario directamente', function ($realm, $race, $subclass) {
    $user = User::create(['discord_id' => 'restriction', 'discord_username' => 'restriction', 'name' => 'Tester']);
    $this->actingAs($user)->post(route('player.register'), [
        'character_name' => 'Prueba Raza', 'realm' => $realm, 'race' => $race,
        'subclass' => $subclass, 'gender' => 'male',
    ])->assertSessionHasErrors('subclass');
    expect(Player::count())->toBe(0);
})->with('forbidden race classes');

it('no permite cambiar a una raza incompatible con la clase existente', function ($realm, $race, $subclass) {
    $user = User::create(['discord_id' => 'edit-restriction', 'discord_username' => 'restriction', 'name' => 'Tester']);
    $player = Player::create([
        'user_id' => $user->id, 'character_name' => 'Prueba Cambio', 'realm' => $realm,
        'race' => 'lamai', 'subclass' => $subclass, 'gender' => 'male',
    ]);
    $this->actingAs($user)->put(route('player.update', $player), [
        'character_name' => 'Prueba Cambio', 'race' => $race, 'gender' => 'female',
    ])->assertSessionHasErrors('race');
    expect($player->fresh()->race)->toBe('lamai');
})->with('forbidden race classes');

it('cada combinacion permitida tiene modelo para ambos sexos y ninguna prohibida tiene modelo', function () {
    $models = ChampionModels::available();
    expect($models)->toHaveCount(60);
    foreach (Player::RACES as $realm => $races) {
        foreach ($races as $race => $label) {
            foreach (Player::SUBCLASS_ARCHETYPES as $subclass => $archetype) {
                foreach (array_keys(Player::GENDERS) as $gender) {
                    expect(in_array("$realm-$race-$gender-$archetype", $models, true))
                        ->toBe(Player::raceCanBeSubclass($realm, $race, $subclass));
                }
            }
        }
    }
});
