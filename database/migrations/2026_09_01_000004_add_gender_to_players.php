<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sexo del personaje.
 *
 * Igual que la raza, solo cambia como se ve el guerrero: no da ventajas, no
 * entra en el emparejamiento y no aparece en el ranking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            if (!Schema::hasColumn('players', 'gender')) {
                $table->string('gender', 12)->nullable()->after('race');
            }
        });

        if (Schema::hasColumn('players', 'gender')) {
            DB::table('players')->whereNull('gender')->update(['gender' => 'male']);
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            if (Schema::hasColumn('players', 'gender')) {
                $table->dropColumn('gender');
            }
        });
    }
};
