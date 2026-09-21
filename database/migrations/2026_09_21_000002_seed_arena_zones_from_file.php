<?php

use App\Models\ArenaMatch;
use App\Models\ArenaZone;
use App\Services\ArenaZoneService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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

        $delFichero = $this->loQueHubiera();

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

        // Cualquier visita anterior a esta linea vio el mapa vacio. Sin este
        // olvido, el sello de "sin zonas" se quedaria guardado y la URL del
        // script no cambiaria al sembrar la tabla.
        app(ArenaZoneService::class)->olvidar();
    }

    public function down(): void
    {
        if (Schema::hasTable('arena_zones')) {
            ArenaZone::query()->delete();
        }
    }

    /**
     * Lo ultimo que el admin llego a publicar, de donde se pueda leer.
     *
     * Primero los respaldos de storage/, y solo despues el fichero de public/.
     * El orden importa y no es un detalle:
     *
     * `public/js/arena-zones.js` esta versionado en git. El despliegue que
     * trae esta migracion es el mismo que hace `git pull`, asi que para cuando
     * la migracion lo lee ya no tiene los contornos del admin: tiene los del
     * repositorio. La migracion es de una sola oportunidad, asi que ese error
     * no se puede deshacer despues.
     *
     * Los respaldos, en cambio, los escribia el propio panel en storage/ cada
     * vez que se publicaba, y storage/ NO esta en git: sobrevive al pull.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loQueHubiera(): array
    {
        $delRespaldo = $this->leerElRespaldoMasNuevo();

        return $delRespaldo !== [] ? $delRespaldo : $this->leerElFicheroViejo();
    }

    /**
     * El respaldo mas reciente que dejo el panel al publicar.
     *
     * @return array<string, array<string, mixed>>
     */
    private function leerElRespaldoMasNuevo(): array
    {
        try {
            $respaldos = collect(Storage::disk('local')->files())
                ->filter(fn (string $fichero) => str_starts_with(basename($fichero), 'arena-zones-backup-'))
                // El nombre lleva la fecha en formato Ymd-His, asi que ordenar
                // por nombre es ordenar por fecha.
                ->sort()
                ->values();

            if ($respaldos->isEmpty()) {
                return [];
            }

            return $this->porClave(json_decode((string) Storage::disk('local')->get($respaldos->last()), true));
        } catch (\Throwable $e) {
            // Un respaldo ilegible no puede impedir el despliegue: se cae al
            // fichero de siempre.
            return [];
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

        return $this->porClave(json_decode(substr($contenido, $inicio, $fin - $inicio + 1), true));
    }

    /**
     * Indexa por clave de zona y descarta lo que no se entienda.
     *
     * @return array<string, array<string, mixed>>
     */
    private function porClave(mixed $datos): array
    {
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
