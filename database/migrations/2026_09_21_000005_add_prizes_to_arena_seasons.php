<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que repartio cada temporada, guardado con ella.
 *
 * El reparto vive en los ajustes y cambia de una temporada a otra. Si el Salon
 * de la Fama lo leyera de ahi, la temporada 0 diria lo que reparte la 3, y una
 * vitrina que miente no es una vitrina. Se congela al cerrarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('arena_seasons')) {
            return;
        }

        Schema::table('arena_seasons', function (Blueprint $table) {
            if (!Schema::hasColumn('arena_seasons', 'prizes')) {
                $table->json('prizes')->nullable()->after('enabled_modes');
            }

            if (!Schema::hasColumn('arena_seasons', 'prize_currency')) {
                $table->string('prize_currency', 80)->nullable()->after('prizes');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('arena_seasons')) {
            return;
        }

        Schema::table('arena_seasons', function (Blueprint $table) {
            foreach (['prize_currency', 'prizes'] as $columna) {
                if (Schema::hasColumn('arena_seasons', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
