<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('leader_player_id')->constrained('players')->onDelete('cascade');
            $table->string('status')->default('forming'); // forming, queued, dissolved
            $table->char('realm', 10);
            $table->timestamps();
        });

        Schema::create('party_members', function (Blueprint $table) {
            $table->id();
            $table->uuid('party_id');
            $table->foreignId('player_id')->constrained('players')->onDelete('cascade');
            $table->boolean('is_accepted_invite')->default(false);
            $table->boolean('is_leader')->default(false);
            $table->string('conjurer_role')->nullable();
            
            $table->foreign('party_id')->references('id')->on('parties')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['party_id', 'player_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('party_members');
        Schema::dropIfExists('parties');
    }
};
