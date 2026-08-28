<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('matches') || !Schema::hasColumn('matches', 'zone')) {
            return;
        }

        DB::statement('ALTER TABLE `matches` MODIFY `zone` VARCHAR(50) NULL');

        DB::table('matches')
            ->whereIn('zone', ['ign', 'ignis', 'als', 'alsius', 'syr', 'syrtis'])
            ->update(['zone' => null]);
    }

    public function down(): void
    {
        // Compatibility migration only.
    }
};
