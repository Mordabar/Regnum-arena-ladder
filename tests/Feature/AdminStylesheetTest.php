<?php

/**
 * La hoja del panel se compila y se versiona en el repo, porque el servidor no
 * ejecuta node. Eso tiene un riesgo propio: Tailwind purga lo que no encuentra
 * en los archivos que escanea, asi que una clase usada en una plantilla que no
 * este en `tailwind.admin.config.js` desaparece del CSS y la pantalla sale sin
 * estilo. Paso de verdad con la paginacion: no se veia en local porque con
 * pocos datos solo hay una pagina y el bloque ni se renderiza.
 */

function panelTemplates(): array
{
    $paths = array_merge(
        glob(base_path('resources/views/admin/*.blade.php')),
        glob(base_path('resources/views/admin/auth/*.blade.php')),
        glob(base_path('resources/views/components/admin/*.blade.php')),
        [
            base_path('resources/views/layouts/admin.blade.php'),
            base_path('resources/views/vendor/pagination/admin.blade.php'),
        ],
    );

    return array_values(array_filter($paths, 'is_file'));
}

it('compila la hoja del panel con todas sus clases', function () {
    $stylesheet = public_path('css/admin.css');

    expect(file_exists($stylesheet))->toBeTrue('Falta public/css/admin.css: el panel se veria sin estilo.');

    $css = file_get_contents($stylesheet);
    $missing = [];

    foreach (panelTemplates() as $template) {
        $source = file_get_contents($template);

        preg_match_all('/class="([^"]*)"/', $source, $matches);

        foreach ($matches[1] as $attribute) {
            // Las interpolaciones de Blade se resuelven en tiempo de ejecucion:
            // aqui solo se pueden comprobar las clases literales.
            $literal = preg_replace('/\{\{.*?\}\}/s', ' ', $attribute);

            foreach (preg_split('/\s+/', (string) $literal, -1, PREG_SPLIT_NO_EMPTY) as $class) {
                if (!str_starts_with($class, 'ap-')) {
                    continue;
                }

                if (!str_contains($css, '.' . $class)) {
                    $missing[$class] = basename($template);
                }
            }
        }
    }

    expect($missing)->toBe([], 'Clases del panel ausentes del CSS compilado: '
        . json_encode($missing, JSON_UNESCAPED_SLASHES));
});
