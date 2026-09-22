<?php

use Illuminate\Support\Facades\File;

/**
 * Que ninguna plantilla se rompa en silencio.
 *
 * Esto existe por un fallo que costo dos veces la misma tarde: Blade compila
 * las directivas que encuentra DENTRO de un comentario `{{-- --}}`. Escribir
 * en un comentario que la forma corta de `php` de una linea es peligrosa
 * bastaba para dejar una apertura de PHP sin cerrar y llevarse por delante el
 * resto del fichero; el error que salia era un "unexpected end of file" en la
 * vista compilada, a cientos de lineas de donde estaba la causa.
 *
 * Dos redes, las dos baratas:
 *
 *   1. Todas las plantillas compilan. Es lo que hace `view:cache`, pero aqui
 *      dentro y con el nombre del fichero en el fallo.
 *   2. Ninguna nombra una directiva peligrosa dentro de un comentario.
 */
it('todas las plantillas compilan', function () {
    $compilador = app('blade.compiler');
    $rotas = [];

    foreach (plantillas() as $ruta) {
        try {
            // Compilar a texto, sin escribir en disco ni renderizar: lo unico
            // que se comprueba es que el PHP que sale es PHP valido.
            $php = $compilador->compileString(File::get($ruta));

            // `eval` no vale -ejecutaria la vista-, pero el linter de PHP si
            // dice si el resultado esta bien formado.
            $temporal = tempnam(sys_get_temp_dir(), 'blade');
            file_put_contents($temporal, $php);

            exec('php -l ' . escapeshellarg($temporal) . ' 2>&1', $salida, $codigo);
            unlink($temporal);

            if ($codigo !== 0) {
                $rotas[] = corta($ruta) . ': ' . trim(implode(' ', $salida));
            }
        } catch (\Throwable $e) {
            $rotas[] = corta($ruta) . ': ' . $e->getMessage();
        }
    }

    expect($rotas)->toBe([]);
});

it('ningun comentario blade nombra una directiva que se compile', function () {
    // La lista es corta a proposito: son las que abren un bloque y por tanto
    // las que pueden dejar el fichero a medias. `@if` dentro de un comentario
    // es feo pero no rompe, asi que no se persigue.
    $peligrosas = ['php', 'endphp', 'verbatim', 'endverbatim'];
    $sospechosas = [];

    foreach (plantillas() as $ruta) {
        $contenido = File::get($ruta);

        preg_match_all('/\{\{--(.*?)--\}\}/s', $contenido, $comentarios);

        foreach ($comentarios[1] as $comentario) {
            foreach ($peligrosas as $directiva) {
                if (preg_match('/@' . $directiva . '\b/', $comentario)) {
                    $sospechosas[] = corta($ruta) . ' nombra @' . $directiva . ' dentro de un comentario';
                }
            }
        }
    }

    expect($sospechosas)->toBe([]);
});

/** @return array<int, string> */
function plantillas(): array
{
    return collect(File::allFiles(resource_path('views')))
        ->filter(fn ($fichero) => str_ends_with($fichero->getFilename(), '.blade.php'))
        ->map(fn ($fichero) => $fichero->getPathname())
        ->values()
        ->all();
}

function corta(string $ruta): string
{
    return str_replace(resource_path('views') . '/', '', $ruta);
}
