<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->string('match_code', 10)->unique(); // ARENA-1234
            $table->json('team_ignis'); // [{player_id, character_name, discord_id}]
            $table->json('team_syrtis'); // [{player_id, character_name, discord_id}]
            $table->json('team_alsius'); // [{player_id, character_name, discord_id}]
            $table->enum('zone', ['ign', 'syr', 'als']); // Zona asignada
            $table->enum('status', ['pending_acceptance', 'accepted', 'in_progress', 'completed', 'cancelled', 'no_show'])->default('pending_acceptance');
            $table->enum('winner_realm', ['ignis', 'syrtis', 'alsius'])->nullable();
            $table->integer('estimated_mmr_avg');
            $table->timestamp('created_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at'); // 10 minutos para aceptar
            $table->text('admin_notes')->nullable();
            
            $table->index(['status']);
            $table->index(['zone']);
            $table->index(['created_at']);
            $table->index(['match_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};