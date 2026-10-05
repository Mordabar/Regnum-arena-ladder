<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una temporada programada: estado 'scheduled', con su fecha de inicio.
 *
 * No hace falta tabla nueva: la temporada programada es una fila de
 * arena_seasons que todavia no esta abierta. Lo unico nuevo es si reparte
 * premios en cuanto abra, porque `next_prizes_enabled` habla de la SIGUIENTE
 * a ella y no de ella misma.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('arena_seasons') || Schema::hasColumn('arena_seasons', 'prizes_on_open')) {
            return;
        }

        Schema::table('arena_seasons', function (Blueprint $table) {
            $table->boolean('prizes_on_open')->default(true)->after('next_prizes_enabled');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('arena_seasons') && Schema::hasColumn('arena_seasons', 'prizes_on_open')) {
            Schema::table('arena_seasons', function (Blueprint $table) {
                $table->dropColumn('prizes_on_open');
            });
        }
    }
};
