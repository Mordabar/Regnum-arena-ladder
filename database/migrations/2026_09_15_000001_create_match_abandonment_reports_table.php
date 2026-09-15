<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los avisos de abandono viven aparte del reporte de resultado.
 *
 * `match_reports` tiene match_id unico y claimed_winner_team obligatorio: es el
 * reporte de quien gano, uno por enfrentamiento. Un abandono no es eso -no dice
 * quien gano, dice quien se fue- y puede avisarlo mas de uno, el compañero y el
 * rival, del mismo enfrentamiento. Meterlo en la misma tabla obligaria a
 * inventar un ganador y a quedarse con un solo aviso.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('match_abandonment_reports')) {
            return;
        }

        Schema::create('match_abandonment_reports', function (Blueprint $table) {
            $table->id();
            // Con borrado en cascada, igual que match_results y match_reports:
            // un aviso no sobrevive al enfrentamiento que lo origino.
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->unsignedBigInteger('reported_by_player_id')->nullable();
            // A quien se señala. Puede ser un rival o el propio compañero.
            $table->unsignedBigInteger('accused_player_id');
            $table->text('note')->nullable();
            $table->json('evidence_paths')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            // Moderacion entra al panel con su propia cuenta, que no es un
            // `users`. Su nombre va aparte para no falsear el otro campo.
            $table->string('reviewed_by_admin', 64)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index('accused_player_id');
            $table->index('status');
            // Un jugador no señala dos veces al mismo en el mismo
            // enfrentamiento; repetir el aviso no lo hace mas cierto.
            $table->unique(['match_id', 'reported_by_player_id', 'accused_player_id'], 'abandono_unico_por_avisador');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_abandonment_reports');
    }
};
