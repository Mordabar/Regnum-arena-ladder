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
                if (!Schema::hasColumn('queues', 'party_signature')) {
                    $table->string('party_signature', 120)->nullable()->after('premade_leader_discord_id');
                    $table->index('party_signature');
                }
            });
        }

        if (Schema::hasTable('matches')) {
            Schema::table('matches', function (Blueprint $table) {
                if (!Schema::hasColumn('matches', 'team_a_queue_type')) {
                    $table->string('team_a_queue_type', 20)->nullable()->after('queue_mode');
                }

                if (!Schema::hasColumn('matches', 'team_b_queue_type')) {
                    $table->string('team_b_queue_type', 20)->nullable()->after('team_a_queue_type');
                }

                if (!Schema::hasColumn('matches', 'team_a_party_signature')) {
                    $table->string('team_a_party_signature', 120)->nullable()->after('team_b');
                    $table->index('team_a_party_signature');
                }

                if (!Schema::hasColumn('matches', 'team_b_party_signature')) {
                    $table->string('team_b_party_signature', 120)->nullable()->after('team_a_party_signature');
                    $table->index('team_b_party_signature');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('matches')) {
            Schema::table('matches', function (Blueprint $table) {
                foreach (['team_a_queue_type', 'team_b_queue_type', 'team_a_party_signature', 'team_b_party_signature'] as $column) {
                    if (Schema::hasColumn('matches', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('queues')) {
            Schema::table('queues', function (Blueprint $table) {
                if (Schema::hasColumn('queues', 'party_signature')) {
                    $table->dropColumn('party_signature');
                }
            });
        }
    }
};
