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

                // Los mismos indices que crea 2026_07_06_000001_add_arena_modes.
                // Solo se agregan cuando esta migracion creo la columna, para no
                // chocar con los que aquella ya dejo puestos.
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    match ($table) {
                        'queues' => $blueprint->index(['arena_mode', 'status', 'queue_type'], 'queues_mode_status_type_index'),
                        'matches' => $blueprint->index(['arena_mode', 'status', 'completed_at'], 'matches_mode_status_completed_index'),
                        'parties' => $blueprint->index(['arena_mode', 'status'], 'parties_mode_status_index'),
                        default => null,
                    };
                });
            }

            // Filas previas al soporte multimodal: todo lo jugado hasta ahora fue
            // 2v2. Se cubre tambien la cadena vacia, que no la atrapa whereNull.
            DB::table($table)
                ->where(function ($query) {
                    $query->whereNull('arena_mode')->orWhere('arena_mode', '');
                })
                ->update(['arena_mode' => '2v2']);
        }

        if (!Schema::hasTable('app_settings')) {
            return;
        }

        // Se FUERZA el valor (no basta con insertar si falta): la migracion
        // 2026_07_06_000001 pudo dejar mode_3v3_enabled='1' y, si la siguiente
        // no llego a correr, un simple "insertar si no existe" encenderia 3v3
        // sola en el deploy.
        foreach ($this->resolveInitialModeStates() as $mode => $enabled) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => 'mode_' . $mode . '_enabled'],
                [
                    'group' => 'modes',
                    'value' => $enabled ? '1' : '0',
                    'type' => 'boolean',
                    'is_public' => true,
                    'updated_at' => now(),
                ]
            );
        }

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
        // A proposito no se borran mode_*_enabled ni la columna arena_mode:
        // ambas son propiedad de 2026_07_06_000001_add_arena_modes. Borrarlas
        // aqui dejaria al sistema peor que antes de esta migracion (sin las
        // claves que aquella creo, y perdiendo la modalidad de partidas ya
        // jugadas). Esta migracion solo normaliza valores, y eso no se revierte.
    }

    /**
     * Estado inicial de cada modalidad.
     *
     * Hasta ahora quien decidia esto era la temporada activa (arena_seasons.
     * enabled_modes), asi que si existe se respeta lo que estaba corriendo en
     * produccion. Sin temporada, queda 2v2 encendido y 3v3 apagado: estrenar
     * una modalidad tiene que ser una decision explicita del admin, no un
     * efecto secundario del deploy.
     *
     * @return array<string, bool>
     */
    private function resolveInitialModeStates(): array
    {
        $default = ['2v2' => true, '3v3' => false];

        if (!Schema::hasTable('arena_seasons')) {
            return $default;
        }

        $activeModes = DB::table('arena_seasons')
            ->where('status', 'active')
            ->orderByDesc('starts_at')
            ->value('enabled_modes');

        if ($activeModes === null) {
            return $default;
        }

        $decoded = json_decode((string) $activeModes, true);

        if (!is_array($decoded) || $decoded === []) {
            return $default;
        }

        $normalized = array_map(
            static fn ($mode) => strtolower(trim((string) $mode)),
            $decoded
        );

        $states = [
            '2v2' => in_array('2v2', $normalized, true),
            '3v3' => in_array('3v3', $normalized, true),
        ];

        // Si la temporada no nombra ninguna modalidad conocida, no dejamos el
        // sitio sin colas.
        return ($states['2v2'] || $states['3v3']) ? $states : $default;
    }

};
