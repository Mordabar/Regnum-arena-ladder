<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->onDelete('cascade');
            $table->foreignId('player_id')->constrained()->onDelete('cascade');
            $table->enum('result', ['win', 'loss', 'no_show']); 
            $table->integer('pl_change'); // +3, -1, -2 (no show)
            $table->integer('mmr_change'); // Calculado por algoritmo
            $table->integer('pl_before');
            $table->integer('pl_after');
            $table->integer('mmr_before');
            $table->integer('mmr_after');
            $table->boolean('reported_by_admin')->default(false);
            $table->timestamp('created_at');
            
            $table->index(['player_id']);
            $table->index(['match_id']);
            $table->unique(['match_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_results');
    }
};