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
            $table->string('match_code', 20)->unique();
            $table->string('report_token', 24)->unique();
            $table->enum('queue_mode', ['random', 'premade'])->default('random');
            $table->enum('team_a_realm', ['ignis', 'syrtis', 'alsius']);
            $table->enum('team_b_realm', ['ignis', 'syrtis', 'alsius']);
            $table->json('team_a');
            $table->json('team_b');
            $table->string('zone', 50);
            $table->enum('status', ['pending_acceptance', 'accepted', 'in_progress', 'completed', 'cancelled', 'void', 'disputed'])->default('pending_acceptance');
            $table->enum('winner_team', ['team_a', 'team_b'])->nullable();
            $table->enum('winner_realm', ['ignis', 'syrtis', 'alsius'])->nullable();
            $table->integer('estimated_mmr_avg')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['zone']);
            $table->index(['created_at']);
            $table->index(['match_code']);
            $table->index(['report_token']);
            $table->index(['team_a_realm']);
            $table->index(['team_b_realm']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
