<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El punto de encuentro, escrito en el propio enfrentamiento.
 *
 * Antes el punto se calculaba en el navegador cada vez que alguien abria el
 * mapa. Dos jugadores del mismo cruce podian ver sitios distintos por dos
 * motivos: uno tenia el fichero de zonas cacheado del dia anterior, o el admin
 * habia movido el punto entre que uno abrio el mapa y el otro lo abrio.
 *
 * Con el punto guardado aqui, el cruce dice a donde hay que ir y nadie calcula
 * nada. Mover una zona a partir de ahora solo afecta a los cruces nuevos, que
 * es lo que cualquiera espera.
 *
 * `meeting_slot` guarda si salio el primer punto o el segundo, solo para poder
 * contarlo y enseñarlo; el que manda es `meeting_point`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('matches')) {
            return;
        }

        Schema::table('matches', function (Blueprint $table) {
            if (!Schema::hasColumn('matches', 'meeting_slot')) {
                $table->unsignedTinyInteger('meeting_slot')->nullable()->after('zone');
            }

            if (!Schema::hasColumn('matches', 'meeting_point')) {
                $table->json('meeting_point')->nullable()->after('meeting_slot');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('matches')) {
            return;
        }

        Schema::table('matches', function (Blueprint $table) {
            foreach (['meeting_point', 'meeting_slot'] as $columna) {
                if (Schema::hasColumn('matches', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
