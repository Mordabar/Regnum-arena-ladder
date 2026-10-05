<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fechas exactas de la temporada que sigue a otra: cuando empieza y cuando
 * termina, en vez de "dura N dias". `next_duration_days` queda por compatibilidad
 * con lo que ya estuviera guardado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('arena_seasons')) {
            return;
        }

        Schema::table('arena_seasons', function (Blueprint $table) {
            if (!Schema::hasColumn('arena_seasons', 'next_starts_at')) {
                $table->dateTime('next_starts_at')->nullable();
            }

            if (!Schema::hasColumn('arena_seasons', 'next_ends_at')) {
                $table->dateTime('next_ends_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('arena_seasons')) {
            return;
        }

        Schema::table('arena_seasons', function (Blueprint $table) {
            foreach (['next_starts_at', 'next_ends_at'] as $c) {
                if (Schema::hasColumn('arena_seasons', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
