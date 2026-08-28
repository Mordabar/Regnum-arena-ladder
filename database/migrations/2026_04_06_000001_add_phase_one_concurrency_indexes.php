<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('queues')) {
            Schema::table('queues', function (Blueprint $table) {
                $table->index(['player_id', 'status'], 'queues_player_id_status_index');
                $table->index(['match_id', 'status'], 'queues_match_id_status_index');
            });
        }

        if (Schema::hasTable('party_members')) {
            Schema::table('party_members', function (Blueprint $table) {
                $table->index(['player_id'], 'party_members_player_id_index');
            });
        }

        if (Schema::hasTable('matches')) {
            Schema::table('matches', function (Blueprint $table) {
                $table->index(['status', 'updated_at'], 'matches_status_updated_at_index');
                $table->index(['status', 'completed_at'], 'matches_status_completed_at_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('matches')) {
            Schema::table('matches', function (Blueprint $table) {
                $table->dropIndex('matches_status_updated_at_index');
                $table->dropIndex('matches_status_completed_at_index');
            });
        }

        if (Schema::hasTable('party_members')) {
            Schema::table('party_members', function (Blueprint $table) {
                $table->dropIndex('party_members_player_id_index');
            });
        }

        if (Schema::hasTable('queues')) {
            Schema::table('queues', function (Blueprint $table) {
                $table->dropIndex('queues_player_id_status_index');
                $table->dropIndex('queues_match_id_status_index');
            });
        }
    }
};
