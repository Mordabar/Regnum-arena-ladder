<?php

use App\Support\ChampionModels;

/**
 * Los modelos se suben a mano, asi que lo que se comprueba aqui es que el
 * nombre del archivo case con lo que el visor va a pedir. Un nombre mal escrito
 * no rompe nada: simplemente el guerrero se queda con la silueta generada por
 * codigo y nadie se entera hasta que alguien lo mira.
 */
it('todos los modelos subidos tienen un nombre que el visor sabe pedir', function () {
    $realms = array_keys(\App\Models\Player::REALMS);
    $archetypes = ['warrior', 'archer', 'mage'];
    $subclasses = array_keys(\App\Models\Player::SUBCLASSES);
    $genders = array_keys(\App\Models\Player::GENDERS);

    // El nombre que falle sale en la traza del foreach; aqui basta con que
    // cada pieza pertenezca a su lista.
    foreach (ChampionModels::available() as $name) {
        $parts = explode('-', $name);
        $realm = $parts[0];

        expect($realms)->toContain($realm);

        // Los respaldos cortos: reino mas arquetipo o subclase.
        if (count($parts) === 2) {
            expect(array_merge($archetypes, $subclasses))->toContain($parts[1]);
            continue;
        }

        $races = array_keys(\App\Models\Player::RACES[$realm] ?? []);
        // La clave de raza lleva guion bajo, no guion: "dark_elf".
        expect($races)->toContain($parts[1]);
        expect($genders)->toContain($parts[2]);

        if (count($parts) === 4) {
            expect(array_merge($archetypes, $subclasses))->toContain($parts[3]);
        }

        expect(count($parts))->toBeLessThanOrEqual(4);
    }
})->skip(fn () => ChampionModels::available() === [], 'Todavia no hay modelos subidos.');

it('ningun modelo pesa tanto como para castigar al movil', function () {
    // Tres guerreros a la vez en el lobby: a 900 KB cada uno son menos de 3 MB.
    foreach (ChampionModels::available() as $name) {
        $kb = (int) round(filesize(public_path('models/' . $name . '.glb')) / 1024);
        expect($kb)->toBeLessThanOrEqual(900);
    }
})->skip(fn () => ChampionModels::available() === [], 'Todavia no hay modelos subidos.');
