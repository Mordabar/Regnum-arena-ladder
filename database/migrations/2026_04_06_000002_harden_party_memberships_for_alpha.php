<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('party_members') || !Schema::hasTable('parties')) {
            return;
        }

        DB::transaction(function () {
            $duplicatePlayerIds = DB::table('party_members')
                ->select('player_id')
                ->groupBy('player_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('player_id');

            foreach ($duplicatePlayerIds as $playerId) {
                $memberships = DB::table('party_members')
                    ->where('player_id', $playerId)
                    ->orderByDesc('id')
                    ->get(['id', 'party_id']);

                $membershipToKeep = $memberships->shift();

                if (!$membershipToKeep) {
                    continue;
                }

                $partyIdsToDissolve = $memberships
                    ->pluck('party_id')
                    ->filter()
                    ->unique()
                    ->values();

                if ($partyIdsToDissolve->isNotEmpty()) {
                    DB::table('parties')
                        ->whereIn('id', $partyIdsToDissolve)
                        ->update([
                            'status' => 'dissolved',
                            'updated_at' => now(),
                        ]);

                    DB::table('party_members')
                        ->whereIn('party_id', $partyIdsToDissolve)
                        ->delete();
                }
            }
        });

        Schema::table('party_members', function (Blueprint $table) {
            $table->unique('player_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('party_members')) {
            return;
        }

        Schema::table('party_members', function (Blueprint $table) {
            $table->dropUnique('party_members_player_id_unique');
        });
    }
};

