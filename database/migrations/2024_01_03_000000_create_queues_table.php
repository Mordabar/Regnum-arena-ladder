<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->onDelete('cascade');
            $table->enum('queue_type', ['random', 'premade'])->default('random');
            $table->enum('status', ['waiting', 'matched', 'accepted', 'cancelled'])->default('waiting');
            $table->integer('estimated_mmr')->nullable();
            $table->json('team_composition')->nullable(); // Para premades: [{player_id, character_name, subclass}]
            $table->string('premade_leader_discord_id', 20)->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // Para timeouts
            $table->timestamps();
            
            $table->index(['status', 'queue_type']);
            $table->index(['joined_at']);
            $table->index(['estimated_mmr']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queues');
    }
};