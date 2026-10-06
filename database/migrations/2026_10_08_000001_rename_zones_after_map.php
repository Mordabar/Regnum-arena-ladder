<?php

use App\Models\ArenaZone;
use App\Services\ArenaZoneService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Las zonas se llaman como el sitio del mapa donde estan.
 *
 * Hasta ahora llevaban nombres inventados en ingles ("Central Ruins") que no
 * aparecen en el mapa del juego. Solo se renombra la zona que aun conserva su
 * nombre de fabrica: si el admin ya le puso otro, se respeta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('arena_zones')) {
            return;
        }

        $nombres = [
            'frozen_bridge' => ['Frozen Bridge', 'Imperia (Impe)'],
            'emerald_pass' => ['Emerald Pass', 'Menirah'],
            'red_cliff_pass' => ['Red Cliff Pass', 'Llanura de Medenet'],
            'black_fort_shore' => ['Black Fort Shore', 'Shaanarid (Shana)'],
            'merchant_coast' => ['Merchant Coast', 'Pantano'],
            'crimson_canyon' => ['Crimson Canyon', 'Cañón de Daen Rha (Daen)'],
            'central_ruins' => ['Central Ruins', 'Campa sin Orcos'],
            'etreng_outskirts' => ['Etreng Outskirts', 'Jabe'],
            'obsidian_watch' => ['Obsidian Watch', 'Eferias (Efe)'],
            'green_camp' => ['Green Camp', 'Campa Orco'],
            'jagaros_crossroads' => ['Jagaros Crossroads', 'Algaros (Alga)'],
            'bridge_watch' => ['Bridge Watch', 'Trelleborg (Trelle)'],
            'aggersborg_bay' => ['Aggersborg Bay', 'Puente de Pinos Este (PP)'],
            'herth_gulf' => ['Herth Gulf', 'Golpe de Thorkul (Pozo)'],
        ];

        foreach ($nombres as $key => [$antes, $ahora]) {
            $zona = ArenaZone::query()->where('key', $key)->first();

            if ($zona && $zona->name === 'Zona ' . $zona->number . ' - ' . $antes) {
                $zona->update(['name' => 'Zona ' . $zona->number . ' - ' . $ahora]);
            }
        }

        app(ArenaZoneService::class)->olvidar();
    }

    public function down(): void
    {
        // El nombre anterior no se restaura: era un texto de fabrica sin uso.
    }
};
