<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('matches')) {
            return;
        }

        if (!Schema::hasColumn('matches', 'created_at')) {
            Schema::table('matches', function (Blueprint $table) {
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasColumn('matches', 'updated_at')) {
            Schema::table('matches', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Compatibility migration; keep timestamps in place.
    }
};
