<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deja el esquema listo para que 2v2 y 3v3 convivan.
 *
 * Es idempotente a proposito: la columna arena_mode pudo haberse creado ya en
 * 2026_07_06_000001_add_arena_modes, pero ese paso no se puede dar por hecho en
 * todos los entornos. Aqui solo se agrega lo que falte.
 */
return new class extends Migration
{
    /**
     * Tablas que necesitan arena_mode y la columna despues de la cual insertarla.
     */
    private const MODE_COLUMNS = [
        'queues' => 'queue_type',
        'matches' => 'queue_mode',
        'parties' => 'realm',
    ];

    /**
     * Valores de copy generados automaticamente por versiones anteriores. Solo
     * se reemplazan estos: un texto personalizado por el admin se respeta.
     */
    private const REPLACEABLE_TAGLINES = [
        'Conquest PvP 2v2 por reino y subclase',
        'Conquest PvP 3v3 por reino y subclase',
    ];

    private const REPLACEABLE_EXCERPTS = [
        'Random y premade 2v2, anonimato rival y ladder automatico.',
        'Random y premade 3v3, anonimato rival y ladder automatico.',
        'Random y premade 2v2, anonimato rival, reporte con 2 capturas y ladder automatico por PL/MMR.',
        'Random y premade 3v3, anonimato rival, reporte con 2 capturas y ladder automatico por PL/MMR.',
        'Random y premade 2v2, anonimato rival, reporte con 2 capturas y ladder automático por PL/MMR.',
        'Random y premade 3v3, anonimato rival, reporte con 2 capturas y ladder automático por PL/MMR.',
        'Random y premade 2v2, anonimato rival, reporte con 1 a 3 capturas y ladder automatico por PL/MMR.',
        'Random y premade 3v3, anonimato rival, reporte con 1 a 3 capturas y ladder automatico por PL/MMR.',
        'Random y premade con ladder acumulado durante la temporada.',
        'Random y premade, anonimato rival y ladder independiente por modalidad.',
    ];

    public function up(): void
    {
        foreach (self::MODE_COLUMNS as $table => $after) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if (!Schema::hasColumn($table, 'arena_mode')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table, $after) {
                    $column = $blueprint->string('arena_mode', 8)->default('2v2');

                    if (Schema::hasColumn($table, $after)) {
                        $column->after($after);
                    }
                });
            }

            // Filas previas al soporte multimodal: todo lo jugado hasta ahora fue 2v2.
            DB::table($table)->whereNull('arena_mode')->update(['arena_mode' => '2v2']);
        }

        if (!Schema::hasTable('app_settings')) {
            return;
        }

        // 2v2 queda encendido (es lo que corre hoy) y 3v3 apagado: activar una
        // modalidad nueva debe ser una decision explicita del admin, no un
        // efecto secundario del deploy.
        $this->ensureSetting('mode_2v2_enabled', '1');
        $this->ensureSetting('mode_3v3_enabled', '0');

        DB::table('app_settings')
            ->where('key', 'home_tagline')
            ->whereIn('value', self::REPLACEABLE_TAGLINES)
            ->update(['value' => 'Conquest PvP por reino y subclase']);

        DB::table('app_settings')
            ->where('key', 'rules_excerpt')
            ->whereIn('value', self::REPLACEABLE_EXCERPTS)
            ->update(['value' => 'Random y premade, anonimato rival, reporte con capturas y ladder automatico por PL/MMR.']);
    }

    public function down(): void
    {
        if (Schema::hasTable('app_settings')) {
            DB::table('app_settings')
                ->whereIn('key', ['mode_2v2_enabled', 'mode_3v3_enabled'])
                ->delete();
        }

        // arena_mode no se elimina: 2026_07_06_000001_add_arena_modes es su
        // dueño original y revertirla aqui borraria el modo de partidas ya
        // jugadas en 3v3.
    }

    private function ensureSetting(string $key, string $value): void
    {
        $exists = DB::table('app_settings')->where('key', $key)->exists();

        if ($exists) {
            return;
        }

        DB::table('app_settings')->insert([
            'group' => 'modes',
            'key' => $key,
            'value' => $value,
            'type' => 'boolean',
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
