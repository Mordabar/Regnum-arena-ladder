<?php

namespace App\Services\Matches;

use App\Models\MatchAbandonmentReport;
use App\Models\MatchReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Mantenimiento de las capturas que ya estan en el servidor.
 *
 * Las nuevas se guardan normalizadas (EvidenceStorage), pero las que se
 * subieron antes siguen pesando lo que pesaban. Esto las pasa a WebP y quita
 * del disco los ficheros que ya no apunta ningun reporte ni aviso.
 */
class EvidenceMaintenance
{
    /**
     * Un fichero sin reporte que lo apunte puede ser una subida a medias: el
     * fichero se escribe antes que la fila. Solo se consideran huerfanos los
     * que llevan al menos este tiempo ahi.
     */
    private const ORPHAN_MIN_AGE_SECONDS = 86400;

    public function __construct(private readonly EvidenceStorage $storage)
    {
    }

    /**
     * @return array{files:int, bytes:int, convertible:int, convertible_bytes:int, orphans:int, orphan_bytes:int}
     */
    public function summary(): array
    {
        $disk = $this->disk();
        $referenced = $this->referencedPaths();
        $files = collect($disk->allFiles('match-reports'));

        $convertible = $files->filter(fn (string $p) => $referenced->has($p) && $this->isConvertible($p));
        $orphans = $this->orphans($files, $referenced);

        return [
            'files' => $files->count(),
            'bytes' => $files->sum(fn (string $p) => $disk->size($p)),
            'convertible' => $convertible->count(),
            'convertible_bytes' => $convertible->sum(fn (string $p) => $disk->size($p)),
            'orphans' => $orphans->count(),
            'orphan_bytes' => $orphans->sum(fn (string $p) => $disk->size($p)),
        ];
    }

    /**
     * Pasa a WebP cada captura referenciada que no lo sea ya.
     *
     * @return array{converted:int, skipped:int, saved_bytes:int}
     */
    public function compress(): array
    {
        $disk = $this->disk();
        $converted = 0;
        $skipped = 0;
        $saved = 0;
        $map = [];

        foreach ($this->referencedPaths()->keys() as $path) {
            if (!$this->isConvertible($path) || !$disk->exists($path)) {
                continue;
            }

            $before = $disk->size($path);
            $webp = $this->storage->normalize($this->asUpload($disk->path($path)));

            if ($webp === null || strlen($webp) >= $before) {
                $skipped++;
                continue;
            }

            $newPath = preg_replace('/\.[a-z0-9]+$/i', '', $path) . '.webp';
            $disk->put($newPath, $webp);
            $map[$path] = $newPath;

            $converted++;
            $saved += $before - strlen($webp);
        }

        // Primero se apunta la base de datos a los ficheros nuevos y solo
        // despues se borran los viejos: si algo falla entre medias queda algun
        // fichero de mas, nunca un reporte sin prueba.
        if ($map !== []) {
            $this->replaceReferences($map);
            foreach (array_keys($map) as $old) {
                $disk->delete($old);
            }
        }

        return ['converted' => $converted, 'skipped' => $skipped, 'saved_bytes' => $saved];
    }

    /**
     * @return array{deleted:int, freed_bytes:int}
     */
    public function deleteOrphans(): array
    {
        $disk = $this->disk();
        $orphans = $this->orphans(collect($disk->allFiles('match-reports')), $this->referencedPaths());
        $freed = $orphans->sum(fn (string $p) => $disk->size($p));

        $orphans->each(fn (string $p) => $disk->delete($p));

        return ['deleted' => $orphans->count(), 'freed_bytes' => $freed];
    }

    /**
     * Todas las rutas que apunta algun reporte o aviso de abandono.
     *
     * @return Collection<string, true>
     */
    private function referencedPaths(): Collection
    {
        $paths = collect();

        // rejection_evidence_paths llego con una migracion posterior: si falta,
        // el comando no puede caerse por ello.
        $columnas = ['id', 'encounter_screenshot_path', 'final_screenshot_path', 'evidence_paths'];
        if (Schema::hasColumn('match_reports', 'rejection_evidence_paths')) {
            $columnas[] = 'rejection_evidence_paths';
        }

        MatchReport::query()
            ->select($columnas)
            ->chunkById(500, function ($reports) use ($paths) {
                foreach ($reports as $report) {
                    $paths->push($report->encounter_screenshot_path, $report->final_screenshot_path);
                    $paths->push(...array_values($report->evidence_paths ?? []));
                    $paths->push(...array_values($report->rejection_evidence_paths ?? []));
                }
            });

        MatchAbandonmentReport::query()
            ->select(['id', 'evidence_paths'])
            ->chunkById(500, function ($avisos) use ($paths) {
                foreach ($avisos as $aviso) {
                    $paths->push(...array_values($aviso->evidence_paths ?? []));
                }
            });

        return $paths->filter(fn ($p) => is_string($p) && $p !== '')
            ->mapWithKeys(fn (string $p) => [$p => true]);
    }

    /**
     * Cambia rutas viejas por las nuevas en todos los reportes y avisos, en
     * una sola pasada por cada tabla.
     *
     * Se recorre en PHP y no con un LIKE sobre el JSON: dentro del JSON las
     * barras van escapadas, y en MySQL la barra invertida tambien escapa en
     * LIKE, asi que la busqueda no encontraria nada.
     *
     * @param  array<string, string>  $map  ruta vieja => ruta nueva
     */
    private function replaceReferences(array $map): void
    {
        $one = fn (?string $p) => $p !== null && isset($map[$p]) ? $map[$p] : $p;
        $list = fn (?array $l) => $l === null ? null : array_map($one, $l);

        $conRechazo = Schema::hasColumn('match_reports', 'rejection_evidence_paths');

        DB::transaction(function () use ($one, $list, $conRechazo) {
            MatchReport::query()->chunkById(500, function ($reports) use ($one, $list, $conRechazo) {
                foreach ($reports as $report) {
                    $report->encounter_screenshot_path = $one($report->encounter_screenshot_path);
                    $report->final_screenshot_path = $one($report->final_screenshot_path);
                    $report->evidence_paths = $list($report->evidence_paths);

                    // Solo si la columna existe: asignarla sin ella marcaba el
                    // modelo como cambiado y el UPDATE fallaba.
                    if ($conRechazo) {
                        $report->rejection_evidence_paths = $list($report->rejection_evidence_paths);
                    }

                    if ($report->isDirty()) {
                        $report->saveQuietly();
                    }
                }
            });

            MatchAbandonmentReport::query()->chunkById(500, function ($avisos) use ($list) {
                foreach ($avisos as $aviso) {
                    $aviso->evidence_paths = $list($aviso->evidence_paths);

                    if ($aviso->isDirty()) {
                        $aviso->saveQuietly();
                    }
                }
            });
        });
    }

    private function orphans(Collection $files, Collection $referenced): Collection
    {
        $disk = $this->disk();
        $limit = now()->getTimestamp() - self::ORPHAN_MIN_AGE_SECONDS;

        return $files->reject(fn (string $p) => $referenced->has($p))
            ->filter(fn (string $p) => $disk->lastModified($p) <= $limit)
            ->values();
    }

    private function isConvertible(string $path): bool
    {
        return (bool) preg_match('/\.(jpe?g|png|gif|bmp|avif|heic|heif)$/i', $path);
    }

    private function asUpload(string $absolute): UploadedFile
    {
        return new UploadedFile($absolute, basename($absolute), null, null, true);
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(MatchReport::EVIDENCE_DISK);
    }
}
