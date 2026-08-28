<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_admin')->default(false)->after('email');
            });
        }

        if (Schema::hasTable('players') && !Schema::hasColumn('players', 'queue_locked_until')) {
            Schema::table('players', function (Blueprint $table) {
                $table->timestamp('queue_locked_until')->nullable()->after('trust_score');
            });
        }

        if (Schema::hasTable('match_results') && !Schema::hasColumn('match_results', 'scoring_context')) {
            Schema::table('match_results', function (Blueprint $table) {
                $table->json('scoring_context')->nullable()->after('reported_by_admin');
            });
        }

        if (!Schema::hasTable('match_reports')) {
            Schema::create('match_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
                $table->foreignId('reported_by_player_id')->nullable()->constrained('players')->nullOnDelete();
                $table->string('reporting_team', 20);
                $table->string('claimed_winner_team', 20);
                $table->string('claimed_winner_realm', 20)->nullable();
                $table->string('status', 32)->default('pending_confirmation');
                $table->string('encounter_screenshot_path');
                $table->string('final_screenshot_path');
                $table->text('reporter_note')->nullable();
                $table->foreignId('confirmed_by_player_id')->nullable()->constrained('players')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->foreignId('rejected_by_player_id')->nullable()->constrained('players')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_note')->nullable();
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('admin_note')->nullable();
                $table->json('resolution_payload')->nullable();
                $table->timestamps();

                $table->unique('match_id');
                $table->index(['status', 'claimed_winner_team']);
            });
        }

        if (!Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->id();
                $table->string('group', 50)->default('general');
                $table->string('key')->unique();
                $table->longText('value')->nullable();
                $table->string('type', 20)->default('string');
                $table->boolean('is_public')->default(false);
                $table->timestamps();

                $table->index(['group', 'is_public']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('app_settings')) {
            Schema::dropIfExists('app_settings');
        }

        if (Schema::hasTable('match_reports')) {
            Schema::dropIfExists('match_reports');
        }

        if (Schema::hasTable('match_results') && Schema::hasColumn('match_results', 'scoring_context')) {
            Schema::table('match_results', function (Blueprint $table) {
                $table->dropColumn('scoring_context');
            });
        }

        if (Schema::hasTable('players') && Schema::hasColumn('players', 'queue_locked_until')) {
            Schema::table('players', function (Blueprint $table) {
                $table->dropColumn('queue_locked_until');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_admin');
            });
        }
    }
};
