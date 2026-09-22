<?php

use App\Models\ArenaMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Que lo que el codigo genera quepa en la columna donde se guarda.
 *
 * Existe por un fallo real: un token de 32 caracteres en un `varchar(24)`.
 * SQLite -donde corre la suite, porque es rapido- no comprueba longitudes y lo
 * dejo pasar; MySQL, que es lo que hay en produccion, lo rechaza con un 1406 y
 * tumba la peticion.
 *
 * Y SQLite tampoco guarda la longitud en su esquema: declara `varchar` a
 * secas. Asi que esto SOLO puede comprobarse contra MySQL, y por eso el test
 * se salta -con su motivo escrito- en vez de pasar en verde sin haber mirado
 * nada. Para que corra: `composer test:mysql`.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped(
            'SQLite no guarda las longitudes de columna: declara "varchar" sin numero. '
            . 'Esta comprobacion solo vale contra MySQL, que es lo que corre en produccion. '
            . 'Ejecuta: composer test:mysql'
        );
    }
});

it('los codigos que genera el modelo caben en sus columnas', function () {
    $generados = [
        'match_code' => ArenaMatch::generateMatchCode(),
        'report_token' => ArenaMatch::generateReportToken(),
    ];

    foreach ($generados as $columna => $valor) {
        $limite = limiteDeColumna('matches', $columna);

        expect($limite)->not->toBeNull("No se pudo leer el limite de matches.{$columna}");

        expect(strlen($valor))->toBeLessThanOrEqual(
            $limite,
            "matches.{$columna} admite {$limite} caracteres y el codigo genera "
            . strlen($valor) . ": '{$valor}'"
        );
    }
});

it('meter un valor mas largo de la cuenta falla de verdad', function () {
    // El centinela del centinela: comprueba que esta base SI rechaza lo que
    // SQLite dejaba pasar. Si algun dia deja de rechazarlo -modo permisivo,
    // otro motor-, el test lo dice en vez de dar por buena una red que ya no
    // esta puesta.
    $limite = limiteDeColumna('matches', 'report_token');

    expect($limite)->toBeInt()->toBeGreaterThan(0);

    expect(fn () => DB::table('matches')->insert([
        'match_code' => 'ARENA-0001',
        'report_token' => str_repeat('X', $limite + 8),
        'arena_mode' => '1v1',
        'status' => 'completed',
        'zone' => 'central_ruins',
        'team_a' => '[]',
        'team_b' => '[]',
        'team_a_realm' => 'ignis',
        'team_b_realm' => 'alsius',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

/** El maximo de caracteres de una columna, leido de information_schema. */
function limiteDeColumna(string $tabla, string $columna): ?int
{
    if (!Schema::hasColumn($tabla, $columna)) {
        return null;
    }

    $fila = DB::selectOne(
        'select character_maximum_length as largo
           from information_schema.columns
          where table_schema = database() and table_name = ? and column_name = ?',
        [$tabla, $columna]
    );

    return $fila === null || $fila->largo === null ? null : (int) $fila->largo;
}
