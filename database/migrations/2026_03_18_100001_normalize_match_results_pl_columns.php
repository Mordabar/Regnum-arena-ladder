<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('match_results')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE match_results MODIFY pl_change DECIMAL(8,1) NOT NULL');
        DB::statement('ALTER TABLE match_results MODIFY pl_before DECIMAL(8,1) NOT NULL');
        DB::statement('ALTER TABLE match_results MODIFY pl_after DECIMAL(8,1) NOT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('match_results')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE match_results MODIFY pl_change INT NOT NULL');
        DB::statement('ALTER TABLE match_results MODIFY pl_before INT NOT NULL');
        DB::statement('ALTER TABLE match_results MODIFY pl_after INT NOT NULL');
    }
};
