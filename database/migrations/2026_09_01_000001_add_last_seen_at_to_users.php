<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registra la ultima vez que cada usuario entro al ladder.
 *
 * Hasta ahora no existia ningun dato de este tipo, asi que era imposible saber
 * quien lleva tiempo sin aparecer. El unico campo parecido era `is_active` en
 * players, que significa otra cosa: si el personaje esta habilitado para jugar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('email_verified_at');
                $table->index('last_seen_at');
            }
        });

        // Backfill: a quien ya existe se le da como ultima visita la senal mas
        // reciente que haya dejado en el ladder (su ultima entrada en cola),
        // y si nunca encolo, la fecha de su registro. Poner a todos la fecha de
        // registro haria que el dia del despliegue media base apareciese "sin
        // actividad" aunque estuviese jugando la semana pasada.
        if (Schema::hasColumn('users', 'last_seen_at')) {
            $lastQueue = DB::table('queues')
                ->join('players', 'players.id', '=', 'queues.player_id')
                ->groupBy('players.user_id')
                ->select('players.user_id', DB::raw('MAX(queues.joined_at) as last_queue'))
                ->pluck('last_queue', 'user_id');

            DB::table('users')
                ->whereNull('last_seen_at')
                ->orderBy('id')
                ->select('id', 'created_at')
                ->chunk(200, function ($users) use ($lastQueue) {
                    foreach ($users as $user) {
                        $candidates = array_filter([$user->created_at, $lastQueue[$user->id] ?? null]);

                        DB::table('users')
                            ->where('id', $user->id)
                            ->update(['last_seen_at' => empty($candidates) ? now() : max($candidates)]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_seen_at')) {
                $table->dropIndex(['last_seen_at']);
                $table->dropColumn('last_seen_at');
            }
        });
    }
};
