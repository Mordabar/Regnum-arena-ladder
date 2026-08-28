<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('matches')) {
            Schema::create('matches', function (Blueprint $table) {
                $table->id();
                $table->string('match_code', 20)->nullable();
                $table->string('report_token', 24)->nullable();
                $table->string('queue_mode', 20)->default('random');
                $table->string('team_a_realm', 20)->nullable();
                $table->string('team_b_realm', 20)->nullable();
                $table->json('team_a')->nullable();
                $table->json('team_b')->nullable();
                $table->string('zone', 50)->nullable();
                $table->string('status', 32)->default('pending_acceptance');
                $table->string('winner_team', 20)->nullable();
                $table->string('winner_realm', 20)->nullable();
                $table->integer('estimated_mmr_avg')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('reported_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });

            return;
        }

        $this->addColumnIfMissing('matches', 'match_code', function (Blueprint $table) {
            $table->string('match_code', 20)->nullable();
        });

        $this->addColumnIfMissing('matches', 'report_token', function (Blueprint $table) {
            $table->string('report_token', 24)->nullable();
        });

        $this->addColumnIfMissing('matches', 'queue_mode', function (Blueprint $table) {
            $table->string('queue_mode', 20)->default('random');
        });

        $this->addColumnIfMissing('matches', 'team_a_realm', function (Blueprint $table) {
            $table->string('team_a_realm', 20)->nullable();
        });

        $this->addColumnIfMissing('matches', 'team_b_realm', function (Blueprint $table) {
            $table->string('team_b_realm', 20)->nullable();
        });

        $this->addColumnIfMissing('matches', 'team_a', function (Blueprint $table) {
            $table->json('team_a')->nullable();
        });

        $this->addColumnIfMissing('matches', 'team_b', function (Blueprint $table) {
            $table->json('team_b')->nullable();
        });

        $this->addColumnIfMissing('matches', 'zone', function (Blueprint $table) {
            $table->string('zone', 50)->nullable();
        });

        $this->addColumnIfMissing('matches', 'status', function (Blueprint $table) {
            $table->string('status', 32)->default('pending_acceptance');
        });

        $this->addColumnIfMissing('matches', 'winner_team', function (Blueprint $table) {
            $table->string('winner_team', 20)->nullable();
        });

        $this->addColumnIfMissing('matches', 'winner_realm', function (Blueprint $table) {
            $table->string('winner_realm', 20)->nullable();
        });

        $this->addColumnIfMissing('matches', 'estimated_mmr_avg', function (Blueprint $table) {
            $table->integer('estimated_mmr_avg')->nullable();
        });

        $this->addColumnIfMissing('matches', 'accepted_at', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable();
        });

        $this->addColumnIfMissing('matches', 'started_at', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable();
        });

        $this->addColumnIfMissing('matches', 'completed_at', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable();
        });

        $this->addColumnIfMissing('matches', 'reported_at', function (Blueprint $table) {
            $table->timestamp('reported_at')->nullable();
        });

        $this->addColumnIfMissing('matches', 'expires_at', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
        });

        $this->addColumnIfMissing('matches', 'notes', function (Blueprint $table) {
            $table->text('notes')->nullable();
        });

        $this->addColumnIfMissing('matches', 'created_at', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable();
        });

        $this->addColumnIfMissing('matches', 'updated_at', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        // No-op compatibility migration.
    }

    private function addColumnIfMissing(string $tableName, string $columnName, \Closure $callback): void
    {
        if (Schema::hasColumn($tableName, $columnName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($callback) {
            $callback($table);
        });
    }
};
