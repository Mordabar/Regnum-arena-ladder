<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los avisos de abandono se van con su enfrentamiento.
 *
 * La tabla se creo con `match_id` indexado pero sin clave foranea, al reves que
 * `match_results` y `match_reports`, que si la tienen con borrado en cascada.
 * Al borrar un enfrentamiento esas dos caen solas y los avisos sobrevivian
 * apuntando a una fila que ya no existe; despues, resolver uno desde el panel
 * reventaba con ModelNotFoundException y un 500.
 *
 * Hoy la ruta no es alcanzable -solo se borran cruces sin empezar y un aviso
 * exige el combate en curso-, pero es una bomba de relojeria para el dia que
 * eso cambie, y el codigo no deberia depender de esa coincidencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('match_abandonment_reports')) {
            return;
        }

        // Limpieza previa: con huerfanos ya dentro, añadir la clave falla.
        DB::table('match_abandonment_reports')
            ->whereNotIn('match_id', DB::table('matches')->select('id'))
            ->delete();

        // SQLite no sabe añadir una clave foranea a una tabla que ya existe, y
        // ahi el borrado en cascada lo cubre el propio motor solo si la tabla
        // se creo con ella. El banco de pruebas corre migraciones desde cero,
        // asi que basta con no romper aqui.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('match_abandonment_reports', function (Blueprint $table) {
            $table->foreign('match_id')
                ->references('id')
                ->on('matches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('match_abandonment_reports')
            || DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('match_abandonment_reports', function (Blueprint $table) {
            $table->dropForeign(['match_id']);
        });
    }
};
