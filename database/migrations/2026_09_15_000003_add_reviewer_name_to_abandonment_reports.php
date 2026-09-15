<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quien resolvio el aviso, por su nombre del panel.
 *
 * `reviewed_by_user_id` apunta a `users`, que son las cuentas de Discord de los
 * jugadores. Pero moderacion entra al panel con su propia cuenta, de otra
 * tabla, asi que guardar ahi su id hacia que la ficha atribuyera la resolucion
 * a un jugador cualquiera que casualmente tuviera ese numero. Y dejarlo a null
 * -lo que hacian confirmar y descartar- perdia el rastro de quien sanciono.
 *
 * Un nombre suelto basta para lo que hace falta: saber quien decidio.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('match_abandonment_reports')
            || Schema::hasColumn('match_abandonment_reports', 'reviewed_by_admin')) {
            return;
        }

        Schema::table('match_abandonment_reports', function (Blueprint $table) {
            $table->string('reviewed_by_admin', 64)->nullable()->after('reviewed_by_user_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('match_abandonment_reports', 'reviewed_by_admin')) {
            return;
        }

        Schema::table('match_abandonment_reports', function (Blueprint $table) {
            $table->dropColumn('reviewed_by_admin');
        });
    }
};
