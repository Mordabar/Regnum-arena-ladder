<?php

use App\Models\ArenaMatch;
use App\Models\ArenaZone;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('las zonas se llaman como el sitio del mapa', function () {
    expect(ArenaMatch::ZONES['obsidian_watch']['name'])->toBe('Eferias (Efe)')
        ->and(ArenaMatch::ZONES['frozen_bridge']['name'])->toBe('Imperia (Impe)')
        ->and(ArenaMatch::zoneLabel('central_ruins'))->toBe('Zona 7 - Campa sin Orcos');
});

it('la migracion renombra la zona de fabrica y respeta la que el admin ya cambio', function () {
    ArenaZone::query()->delete();
    ArenaZone::create(['key' => 'central_ruins', 'number' => 7, 'name' => 'Zona 7 - Central Ruins']);
    ArenaZone::create(['key' => 'obsidian_watch', 'number' => 9, 'name' => 'Mi zona']);

    (require database_path('migrations/2026_10_08_000001_rename_zones_after_map.php'))->up();

    expect(ArenaZone::where('key', 'central_ruins')->value('name'))->toBe('Zona 7 - Campa sin Orcos')
        ->and(ArenaZone::where('key', 'obsidian_watch')->value('name'))->toBe('Mi zona');
});
