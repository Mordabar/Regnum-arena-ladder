<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las zonas del mapa, en la base de datos.
 *
 * Hasta ahora vivian en `public/js/arena-zones.js`, un fichero que el panel
 * reescribia a mano. Eso traia tres problemas, y los tres se han dado:
 *
 *   1. El fichero esta versionado en git, asi que cada despliegue lo pisa y se
 *      lleva por delante todo lo que el admin hubiera colocado.
 *   2. El navegador lo cachea. El panel lo pedia con `?v=time()` -por eso el
 *      admin siempre veia lo ultimo- y el mapa del jugador no, asi que un
 *      jugador podia estar viendo un punto de encuentro de hace una semana
 *      mientras su rival veia el nuevo. Es exactamente lo que paso.
 *   3. El servidor no podia leerlo, asi que no habia forma de dejar escrito en
 *      el enfrentamiento a que punto exacto tienen que ir los dos bandos.
 *
 * Con la tabla, el despliegue no pisa nada, el JS se sirve versionado desde
 * aqui, y el emparejador puede congelar el punto al crear el cruce.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('arena_zones')) {
            return;
        }

        Schema::create('arena_zones', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedSmallInteger('number');
            $table->string('name');
            $table->json('coords')->nullable();

            // Los dos puntos de encuentro. Nulos quiere decir "el automatico",
            // que se calcula sobre el contorno. El segundo es opcional del todo:
            // una zona puede tener uno solo.
            $table->json('meeting')->nullable();
            $table->json('meeting_b')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_zones');
    }
};
