<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->decimal('pl_points_3v3', 8, 2)->default(0)->after('losses');
            $table->integer('mmr_3v3')->default(1000)->after('pl_points_3v3');
            $table->integer('matches_played_3v3')->default(0)->after('mmr_3v3');
            $table->integer('wins_3v3')->default(0)->after('matches_played_3v3');
            $table->integer('losses_3v3')->default(0)->after('wins_3v3');
            $table->index(['pl_points_3v3', 'mmr_3v3'], 'players_3v3_ladder_index');
        });

        Schema::table('queues', function (Blueprint $table) {
            $table->string('arena_mode', 8)->default('2v2')->after('queue_type');
            $table->index(['arena_mode', 'status', 'queue_type'], 'queues_mode_status_type_index');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->string('arena_mode', 8)->default('2v2')->after('queue_mode');
            $table->index(['arena_mode', 'status', 'completed_at'], 'matches_mode_status_completed_index');
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->string('arena_mode', 8)->default('2v2')->after('realm');
            $table->index(['arena_mode', 'status'], 'parties_mode_status_index');
        });

        if (Schema::hasTable('app_settings')) {
            foreach (['2v2', '3v3'] as $mode) {
                DB::table('app_settings')->updateOrInsert(
                    ['key' => 'mode_' . $mode . '_enabled'],
                    [
                        'group' => 'season',
                        'value' => '1',
                        'type' => 'boolean',
                        'is_public' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            DB::table('app_settings')
                ->where('key', 'home_tagline')
                ->whereIn('value', [
                    'Conquest PvP 2v2 por reino y subclase',
                    'Conquest PvP 3v3 por reino y subclase',
                ])
                ->update(['value' => 'Conquest PvP 2v2 y 3v3 por reino y subclase']);

            DB::table('app_settings')
                ->where('key', 'rules_excerpt')
                ->where('value', 'like', 'Random y premade 2v2%')
                ->update(['value' => 'Random y premade, anonimato rival y ladder independiente por modalidad.']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('app_settings')) {
            DB::table('app_settings')->whereIn('key', ['mode_2v2_enabled', 'mode_3v3_enabled'])->delete();
        }

        Schema::table('parties', function (Blueprint $table) {
            $table->dropIndex('parties_mode_status_index');
            $table->dropColumn('arena_mode');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex('matches_mode_status_completed_index');
            $table->dropColumn('arena_mode');
        });

        Schema::table('queues', function (Blueprint $table) {
            $table->dropIndex('queues_mode_status_type_index');
            $table->dropColumn('arena_mode');
        });

        Schema::table('players', function (Blueprint $table) {
            $table->dropIndex('players_3v3_ladder_index');
            $table->dropColumn([
                'pl_points_3v3',
                'mmr_3v3',
                'matches_played_3v3',
                'wins_3v3',
                'losses_3v3',
            ]);
        });
    }
};
