<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En SQLite el empate no se podia guardar.
 *
 * La migracion que añadio 'draw' se salta SQLite con este comentario: "en
 * sqlite la columna es texto libre, asi que ya acepta 'draw' sin tocar nada".
 * No es cierto. `$table->enum(...)` en SQLite no crea un tipo ENUM, pero si
 * genera un CHECK con la lista de valores, y ese CHECK se quedo con los tres
 * de siempre.
 *
 * Consecuencia: en MySQL -produccion- el empate funciona, y en SQLite -local y
 * banco de pruebas- reventaba al confirmar el reporte con "CHECK constraint
 * failed". La partida se quedaba muerta: nadie podia confirmar ese reporte y
 * el combate se auto-anulaba al vencer. Por eso no habia ni un test de empate:
 * no se podia escribir uno que pasara.
 *
 * SQLite no sabe modificar un CHECK, asi que se recrea la columna sin el. La
 * validacion la hace la aplicacion, que es donde vive la lista de resultados.
 */
return new class extends Migration
{
    /** Columnas con un CHECK heredado que no conoce 'draw'. */
    private const COLUMNAS = [
        ['match_results', 'result', "varchar not null"],
        ['matches', 'winner_team', 'varchar null'],
        ['match_reports', 'claimed_winner_team', "varchar not null default 'team_a'"],
    ];

    public function up(): void
    {
        // MySQL ya lo arreglo la migracion de abril con sus ALTER ... MODIFY.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::COLUMNAS as [$tabla, $columna, $definicion]) {
            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, $columna)) {
                continue;
            }

            if (!$this->tieneCheckSinDraw($tabla, $columna)) {
                continue;
            }

            $this->recrearSinCheck($tabla, $columna, $definicion);
        }
    }

    public function down(): void
    {
        // Sin vuelta atras: devolver el CHECK volveria a impedir los empates
        // que se hayan guardado mientras tanto.
    }

    /** La definicion entera de la columna, con su CHECK y lo que venga detras. */
    private function patron(string $columna): string
    {
        $c = preg_quote($columna, '/');

        return '/"' . $c . '"\s+\w+\s+check\s*\(\s*"' . $c . '"\s+in\s*\([^)]*\)\s*\)(\s+not\s+null)?(\s+default\s+\S+)?/i';
    }

    private function tieneCheckSinDraw(string $tabla, string $columna): bool
    {
        $sql = (string) (DB::selectOne(
            'SELECT sql FROM sqlite_master WHERE type = ? AND name = ?',
            ['table', $tabla]
        )->sql ?? '');

        // El CHECK lleva parentesis anidados -check ("col" in ('a','b'))-, asi
        // que hay que cerrar los dos, no el primero que aparezca.
        if (!preg_match($this->patron($columna), $sql, $m)) {
            return false;
        }

        return !str_contains(strtolower($m[0]), "'draw'");
    }

    /**
     * Copiar la tabla sin el CHECK de esa columna.
     *
     * Es el baile que exige SQLite para cambiar una restriccion: tabla nueva,
     * datos dentro, fuera la vieja, renombrar. Se hace con las claves foraneas
     * apagadas para que el borrado intermedio no arrastre nada.
     */
    private function recrearSinCheck(string $tabla, string $columna, string $definicion): void
    {
        $creacion = (string) DB::selectOne(
            'SELECT sql FROM sqlite_master WHERE type = ? AND name = ?',
            ['table', $tabla]
        )->sql;

        $nueva = preg_replace(
            $this->patron($columna),
            '"' . $columna . '" ' . $definicion,
            $creacion,
            1
        );

        if ($nueva === null || $nueva === $creacion) {
            return;
        }

        $temporal = $tabla . '_sin_check';
        $nueva = preg_replace('/create table "' . preg_quote($tabla, '/') . '"/i',
            'create table "' . $temporal . '"', $nueva, 1);

        $columnas = collect(Schema::getColumns($tabla))
            ->pluck('name')
            ->map(fn (string $c) => '"' . $c . '"')
            ->implode(', ');

        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::statement($nueva);
            DB::statement("INSERT INTO \"{$temporal}\" ({$columnas}) SELECT {$columnas} FROM \"{$tabla}\"");
            DB::statement("DROP TABLE \"{$tabla}\"");
            DB::statement("ALTER TABLE \"{$temporal}\" RENAME TO \"{$tabla}\"");
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
