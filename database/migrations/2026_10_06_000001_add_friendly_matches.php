<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partidas amistosas: PvP que no mueven el ranking.
 *
 * `is_ranked` vive en la cola, en la party y en el enfrentamiento. Por defecto
 * es true: todo lo que ya existe sigue siendo competitivo. El tipo se decide al
 * entrar en cola, y el emparejador nunca mezcla las dos cosas.
 *
 * Y `open_next` en la temporada: cerrar una temporada puede dejar abierta la
 * siguiente o no. Sin temporada abierta el ladder esta en pausa y solo se
 * juegan amistosos.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['queues', 'matches', 'parties'] as $tabla) {
            if (!Schema::hasTable($tabla) || Schema::hasColumn($tabla, 'is_ranked')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->boolean('is_ranked')->default(true)->after('arena_mode');
            });
        }

        if (Schema::hasTable('arena_seasons') && !Schema::hasColumn('arena_seasons', 'open_next')) {
            Schema::table('arena_seasons', function (Blueprint $table) {
                $table->boolean('open_next')->default(true)->after('next_prizes_enabled');
            });

            // La temporada en marcha con cierre automatico y sin duracion
            // siguiente es la Season 0: al acabar, el ladder se queda quieto y
            // se juega en amistoso hasta que alguien abra otra.
            DB::table('arena_seasons')
                ->where('status', 'active')
                ->where('auto_close', true)
                ->whereNotNull('ends_at')
                ->whereNull('next_duration_days')
                ->update(['open_next' => false]);
        }
    }

    public function down(): void
    {
        foreach (['queues', 'matches', 'parties'] as $tabla) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'is_ranked')) {
                Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn('is_ranked'));
            }
        }

        if (Schema::hasTable('arena_seasons') && Schema::hasColumn('arena_seasons', 'open_next')) {
            Schema::table('arena_seasons', fn (Blueprint $table) => $table->dropColumn('open_next'));
        }
    }
};
