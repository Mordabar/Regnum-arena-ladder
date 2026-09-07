<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue POR QUE un personaje esta deshabilitado.
 *
 * `is_active = false` cubria dos casos muy distintos: el jugador borro el
 * personaje (y sus partidas se conservan) o un administrador lo apago. Los dos
 * se mostraban como "Inactivo", que ademas se confunde con la metrica de
 * actividad del ladder. Con el motivo guardado, cada uno se puede llamar por su
 * nombre: eliminado o deshabilitado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            if (!Schema::hasColumn('players', 'deactivated_reason')) {
                $table->string('deactivated_reason', 32)->nullable()->after('is_active');
                $table->timestamp('deactivated_at')->nullable()->after('deactivated_reason');
                $table->index('deactivated_reason');
            }
        });

        if (!Schema::hasColumn('players', 'deactivated_reason')) {
            return;
        }

        // Los que ya estaban apagados: el sufijo del nombre delata a los que
        // borro su dueno; el resto los apago un administrador.
        DB::table('players')
            ->where('is_active', false)
            ->whereNull('deactivated_reason')
            ->where('character_name', 'like', '% [INACTIVO]')
            ->update([
                'deactivated_reason' => 'deleted_by_player',
                'deactivated_at' => DB::raw('updated_at'),
            ]);

        DB::table('players')
            ->where('is_active', false)
            ->whereNull('deactivated_reason')
            ->update([
                'deactivated_reason' => 'disabled_by_admin',
                'deactivated_at' => DB::raw('updated_at'),
            ]);

        // El sufijo pasa a decir lo que de verdad paso. Se hace aqui y no en la
        // vista porque el nombre esta guardado en la fila y en los equipos de
        // los enfrentamientos ya jugados.
        foreach (DB::table('players')->where('character_name', 'like', '% [INACTIVO]')->get(['id', 'character_name']) as $player) {
            DB::table('players')
                ->where('id', $player->id)
                ->update(['character_name' => str_replace(' [INACTIVO]', ' [ELIMINADO]', $player->character_name)]);
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            if (Schema::hasColumn('players', 'deactivated_reason')) {
                $table->dropIndex(['deactivated_reason']);
                $table->dropColumn(['deactivated_reason', 'deactivated_at']);
            }
        });
    }
};
