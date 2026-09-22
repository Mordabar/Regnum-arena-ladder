<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pone al dia el texto de la portada.
 *
 * Decia "2v2 y 3v3 por reino y subclase" y el duelo 1v1 lleva semanas
 * abierto: la primera linea del sitio se dejaba fuera una modalidad entera.
 *
 * Y el subtitulo enumeraba caracteristicas -random, premade, anonimato,
 * PL/MMR- que no le dicen nada a quien llega sin conocer el sitio. Ahora
 * cuenta lo que se hace aqui, en el orden en que se hace.
 *
 * Solo se toca lo que sigue teniendo el texto de serie: si el admin lo
 * cambio a mano, su version manda.
 */
return new class extends Migration
{
    private const VIEJOS_TAGLINE = [
        'Conquest PvP por reino y subclase',
        'Conquest PvP 2v2 y 3v3 por reino y subclase',
        'Conquest PvP 2v2 por reino y subclase',
        'Conquest PvP 3v3 por reino y subclase',
    ];

    private const NUEVO_TAGLINE = 'Conquest PvP 1v1, 2v2 y 3v3 en la Zona de Guerra';

    private const VIEJOS_EXTRACTO = [
        'Random y premade, anonimato rival, reporte con capturas y ladder automatico por PL/MMR.',
        'Random y premade, anonimato rival, reporte con capturas y ladder automático por PL/MMR.',
        'Random y premade con ladder acumulado durante la temporada.',
        'Random y premade, anonimato rival y ladder independiente por modalidad.',
        'Random y premade, anonimato rival y ladder automatico.',
    ];

    private const NUEVO_EXTRACTO = 'Busca contrincante, quedad en el punto marcado, pelead y reporta el resultado. '
        . 'Cada combate te sube en el ladder: se juega por los premios de la temporada y por quedarse en el '
        . 'Salon de la Fama, donde solo aguantan los mejores.';

    public function up(): void
    {
        if (!Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')
            ->where('key', 'home_tagline')
            ->whereIn('value', self::VIEJOS_TAGLINE)
            ->update(['value' => self::NUEVO_TAGLINE]);

        DB::table('app_settings')
            ->where('key', 'rules_excerpt')
            ->whereIn('value', self::VIEJOS_EXTRACTO)
            ->update(['value' => self::NUEVO_EXTRACTO]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')
            ->where('key', 'home_tagline')
            ->where('value', self::NUEVO_TAGLINE)
            ->update(['value' => 'Conquest PvP por reino y subclase']);

        DB::table('app_settings')
            ->where('key', 'rules_excerpt')
            ->where('value', self::NUEVO_EXTRACTO)
            ->update(['value' => 'Random y premade, anonimato rival, reporte con capturas y ladder automatico por PL/MMR.']);
    }
};
