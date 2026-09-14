<?php

namespace App\Console\Commands;

use App\Models\ArenaMatch;
use App\Services\ArenaMatchmakingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Dice por que el ladder se cierra, en vez de dejarlo adivinar.
 *
 * Cuando a la tabla `matches` le falta algo, la aplicacion cierra la cola y
 * devuelve al lobby a quien intente abrir un enfrentamiento. Es deliberado
 * -mejor eso que reventar con errores de SQL delante de un jugador-, pero
 * desde fuera se ve como si la pagina se fuera sola y no dice que falta.
 *
 * Esto lo dice: recorre las mismas comprobaciones que isMatchesSchemaReady()
 * y nombra la que no pasa.
 */
class LadderDiagnosticoCommand extends Command
{
    protected $signature = 'ladder:diagnostico';

    protected $description = 'Revisa si la tabla matches tiene lo que el ladder necesita y nombra lo que falte.';

    /** Las mismas que exige ArenaMatchmakingService::isMatchesSchemaReady(). */
    private const COLUMNAS = [
        'match_code', 'report_token', 'queue_mode', 'arena_mode',
        'team_a_realm', 'team_b_realm', 'team_a', 'team_b',
        'zone', 'status', 'winner_team', 'winner_realm',
        'estimated_mmr_avg', 'accepted_at', 'started_at', 'completed_at',
        'reported_at', 'expires_at', 'notes', 'created_at', 'updated_at',
    ];

    public function handle(ArenaMatchmakingService $matchmaking): int
    {
        $this->line('Conexion: <info>' . config('database.default') . '</info>');

        if (!Schema::hasTable('matches')) {
            $this->error('No existe la tabla `matches`. Falta correr php artisan migrate --force.');

            return self::FAILURE;
        }

        $columnas = collect(Schema::getColumns('matches'))->keyBy('name');
        $faltan = array_values(array_filter(
            self::COLUMNAS,
            fn (string $c) => !$columnas->has($c)
        ));

        if ($faltan !== []) {
            $this->error('Faltan ' . count($faltan) . ' columnas en `matches`:');
            foreach ($faltan as $c) {
                $this->line('  - ' . $c);
            }
            $this->newLine();
            $this->warn('Con esto la cola queda cerrada y ver un enfrentamiento devuelve al lobby.');
            $this->line('Solucion: php artisan migrate --force');

            return self::FAILURE;
        }

        $this->info('Las ' . count(self::COLUMNAS) . ' columnas necesarias estan.');

        // Un ENUM heredado acepta menos valores de los que la aplicacion
        // escribe. No se nota al leer: revienta al guardar.
        $problemas = 0;
        foreach ([
            'status' => array_keys(ArenaMatch::STATUSES),
            'zone' => array_keys(ArenaMatch::ZONES),
        ] as $columna => $esperados) {
            $tipo = strtolower((string) ($columnas->get($columna)['type'] ?? ''));

            if (!str_starts_with($tipo, 'enum(')) {
                $this->line("  <info>ok</info>  `$columna` es " . ($tipo ?: '?') . ', acepta cualquier valor.');
                continue;
            }

            preg_match_all("/'([^']*)'/", $tipo, $m);
            $ausentes = array_diff($esperados, $m[1] ?? []);

            if ($ausentes === []) {
                $this->line("  <info>ok</info>  `$columna` es un ENUM pero los lista todos.");
                continue;
            }

            $problemas++;
            $this->error("  `$columna` es un ENUM al que le faltan: " . implode(', ', $ausentes));
        }

        if ($problemas > 0) {
            $this->newLine();
            $this->warn('Guardar uno de esos valores da "Data truncated for column" y tumba la operacion.');
            $this->line('Solucion: php artisan migrate --force');

            return self::FAILURE;
        }

        $this->newLine();
        $listo = $matchmaking->isMatchesSchemaReady();
        $this->line('isMatchesSchemaReady(): ' . ($listo ? '<info>true</info>' : '<error>false</error>'));

        if (!$listo) {
            $this->warn('Da false aunque las columnas esten: revisa el tipo de `zone`.');

            return self::FAILURE;
        }

        $this->info('El esquema esta listo. Si aun te devuelve al lobby, el motivo es otro.');

        return self::SUCCESS;
    }
}
