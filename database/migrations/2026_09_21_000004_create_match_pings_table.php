<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los avisos rapidos de un enfrentamiento.
 *
 * No es un chat. Son diez frases cerradas -voy de camino, ya llegue, me han
 * matado- que sirven para lo unico que hacia falta y no habia: que el rival
 * sepa que estas yendo, en vez de quedarse cinco minutos mirando un claro
 * vacio sin saber si el otro viene o se ha ido a cenar.
 *
 * Viven lo que vive el enfrentamiento. Al cerrarse, se van con el: no hay nada
 * que guardar ahi, y un historial de avisos de hace tres semanas no le importa
 * a nadie.
 *
 * Sin texto libre a proposito: un chat abierto entre rivales de tres reinos
 * distintos es un problema de moderacion, y esto es una herramienta para
 * quedar, no para hablar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('match_pings')) {
            return;
        }

        Schema::create('match_pings', function (Blueprint $table) {
            $table->id();

            // Sin clave foranea: `matches.id` no es igual en todos los
            // despliegues heredados, y una foranea que no se puede crear tumba
            // la migracion entera. La limpieza la hace el mantenimiento.
            $table->string('match_id');
            $table->unsignedBigInteger('player_id');

            // El codigo del aviso, no su texto: el texto vive en PHP y asi se
            // puede cambiar la redaccion sin tocar lo ya enviado.
            $table->string('code', 40);

            $table->timestamps();

            $table->index(['match_id', 'id']);
            $table->index(['match_id', 'player_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_pings');
    }
};
