<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Alter result enum in match_results to include 'draw'
        DB::statement("ALTER TABLE match_results MODIFY COLUMN result ENUM('win', 'loss', 'no_show', 'draw') NOT NULL");
        
        // Alter winner_team enum in matches to include 'draw'
        DB::statement("ALTER TABLE matches MODIFY COLUMN winner_team ENUM('team_a', 'team_b', 'draw') NULL");
        
        // Alter claimed_winner_team enum in match_reports to include 'draw'
        DB::statement("ALTER TABLE match_reports MODIFY COLUMN claimed_winner_team ENUM('team_a', 'team_b', 'draw') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE match_results MODIFY COLUMN result ENUM('win', 'loss', 'no_show') NOT NULL");
        DB::statement("ALTER TABLE matches MODIFY COLUMN winner_team ENUM('team_a', 'team_b') NULL");
        DB::statement("ALTER TABLE match_reports MODIFY COLUMN claimed_winner_team ENUM('team_a', 'team_b') NOT NULL");
    }
};
