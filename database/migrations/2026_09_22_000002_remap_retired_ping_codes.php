<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Traduce los avisos de los codigos que se retiraron del catalogo.
 *
 * El chat paso de diez frases a seis, y varias decian lo mismo. Los avisos ya
 * mandados con una de las retiradas siguen en la tabla, y el modelo los pinta
 * como "Aviso" -que es el respaldo correcto para un codigo desconocido, pero
 * no dice nada-. Cada uno se cambia por la frase que mas se le parece, asi que
 * el historial de un combate en marcha sigue leyendose como se escribio.
 *
 * Los cerrados se limpian solos por otro lado, pero remapear todos sale igual
 * de barato que filtrar.
 */
return new class extends Migration
{
    private const EQUIVALENCIAS = [
        'listo' => 'llegue',    // "Listo, cuando quieras" -> "Estoy en el punto"
        'vamos' => 'voy',       // "¡Vamos!"                -> "Voy de camino"
        'tardas' => 'voy',      // "¿Tardas mucho?"         -> "Voy de camino"
        'perdido' => 'un_momento', // "No encuentro el punto" -> "Dame un momento"
    ];

    public function up(): void
    {
        if (!Schema::hasTable('match_pings')) {
            return;
        }

        foreach (self::EQUIVALENCIAS as $retirado => $vigente) {
            DB::table('match_pings')
                ->where('code', $retirado)
                ->update(['code' => $vigente]);
        }
    }

    /**
     * No se deshace: la traduccion pierde el original.
     *
     * Dos codigos retirados apuntan a la misma frase vigente, asi que volver
     * atras tendria que inventarse cual era cual.
     */
    public function down(): void
    {
        // A proposito.
    }
};
