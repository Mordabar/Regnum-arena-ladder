<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('character_name');
            $table->enum('subclass', ['knight', 'barbarian', 'hunter', 'marksman', 'conjurer', 'warlock']);
            $table->enum('realm', ['ignis', 'alsius', 'syrtis']);
            $table->decimal('pl_points', 8, 2)->default(0);
            $table->integer('mmr')->default(1000);
            $table->integer('matches_played')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->integer('trust_score')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Índices
            $table->unique(['character_name', 'realm']); // Nombres únicos por reino
            $table->index(['user_id', 'is_active']);
            $table->index(['realm', 'is_active']);
            $table->index(['pl_points', 'mmr']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};