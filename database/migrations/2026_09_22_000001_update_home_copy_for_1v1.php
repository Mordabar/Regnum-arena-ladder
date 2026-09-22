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

        $this->reemplazar('home_tagline', self::VIEJOS_TAGLINE, self::NUEVO_TAGLINE);
        $this->reemplazar('rules_excerpt', self::VIEJOS_EXTRACTO, self::NUEVO_EXTRACTO);
    }

    /**
     * Deshacer no restaura: solo deja de imponer el texto nuevo.
     *
     * Volver a poner el viejo seria peor que no hacer nada. Las cuatro
     * variantes de tagline colapsarian en una sola -se pierde de cual venia el
     * entorno- y, si el admin hubiera escrito a mano justo el texto nuevo, se
     * lo pisaria. Una migracion hacia atras no tiene forma de distinguir su
     * propio trabajo del de una persona.
     */
    public function down(): void
    {
        // A proposito.
    }

    /**
     * Cambia el valor solo si sigue siendo uno de los textos de serie.
     *
     * La comparacion va fila a fila y en PHP, no con un `whereIn`. En MySQL la
     * columna es utf8mb4_..._ci: ahi `whereIn` ignora mayusculas Y acentos, asi
     * que un texto que el admin hubiera escrito cambiando solo una tilde se
     * daria por "el de serie" y se sobrescribiria. En SQLite, donde corren los
     * tests, la comparacion es binaria y eso no se veria nunca.
     *
     * @param  array<int, string>  $viejos
     */
    private function reemplazar(string $clave, array $viejos, string $nuevo): void
    {
        $fila = DB::table('app_settings')->where('key', $clave)->first();

        if ($fila === null || !in_array((string) $fila->value, $viejos, true)) {
            return;
        }

        DB::table('app_settings')->where('key', $clave)->update(['value' => $nuevo]);
    }
};
