<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('match_reports') && !Schema::hasColumn('match_reports', 'evidence_paths')) {
            Schema::table('match_reports', function (Blueprint $table) {
                $table->json('evidence_paths')->nullable()->after('final_screenshot_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('match_reports') && Schema::hasColumn('match_reports', 'evidence_paths')) {
            Schema::table('match_reports', function (Blueprint $table) {
                $table->dropColumn('evidence_paths');
            });
        }
    }
};
