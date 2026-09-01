<?php

namespace App\Support;

/**
 * Que modelos 3D hay subidos ahora mismo.
 *
 * El visor los busca por `reino-subclase`. Mientras un archivo no exista, ese
 * guerrero se dibuja con la silueta generada por codigo, asi que los modelos se
 * pueden ir subiendo de uno en uno sin tocar ni una linea de codigo: basta con
 * dejar el .glb en public/models.
 */
class ChampionModels
{
    public const DIRECTORY = 'models';

    /**
     * @return array<int, string> claves tipo "ignis-knight", sin extension
     */
    public static function available(): array
    {
        $path = public_path(self::DIRECTORY);

        if (!is_dir($path)) {
            return [];
        }

        $files = glob($path . DIRECTORY_SEPARATOR . '*.glb') ?: [];

        return array_values(array_map(
            fn (string $file) => pathinfo($file, PATHINFO_FILENAME),
            $files
        ));
    }
}
