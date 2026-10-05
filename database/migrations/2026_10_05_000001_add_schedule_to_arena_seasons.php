<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El calendario de una temporada.
 *
 * `starts_at` y `ends_at` ya existian. Hasta ahora `ends_at` solo se rellenaba
 * al cerrar (el dia que de verdad acabo); ahora, en una temporada abierta, es el
 * dia en que esta previsto que acabe, y al cerrarla pasa a ser el dia en que
 * acabo. Lo nuevo es lo que debe pasar cuando llegue esa fecha, guardado en la
 * propia temporada para que cada una pueda hacer una cosa distinta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('arena_seasons')) {
            return;
        }

        Schema::table('arena_seasons', function (Blueprint $table) {
            // Cerrarse sola al llegar a ends_at. Apagado por defecto: una
            // temporada sin fecha de fin nunca se cierra sola.
            if (!Schema::hasColumn('arena_seasons', 'auto_close')) {
                $table->boolean('auto_close')->default(false)->after('ends_at');
            }

            // 'manual' o 'auto': quien la cerro, para el historial.
            if (!Schema::hasColumn('arena_seasons', 'closed_reason')) {
                $table->string('closed_reason', 20)->nullable()->after('auto_close');
            }

            // Que pasa al cerrarla. Nombre de la siguiente (si no, se deduce
            // del suyo) y cuantos dias dura (si no, queda abierta sin fecha).
            if (!Schema::hasColumn('arena_seasons', 'next_name')) {
                $table->string('next_name', 120)->nullable()->after('closed_reason');
            }

            if (!Schema::hasColumn('arena_seasons', 'next_duration_days')) {
                $table->unsignedSmallInteger('next_duration_days')->nullable()->after('next_name');
            }

            // Poner el ranking a cero justo despues de congelar el podio.
            if (!Schema::hasColumn('arena_seasons', 'reset_on_close')) {
                $table->boolean('reset_on_close')->default(false)->after('next_duration_days');
            }

            // Si la siguiente reparte premios. Los premios son un ajuste
            // global; apagarlos es lo que hace falta cuando una temporada
            // termina y la siguiente es solo juego libre.
            if (!Schema::hasColumn('arena_seasons', 'next_prizes_enabled')) {
                $table->boolean('next_prizes_enabled')->default(true)->after('reset_on_close');
            }

            $table->index(['status', 'auto_close', 'ends_at'], 'arena_seasons_schedule_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('arena_seasons')) {
            return;
        }

        Schema::table('arena_seasons', function (Blueprint $table) {
            $table->dropIndex('arena_seasons_schedule_index');
        });

        Schema::table('arena_seasons', function (Blueprint $table) {
            foreach (['next_prizes_enabled', 'reset_on_close', 'next_duration_days', 'next_name', 'closed_reason', 'auto_close'] as $columna) {
                if (Schema::hasColumn('arena_seasons', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
