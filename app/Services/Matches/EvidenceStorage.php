<?php

namespace App\Services\Matches;

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

/**
 * Guarda las capturas que suben los jugadores (reporte, rechazo y abandono).
 *
 * Antes se guardaba el fichero tal cual llegaba: hasta 10 MB por captura, tres
 * por reporte y otras tres por rechazo, con los metadatos del movil dentro
 * (modelo, fecha y a veces la posicion GPS). Con unos cientos de partidas eso
 * llenaba el disco contado de Hostinger y exponia datos que nadie necesita.
 *
 * Ahora cada captura se normaliza: como mucho 1920 px por lado, en WebP y sin
 * metadatos. Para moderar un combate sobra: se leen los nombres, las barras y
 * el chat, y una captura de 10 MB se queda en unos cientos de KB.
 *
 * Si la imagen no se puede procesar (un HEIC en un servidor sin soporte, un
 * fichero enorme que no cabe en memoria) se guarda la original: perder la
 * prueba de un combate es peor que ocupar mas disco.
 */
class EvidenceStorage
{
    /** Lado mayor maximo de la captura guardada. */
    public const MAX_SIDE = 1920;

    /** Calidad del WebP. Por debajo de ~75 el texto pequeño del chat se emborrona. */
    public const QUALITY = 82;

    /** Si aun asi pesa mas que esto, se prueba con una calidad algo menor. */
    private const TARGET_BYTES = 700 * 1024;

    private const FALLBACK_QUALITY = 72;

    /**
     * Por encima de estos pixeles no se intenta decodificar: una imagen de 40
     * megapixeles ocupa ~160 MB en memoria y tumbaria la peticion en un
     * hosting compartido. Ninguna captura de pantalla real llega ahi.
     */
    private const MAX_PIXELS = 40_000_000;

    /** Formatos que se guardan tal cual si no se pudieron convertir. */
    private const ORIGINAL_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif', 'heic', 'heif'];

    public function store(ArenaMatch $match, UploadedFile $file, string $slot): string
    {
        $directory = 'match-reports/' . now()->format('Y/m') . '/' . strtolower($match->match_code);
        $base = $slot . '-' . now()->format('His') . '-' . bin2hex(random_bytes(6));

        $webp = $this->normalize($file);

        if ($webp !== null) {
            return $this->write($directory, $base . '.webp', $webp);
        }

        // La original solo si es un formato de imagen de verdad: nunca un SVG
        // (puede llevar JavaScript) ni nada que el navegador pudiera ejecutar.
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: '');
        if (!in_array($extension, self::ORIGINAL_EXTENSIONS, true)) {
            throw new \RuntimeException('Ese archivo no es una captura valida. Sube una imagen JPG, PNG o WEBP.');
        }

        $original = @file_get_contents($file->getRealPath());

        if ($original === false) {
            throw new \RuntimeException('No se pudo leer la captura seleccionada. Intenta subirla de nuevo.');
        }

        return $this->write($directory, $base . '.' . $extension, $original);
    }

    /**
     * La captura en WebP, o null si no se pudo convertir.
     */
    public function normalize(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === false || !is_readable($path)) {
            return null;
        }

        $size = @getimagesize($path);
        if (is_array($size) && ($size[0] * $size[1]) > self::MAX_PIXELS) {
            return null;
        }

        foreach ($this->managers() as $manager) {
            try {
                // El decodificador ya gira la imagen segun su EXIF (una foto
                // del movil en vertical sale derecha) y el WebP resultante no
                // lleva ningun metadato del original.
                $image = $manager->read($path);
                $image->scaleDown(width: self::MAX_SIDE, height: self::MAX_SIDE);

                $encoded = (string) $image->toWebp(self::QUALITY);

                if (strlen($encoded) > self::TARGET_BYTES) {
                    $encoded = (string) $image->toWebp(self::FALLBACK_QUALITY);
                }

                if ($encoded !== '') {
                    return $encoded;
                }
            } catch (\Throwable $e) {
                Log::info('No se pudo normalizar una captura; se prueba otro motor o se guarda la original.', [
                    'mime' => $file->getClientMimeType(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Imagick primero si esta (lee HEIC cuando el servidor lo trae), GD
     * despues, que es lo que hay en casi cualquier hosting.
     *
     * @return array<int, ImageManager>
     */
    private function managers(): array
    {
        $managers = [];

        if (extension_loaded('imagick')) {
            $managers[] = new ImageManager(new ImagickDriver());
        }

        if (extension_loaded('gd') && function_exists('imagewebp')) {
            $managers[] = new ImageManager(new GdDriver());
        }

        return $managers;
    }

    private function write(string $directory, string $filename, string $contents): string
    {
        $disk = Storage::disk(MatchReport::EVIDENCE_DISK);
        $path = $directory . '/' . $filename;

        try {
            $disk->makeDirectory($directory);
            $stored = $disk->put($path, $contents);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'No se pudo guardar la captura. Revisa permisos de storage en el servidor.',
                previous: $e
            );
        }

        if (!$stored || !$disk->exists($path)) {
            throw new \RuntimeException('La captura no pudo almacenarse correctamente en el servidor.');
        }

        return $path;
    }
}
