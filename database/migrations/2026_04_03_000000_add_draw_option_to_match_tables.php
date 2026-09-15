<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ENUM y MODIFY son exclusivos de MySQL.
        //
        // OJO: lo que decia aqui -"en sqlite la columna es texto libre, asi que
        // ya acepta 'draw' sin tocar nada"- era falso. $table->enum() en SQLite
        // no crea un ENUM, pero si un CHECK con la lista de valores, y ese
        // CHECK se quedaba sin 'draw': el empate reventaba en local y en el
        // banco de pruebas mientras en produccion funcionaba. Lo arregla la
        // migracion 2026_09_15_000004.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Alter result enum in match_results to include 'draw'
        DB::statement("ALTER TABLE match_results MODIFY COLUMN result ENUM('win', 'loss', 'no_show', 'draw') NOT NULL");

        // Alter winner_team enum in matches to include 'draw'
        DB::statement("ALTER TABLE matches MODIFY COLUMN winner_team ENUM('team_a', 'team_b', 'draw') NULL");

        // Alter claimed_winner_team enum in match_reports to include 'draw'
        DB::statement("ALTER TABLE match_reports MODIFY COLUMN claimed_winner_team ENUM('team_a', 'team_b', 'draw') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE match_results MODIFY COLUMN result ENUM('win', 'loss', 'no_show') NOT NULL");
        DB::statement("ALTER TABLE matches MODIFY COLUMN winner_team ENUM('team_a', 'team_b') NULL");
        DB::statement("ALTER TABLE match_reports MODIFY COLUMN claimed_winner_team ENUM('team_a', 'team_b') NOT NULL");
    }
};
