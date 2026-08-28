<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('players')) {
            return;
        }

        Schema::table('players', function (Blueprint $table) {
            if (!Schema::hasColumn('players', 'penalty_strikes')) {
                $table->unsignedInteger('penalty_strikes')->default(0)->after('trust_score');
            }

            if (!Schema::hasColumn('players', 'queue_lock_reason')) {
                $table->string('queue_lock_reason', 80)->nullable()->after('queue_locked_until');
            }

            if (!Schema::hasColumn('players', 'last_penalty_type')) {
                $table->string('last_penalty_type', 80)->nullable()->after('queue_lock_reason');
            }

            if (!Schema::hasColumn('players', 'last_penalty_at')) {
                $table->timestamp('last_penalty_at')->nullable()->after('last_penalty_type');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('players')) {
            return;
        }

        Schema::table('players', function (Blueprint $table) {
            foreach (['penalty_strikes', 'queue_lock_reason', 'last_penalty_type', 'last_penalty_at'] as $column) {
                if (Schema::hasColumn('players', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
