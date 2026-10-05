<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pone fechas a la temporada en marcha: del 8 de abril de 2026 al 29 de
 * noviembre de 2026 a las 23:59, cerrandose sola.
 *
 * Solo toca la temporada ABIERTA y solo si todavia no tiene fecha de fin: si el
 * admin ya la configuro, esto no pisa nada. Al cerrarse no reparte mas premios
 * ni reinicia el ranking, y la siguiente queda abierta sin fecha: la plataforma
 * sigue online.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('arena_seasons') || !Schema::hasColumn('arena_seasons', 'auto_close')) {
            return;
        }

        $actual = DB::table('arena_seasons')
            ->where('status', 'active')
            ->whereNull('ends_at')
            ->orderByDesc('starts_at')
            ->first();

        if ($actual === null) {
            return;
        }

        $zona = (string) config('arena.season_timezone', 'America/Bogota');

        DB::table('arena_seasons')->where('id', $actual->id)->update([
            'starts_at' => Carbon::create(2026, 4, 8, 0, 0, 0, $zona)->utc(),
            'ends_at' => Carbon::create(2026, 11, 29, 23, 59, 0, $zona)->utc(),
            'auto_close' => true,
            'next_name' => 'Season 1',
            'next_duration_days' => null,
            'reset_on_close' => false,
            'next_prizes_enabled' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Las fechas no se deshacen: no sabemos que habia antes.
    }
};
