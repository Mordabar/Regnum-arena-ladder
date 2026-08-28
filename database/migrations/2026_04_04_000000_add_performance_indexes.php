<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            // Index to optimize the query by status and expires_at used heavily by cron
            $table->index(['status', 'expires_at'], 'queues_status_expires_at_index');
        });

        Schema::table('matches', function (Blueprint $table) {
            // Index to optimize finding matches pending expiration or in progress expiration
            $table->index(['status', 'expires_at'], 'matches_status_expires_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex('matches_status_expires_at_index');
        });

        Schema::table('queues', function (Blueprint $table) {
            $table->dropIndex('queues_status_expires_at_index');
        });
    }
};
