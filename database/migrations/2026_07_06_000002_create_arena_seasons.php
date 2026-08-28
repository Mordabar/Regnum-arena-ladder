<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('status', 20)->default('active');
            $table->json('enabled_modes');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        Schema::create('season_player_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('arena_seasons')->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('character_name', 80);
            $table->string('realm', 20);
            $table->string('subclass', 30);
            $table->boolean('is_hall_eligible')->default(true);
            $table->decimal('pl_points', 8, 2)->default(0);
            $table->integer('mmr')->default(1000);
            $table->integer('matches_played')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->timestamps();

            $table->unique(['season_id', 'player_id']);
            $table->index(['season_id', 'pl_points', 'mmr'], 'season_stats_ladder_index');
        });

        foreach (['queues', 'matches', 'parties'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('season_id')
                    ->nullable()
                    ->after('arena_mode')
                    ->constrained('arena_seasons')
                    ->nullOnDelete();
            });
        }

        $seasonName = (string) (DB::table('app_settings')->where('key', 'season_name')->value('value') ?: 'Alpha Season');
        $mode = $this->resolveInitialMode();
        $seasonId = DB::table('arena_seasons')->insertGetId([
            'name' => $seasonName,
            'slug' => Str::slug($seasonName) . '-' . now()->format('YmdHis') . '-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'enabled_modes' => json_encode([$mode]),
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('queues')->update(['season_id' => $seasonId]);
        DB::table('matches')->update(['season_id' => $seasonId]);
        DB::table('parties')->update(['season_id' => $seasonId]);

        DB::table('players')->orderBy('id')->each(function ($player) use ($seasonId) {
            $discordId = (string) DB::table('users')->where('id', $player->user_id)->value('discord_id');
            $matches2v2 = (int) $player->matches_played;
            $matches3v3 = (int) $player->matches_played_3v3;
            $totalMatches = $matches2v2 + $matches3v3;
            $mmr = $totalMatches > 0
                ? (int) round((((int) $player->mmr * $matches2v2) + ((int) $player->mmr_3v3 * $matches3v3)) / $totalMatches)
                : 1000;

            DB::table('season_player_stats')->insert([
                'season_id' => $seasonId,
                'player_id' => $player->id,
                'character_name' => $player->character_name,
                'realm' => $player->realm,
                'subclass' => $player->subclass,
                'is_hall_eligible' => !str_starts_with($discordId, 'queue-lab-'),
                'pl_points' => round((float) $player->pl_points + (float) $player->pl_points_3v3, 2),
                'mmr' => $mmr,
                'matches_played' => $totalMatches,
                'wins' => (int) $player->wins + (int) $player->wins_3v3,
                'losses' => (int) $player->losses + (int) $player->losses_3v3,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('players')->where('id', $player->id)->update([
                'pl_points' => round((float) $player->pl_points + (float) $player->pl_points_3v3, 2),
                'mmr' => $mmr,
                'matches_played' => $totalMatches,
                'wins' => (int) $player->wins + (int) $player->wins_3v3,
                'losses' => (int) $player->losses + (int) $player->losses_3v3,
            ]);
        });

        DB::table('app_settings')->whereIn('key', ['mode_2v2_enabled', 'mode_3v3_enabled'])->delete();
        DB::table('app_settings')
            ->where('key', 'rules_excerpt')
            ->where('value', 'like', '%ladder independiente por modalidad%')
            ->update(['value' => 'Random y premade con ladder acumulado durante la temporada.']);
    }

    public function down(): void
    {
        foreach (['queues', 'matches', 'parties'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('season_id');
            });
        }

        Schema::dropIfExists('season_player_stats');
        Schema::dropIfExists('arena_seasons');
    }

    private function resolveInitialMode(): string
    {
        $enabled2v2 = filter_var(DB::table('app_settings')->where('key', 'mode_2v2_enabled')->value('value') ?? true, FILTER_VALIDATE_BOOLEAN);
        $enabled3v3 = filter_var(DB::table('app_settings')->where('key', 'mode_3v3_enabled')->value('value') ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($enabled2v2 !== $enabled3v3) {
            return $enabled3v3 ? '3v3' : '2v2';
        }

        $matches3v3 = DB::table('matches')->where('arena_mode', '3v3')->count();
        $matches2v2 = DB::table('matches')->where('arena_mode', '2v2')->count();

        return $matches3v3 > $matches2v2 ? '3v3' : '2v2';
    }
};
