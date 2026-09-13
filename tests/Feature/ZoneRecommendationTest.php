<?php

use App\Models\ArenaMatch;
use App\Services\ArenaMatchmakingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function elegirZona(string $realmA, string $realmB): string
{
    $servicio = app(ArenaMatchmakingService::class);
    $metodo = new ReflectionMethod($servicio, 'pickZone');
    $metodo->setAccessible(true);

    return $metodo->invoke($servicio, $realmA, $realmB);
}

function ocuparZona(string $zona, string $realmA, string $realmB): ArenaMatch
{
    return ArenaMatch::create([
        'match_code' => ArenaMatch::generateMatchCode(),
        'report_token' => ArenaMatch::generateReportToken(),
        'status' => 'in_progress',
        'zone' => $zona,
        'queue_mode' => 'random',
        'arena_mode' => '2v2',
        'team_a_realm' => $realmA,
        'team_b_realm' => $realmB,
        'team_a' => [],
        'team_b' => [],
        'player_count' => 4,
        'expires_at' => now()->addMinutes(30),
    ]);
}

it('manda cada cruce de reinos a su propia frontera', function () {
    expect(elegirZona('syrtis', 'ignis'))->toBe('etreng_outskirts');
    expect(elegirZona('ignis', 'alsius'))->toBe('emerald_pass');
    expect(elegirZona('alsius', 'syrtis'))->toBe('aggersborg_bay');
});

it('da igual el orden en que lleguen los dos reinos', function () {
    expect(elegirZona('ignis', 'syrtis'))->toBe(elegirZona('syrtis', 'ignis'));
    expect(elegirZona('alsius', 'ignis'))->toBe(elegirZona('ignis', 'alsius'));
    expect(elegirZona('syrtis', 'alsius'))->toBe(elegirZona('alsius', 'syrtis'));
});

it('baja a la siguiente recomendada cuando la primera esta ocupada', function () {
    ocuparZona('etreng_outskirts', 'syrtis', 'ignis');

    expect(elegirZona('syrtis', 'ignis'))->toBe('obsidian_watch');

    ocuparZona('obsidian_watch', 'syrtis', 'ignis');

    expect(elegirZona('syrtis', 'ignis'))->toBe('red_cliff_pass');
});

it('recurre a una zona de fuera solo cuando no queda ninguna recomendada', function () {
    foreach (ArenaMatch::ZONE_PREFERENCES['alsius|ignis'] as $zona) {
        ocuparZona($zona, 'ignis', 'alsius');
    }

    $elegida = elegirZona('ignis', 'alsius');

    expect($elegida)->not->toBeIn(ArenaMatch::ZONE_PREFERENCES['alsius|ignis']);
    expect(ArenaMatch::zoneKeys())->toContain($elegida);
});

it('no roba a otro cruce su frontera mientras le queden zonas propias', function () {
    // Zona 8 es la primera de Syrtis contra Ignis. Con un Ignis contra Alsius
    // en marcha en la 2, el siguiente Syrtis contra Ignis sigue yendo a la 8.
    ocuparZona('emerald_pass', 'ignis', 'alsius');

    expect(elegirZona('syrtis', 'ignis'))->toBe('etreng_outskirts');
});

it('con el mapa entero ocupado prefiere una recomendada antes que una lejana', function () {
    // Todas las zonas en uso por cruces de otros reinos, salvo las de la
    // frontera de Alsius contra Syrtis, que arrastran un combate cada una.
    foreach (ArenaMatch::zoneKeys() as $zona) {
        if (in_array($zona, ArenaMatch::ZONE_PREFERENCES['alsius|syrtis'], true)) {
            ocuparZona($zona, 'alsius', 'syrtis');

            continue;
        }

        ocuparZona($zona, 'ignis', 'alsius');
    }

    expect(elegirZona('alsius', 'syrtis'))
        ->toBeIn(ArenaMatch::ZONE_PREFERENCES['alsius|syrtis']);
});

it('no recomienda nada para un cruce sin frontera declarada', function () {
    expect(ArenaMatch::preferredZonesFor('ignis', 'ignis'))->toBe([]);
    expect(ArenaMatch::preferredZonesFor('ignis', ''))->toBe([]);
    expect(ArenaMatch::preferredZonesFor('ignis', 'gaia'))->toBe([]);
});

it('mantiene las tres fronteras dentro del catalogo de zonas', function () {
    foreach (ArenaMatch::ZONE_PREFERENCES as $cruce => $zonas) {
        expect($zonas)->not->toBeEmpty();

        foreach ($zonas as $zona) {
            expect(ArenaMatch::zoneKeys())->toContain($zona);
        }

        expect(array_unique($zonas))->toHaveCount(count($zonas));
    }
});
