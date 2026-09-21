<?php

use App\Models\ArenaMatch;
use App\Models\ArenaZone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Trae a la tabla lo que hubiera en `public/js/arena-zones.js`.
 *
 * Esto se ejecuta una vez, al desplegar. En produccion ese fichero tiene los
 * contornos y los puntos que el admin haya ido colocando, y perderlos seria
 * empezar el mapa de cero: se leen de ahi. En un entorno limpio el fichero trae
 * los contornos de partida, que tambien valen.
 *
 * Si el fichero no se puede leer o no se entiende, se crean las catorce zonas
 * sin contorno. Vacias no rompen nada -el mapa no dibuja lo que no tiene
 * vertices- y el admin puede trazarlas desde el panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('arena_zones')) {
            return;
        }

        if (ArenaZone::query()->exists()) {
            return;
        }

        $delFichero = $this->leerElFicheroViejo();

        foreach (ArenaMatch::ZONES as $key => $meta) {
            $fila = $delFichero[$key] ?? [];

            ArenaZone::query()->create([
                'key' => $key,
                'number' => $meta['number'],
                'name' => 'Zona ' . $meta['number'] . ' - ' . $meta['name'],
                'coords' => $fila['coords'] ?? null,
                'meeting' => $fila['meeting'] ?? null,
                'meeting_b' => $fila['meeting_b'] ?? null,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('arena_zones')) {
            ArenaZone::query()->delete();
        }
    }

    /**
     * Saca el JSON de dentro del JavaScript.
     *
     * El fichero puede venir de dos formas segun quien lo escribiera: el
     * original del repositorio empieza con `const PREDEFINED_ZONES_JSON = [` y
     * el que reescribe el panel con `window.ARENA_ZONES_CONFIG = [`. Se busca
     * el primer corchete y el ultimo, que es lo unico comun a las dos.
     *
     * @return array<string, array<string, mixed>>
     */
    private function leerElFicheroViejo(): array
    {
        $ruta = public_path('js/arena-zones.js');

        if (!is_file($ruta) || !is_readable($ruta)) {
            return [];
        }

        $contenido = (string) file_get_contents($ruta);
        $inicio = strpos($contenido, '[');
        $fin = strrpos($contenido, ']');

        if ($inicio === false || $fin === false || $fin <= $inicio) {
            return [];
        }

        $datos = json_decode(substr($contenido, $inicio, $fin - $inicio + 1), true);

        if (!is_array($datos)) {
            return [];
        }

        $porClave = [];

        foreach ($datos as $zona) {
            $key = ArenaMatch::normalizeZoneKey($zona['key'] ?? null);

            if ($key === null) {
                continue;
            }

            $porClave[$key] = [
                'coords' => is_array($zona['coords'] ?? null) && count($zona['coords']) >= 3
                    ? $zona['coords']
                    : null,
                'meeting' => ArenaZone::esUnPunto($zona['meeting'] ?? null) ? $zona['meeting'] : null,
                'meeting_b' => ArenaZone::esUnPunto($zona['meeting_b'] ?? null) ? $zona['meeting_b'] : null,
            ];
        }

        return $porClave;
    }
};
