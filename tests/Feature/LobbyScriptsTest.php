<?php

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Un icono metido con el componente dentro de una cadena de JavaScript entre
 * comillas simples pinta el SVG en varias lineas y rompe el script entero: el
 * buscador de compañeros de "Invitar aliado" se quedo bloqueado asi. Aqui se
 * comprueba que cada cadena que se asigna a innerHTML cierra en su misma linea.
 */
it('las plantillas de JavaScript del lobby no se parten en varias lineas', function () {
    $user = User::create(['discord_id' => 'js-1', 'discord_username' => 'js', 'name' => 'Js', 'email' => 'js@example.com']);
    Player::create(['user_id' => $user->id, 'character_name' => 'Lider', 'subclass' => 'knight', 'realm' => 'ignis', 'pl_points' => 0, 'mmr' => 1000, 'trust_score' => 100, 'is_active' => true]);

    $html = $this->actingAs($user)->get(route('lobby', ['mode' => '2v2']))->assertOk()->getContent();

    expect($html)->toContain('id="premadeSearch2"');

    foreach (explode("\n", $html) as $linea) {
        if (preg_match("/innerHTML\s*=\s*'/", $linea)) {
            // Comillas simples sin escapar: si son impares, la cadena sigue en
            // la linea de abajo y el navegador no puede leer el script.
            $comillas = preg_match_all("/(?<!\\\\)'/", $linea);
            expect($comillas % 2)->toBe(0, trim($linea));
        }
    }
});
