<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIGRACIÓN DE PRUEBA PARA MATCHES
 * Esta tabla es independiente del sistema principal
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_matches', function (Blueprint $table) {
            $table->id();
            $table->string('match_id')->unique(); // ID único del match de prueba
            $table->enum('realm', ['ignis', 'syrtis', 'alsius']); // Reino del match
            $table->enum('status', ['waiting', 'in_progress', 'completed', 'cancelled'])->default('waiting');
            
            // Jugadores del equipo (usa IDs reales pero tabla separada)
            $table->foreignId('player1_id')->constrained('players');
            $table->foreignId('player2_id')->constrained('players');
            $table->foreignId('player3_id')->constrained('players');
            
            // Información del match de prueba
            $table->integer('estimated_avg_mmr')->nullable();
            $table->text('zone_info')->nullable();
            $table->timestamp('match_started_at')->nullable();
            $table->timestamp('match_completed_at')->nullable();
            
            // Resultados de prueba (NO afectan MMR real)
            $table->enum('result', ['win', 'loss', 'draw', 'forfeit'])->nullable();
            $table->integer('mmr_change_p1')->nullable(); 
            $table->integer('mmr_change_p2')->nullable();
            $table->integer('mmr_change_p3')->nullable();
            
            // Metadata de prueba
            $table->json('team_composition')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_test')->default(true); // Marcador de prueba
            
            $table->timestamps();
            
            // Índices
            $table->index('realm');
            $table->index('status');
            $table->index('is_test');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_matches');
    }
};