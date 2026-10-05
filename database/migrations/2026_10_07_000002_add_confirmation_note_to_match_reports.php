<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** El comentario opcional de quien confirma un reporte, como el del que reporta. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('match_reports') || Schema::hasColumn('match_reports', 'confirmation_note')) {
            return;
        }

        Schema::table('match_reports', function (Blueprint $table) {
            $table->text('confirmation_note')->nullable()->after('reporter_note');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('match_reports') && Schema::hasColumn('match_reports', 'confirmation_note')) {
            Schema::table('match_reports', fn (Blueprint $table) => $table->dropColumn('confirmation_note'));
        }
    }
};
