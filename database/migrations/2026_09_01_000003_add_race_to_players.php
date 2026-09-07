<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La raza del personaje.
 *
 * No da ninguna ventaja en el ladder: es el eje que decide como se ve el
 * guerrero (un enano no se parece a un molok) y hasta ahora no existia, asi
 * que todos los personajes de un mismo reino y subclase eran identicos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            if (!Schema::hasColumn('players', 'race')) {
                $table->string('race', 32)->nullable()->after('realm');
                $table->index(['realm', 'race']);
            }
        });

        if (!Schema::hasColumn('players', 'race')) {
            return;
        }

        // A quien ya existe se le asigna la variante humana de su reino, que es
        // la opcion neutra: cambia el modelo lo minimo respecto a lo que ya
        // veia, y cada jugador puede pedir otra cosa si quiere.
        foreach (\App\Models\Player::RACES as $realm => $races) {
            DB::table('players')
                ->where('realm', $realm)
                ->whereNull('race')
                ->update(['race' => array_key_first($races)]);
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            if (Schema::hasColumn('players', 'race')) {
                $table->dropIndex(['realm', 'race']);
                $table->dropColumn('race');
            }
        });
    }
};
