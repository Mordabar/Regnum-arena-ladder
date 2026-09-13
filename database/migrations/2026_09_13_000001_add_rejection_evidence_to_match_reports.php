<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capturas del rechazo.
 *
 * Hasta ahora quien rechazaba un reporte solo podia escribir un motivo, y
 * moderacion tenia que decidir entre las capturas de uno y la palabra del otro.
 * Con esto el rechazo tambien puede traer pruebas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('match_reports') && !Schema::hasColumn('match_reports', 'rejection_evidence_paths')) {
            Schema::table('match_reports', function (Blueprint $table) {
                $table->json('rejection_evidence_paths')->nullable()->after('rejection_note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('match_reports') && Schema::hasColumn('match_reports', 'rejection_evidence_paths')) {
            Schema::table('match_reports', function (Blueprint $table) {
                $table->dropColumn('rejection_evidence_paths');
            });
        }
    }
};
