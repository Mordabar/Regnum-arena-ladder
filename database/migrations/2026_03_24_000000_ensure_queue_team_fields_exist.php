<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('queues')) {
            return;
        }

        Schema::table('queues', function (Blueprint $table) {
            if (!Schema::hasColumn('queues', 'team_id')) {
                $table->string('team_id')->nullable()->after('status');
            }

            if (!Schema::hasColumn('queues', 'match_id')) {
                $table->string('match_id')->nullable()->after('team_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('queues')) {
            return;
        }

        Schema::table('queues', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('queues', 'team_id')) {
                $columns[] = 'team_id';
            }

            if (Schema::hasColumn('queues', 'match_id')) {
                $columns[] = 'match_id';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
