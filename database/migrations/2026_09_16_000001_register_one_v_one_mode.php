<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deja escrito el interruptor del duelo 1v1, apagado.
 *
 * ArenaMode::isEnabled() ya responde `false` para una clave que no existe, asi
 * que el duelo estaria apagado igual sin esta migracion. Se siembra de todos
 * modos por dos motivos:
 *
 * - La pantalla de ajustes lista lo que hay en `app_settings`, y un interruptor
 *   que solo aparece despues de guardarlo una vez desconcierta al moderador.
 * - Deja un unico sitio donde se decide el estado inicial de la modalidad. Sin
 *   la fila, encenderla y apagarla depende del default escrito en el codigo, y
 *   cambiar ese default un dia encenderia el duelo solo en cada servidor que no
 *   lo hubiera tocado nunca.
 *
 * Nunca pisa un valor existente: si un admin ya la encendio, se queda encendida
 * aunque esta migracion vuelva a correr.
 */
return new class extends Migration
{
    private const KEY = 'mode_1v1_enabled';

    public function up(): void
    {
        if (!Schema::hasTable('app_settings')) {
            return;
        }

        if (DB::table('app_settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('app_settings')->insert([
            'key' => self::KEY,
            'group' => 'modes',
            'value' => '0',
            'type' => 'boolean',
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_settings')) {
            return;
        }

        // Solo se retira la fila si sigue apagada. Si alguien abrio el duelo,
        // borrarla lo cerraria de golpe con partidas en curso.
        DB::table('app_settings')
            ->where('key', self::KEY)
            ->where('value', '0')
            ->delete();
    }
};
