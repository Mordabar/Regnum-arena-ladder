<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La columna `status` de produccion no admite todos los estados.
 *
 * La tabla que corre en produccion viene de antes y su `status` es un ENUM que
 * no incluye 'disputed'. MySQL no avisa al leer: avisa al escribir, con
 * "Data truncated for column 'status'", y tumba la operacion entera. En la
 * practica eso dejaba el rechazo de un reporte sin efecto -el jugador pulsaba y
 * no pasaba nada- y se habria llevado por delante cualquier otra cosa que
 * mandara un enfrentamiento a disputa o lo anulara.
 *
 * Las migraciones del repositorio ya declaran la columna como string(32); esto
 * es para las bases que se crearon con el ENUM viejo. Se ensancha a VARCHAR,
 * que es lo que el resto del esquema espera, y la validacion la sigue haciendo
 * la aplicacion, que es donde vive la lista de estados.
 *
 * Se revisa tambien `match_reports.status`, que tiene el mismo riesgo: la
 * sentencia que fallo en produccion fue la de `matches` solo porque va antes
 * en la transaccion.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Solo MySQL tiene ENUM de verdad. En SQLite la columna ya es texto.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->ensanchar('matches', array_keys(ArenaMatch::STATUSES), 'pending_acceptance');
        $this->ensanchar('match_reports', array_keys(MatchReport::STATUSES), 'pending_confirmation');
    }

    public function down(): void
    {
        // Sin vuelta atras a proposito: estrechar la columna volveria a
        // romper los enfrentamientos que ya esten en disputa o anulados.
    }

    /**
     * @param  array<int, string>  $estados
     */
    private function ensanchar(string $tabla, array $estados, string $porDefecto): void
    {
        if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, 'status')) {
            return;
        }

        $columna = collect(Schema::getColumns($tabla))->firstWhere('name', 'status');
        $tipo = strtolower((string) ($columna['type'] ?? ''));

        if (!str_starts_with($tipo, 'enum(')) {
            return;
        }

        preg_match_all("/'([^']*)'/", $tipo, $coincidencias);

        if (array_diff($estados, $coincidencias[1] ?? []) === []) {
            return;
        }

        DB::statement("ALTER TABLE `{$tabla}` MODIFY `status` VARCHAR(32) NOT NULL DEFAULT '{$porDefecto}'");
    }
};
