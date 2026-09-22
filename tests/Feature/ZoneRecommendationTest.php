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
    foreach (['ignis|syrtis', 'alsius|ignis', 'alsius|syrtis'] as $cruce) {
        [$realmA, $realmB] = explode('|', $cruce);

        expect(elegirZona($realmA, $realmB))->toBeIn(ArenaMatch::ZONE_PREFERENCES[$cruce]);
    }
});

it('da igual el orden en que lleguen los dos reinos', function () {
    expect(ArenaMatch::preferredZonesFor('ignis', 'syrtis'))
        ->toBe(ArenaMatch::preferredZonesFor('syrtis', 'ignis'));
    expect(ArenaMatch::preferredZonesFor('alsius', 'ignis'))
        ->toBe(ArenaMatch::preferredZonesFor('ignis', 'alsius'));
    expect(ArenaMatch::preferredZonesFor('syrtis', 'alsius'))
        ->toBe(ArenaMatch::preferredZonesFor('alsius', 'syrtis'));
});

it('sortea entre las zonas de la frontera en vez de repetir siempre la misma', function () {
    $salidas = collect(range(1, 60))
        ->map(fn () => elegirZona('syrtis', 'ignis'))
        ->unique();

    // Con siete zonas y sesenta sorteos, salir siempre la misma seria un
    // sorteo roto, no mala suerte.
    expect($salidas->count())->toBeGreaterThan(1);
    expect($salidas->diff(ArenaMatch::ZONE_PREFERENCES['ignis|syrtis']))->toBeEmpty();
});

it('esquiva las zonas de la frontera que ya estan ocupadas', function () {
    $frontera = ArenaMatch::ZONE_PREFERENCES['ignis|syrtis'];

    foreach (array_slice($frontera, 0, 5) as $zona) {
        ocuparZona($zona, 'syrtis', 'ignis');
    }

    expect(elegirZona('syrtis', 'ignis'))->toBeIn(array_slice($frontera, 5));
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
    ocuparZona('emerald_pass', 'ignis', 'alsius');

    expect(elegirZona('syrtis', 'ignis'))
        ->toBeIn(ArenaMatch::ZONE_PREFERENCES['ignis|syrtis']);
});

it('reconoce como ocupada una zona guardada con otra escritura', function () {
    // Los enfrentamientos viejos guardaron la zona con su nombre largo. Si eso
    // no cuenta como ocupada, dos cruces acaban en el mismo sitio.
    $frontera = ArenaMatch::ZONE_PREFERENCES['alsius|syrtis'];

    ocuparZona('Aggersborg Bay', 'alsius', 'syrtis');
    ocuparZona('bridge watch', 'alsius', 'syrtis');

    expect(elegirZona('alsius', 'syrtis'))->toBeIn(array_slice($frontera, 2));
});

it('deja la zona 3 compartida por los dos cruces que la reclaman', function () {
    expect(ArenaMatch::ZONE_PREFERENCES['ignis|syrtis'])->toContain('red_cliff_pass');
    expect(ArenaMatch::ZONE_PREFERENCES['alsius|ignis'])->toContain('red_cliff_pass');
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

it('no manda dos combates seguidos a la misma zona', function () {
    // Es lo que se noto jugando: "parecia que nunca salia de las mismas". Con
    // azar puro sobre tres o cuatro zonas de frontera, la misma sale dos veces
    // seguidas cada pocas tiradas, y el filtro de ocupadas no ayuda porque en
    // cuanto el combate anterior se cierra la zona vuelve al bombo.
    $anterior = null;

    foreach (range(1, 12) as $vuelta) {
        $zona = elegirZona('ignis', 'syrtis');

        expect($zona)->not->toBe($anterior);

        $anterior = $zona;
    }
});

it('reparte los combates entre todas las zonas de la frontera', function () {
    // No basta con no repetir la anterior: si de cuatro zonas solo salieran
    // dos, el reparto seguiria siendo malo aunque fueran alternas.
    $frontera = ArenaMatch::preferredZonesFor('ignis', 'syrtis');
    $salieron = [];

    foreach (range(1, count($frontera) * 4) as $vuelta) {
        $salieron[] = ArenaMatch::normalizeZoneKey(elegirZona('ignis', 'syrtis'));
    }

    expect(array_unique($salieron))->toHaveCount(count($frontera));
});

it('los dos puntos de encuentro se alternan en vez de sortearse', function () {
    // Con dos puntos, el azar repite el mismo la mitad de las veces: dos o
    // tres combates en el mismo claro se leen como que el segundo punto no
    // funciona.
    \App\Models\ArenaZone::updateOrCreate(['key' => 'central_ruins'], [
        'number' => 7,
        'name' => 'Zona de prueba',
        'coords' => [[400, 400], [400, 500], [500, 500], [500, 400]],
        'meeting' => [420, 420],
        'meeting_b' => [480, 480],
    ]);

    $servicio = app(\App\Services\ArenaZoneService::class);
    $servicio->olvidar();

    $puestos = [];

    foreach (range(1, 6) as $vuelta) {
        $puestos[] = $servicio->elegirPuntoDeEncuentro('central_ruins')['slot'];
    }

    expect($puestos)->toBe([1, 2, 1, 2, 1, 2]);
});
