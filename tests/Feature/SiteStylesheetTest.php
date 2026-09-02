<?php

/**
 * El sitio publico servia las utilidades de Tailwind desde
 * <script src="https://cdn.tailwindcss.com">, que compila Tailwind en el
 * navegador de cada visitante. Si ese dominio caia, tardaba o estaba bloqueado
 * (red corporativa, extension, pais), la pagina se quedaba SIN una sola clase:
 * texto plano apilado, sin rejillas ni botones.
 *
 * Ahora se compila y se sirve desde el propio dominio. Estas pruebas evitan que
 * la dependencia vuelva a colarse y que el CSS se publique incompleto.
 *
 * La comprobacion exhaustiva de que no falta ninguna clase la hace
 * `npm run check:css`, que compara la hoja compilada contra otra con todas las
 * clases de las plantillas en la lista blanca. Aqui se cubre lo que se puede
 * verificar sin node.
 */

function bladeTemplates(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

it('no carga tailwind desde un CDN externo', function () {
    $offenders = [];

    foreach (bladeTemplates() as $template) {
        if (str_contains(file_get_contents($template), 'src="https://cdn.tailwindcss.com')) {
            $offenders[] = str_replace(resource_path('views') . '/', '', $template);
        }
    }

    expect($offenders)->toBe([], 'Estas vistas volverian a depender del CDN: ' . implode(', ', $offenders));
});

it('publica la hoja del sitio con base, utilidades y variantes', function () {
    $stylesheet = public_path('css/site.css');

    expect(file_exists($stylesheet))->toBeTrue('Falta public/css/site.css. Ejecuta: npm run build');

    $css = file_get_contents($stylesheet);

    // Base (preflight): lo que el CDN aportaba ademas de las utilidades.
    expect($css)->toContain('box-sizing:border-box');

    // Utilidades corrientes.
    foreach (['.flex', '.grid', '.hidden', '.relative'] as $utility) {
        expect($css)->toContain($utility . '{');
    }

    // Variantes responsive: si el escaneo se rompe, estas son las primeras que
    // desaparecen y la pagina deja de adaptarse al movil.
    foreach (['768px', '1024px', '1280px'] as $breakpoint) {
        expect($css)->toContain('min-width:' . $breakpoint);
    }
    expect($css)->toContain('md\:grid-cols-3');
    expect($css)->toContain('lg\:grid-cols-2');

    // Valores arbitrarios, que es donde vive buena parte de este diseno. Los
    // selectores van escapados como los genera Tailwind: la coma es "\2c ".
    expect($css)->toContain('grid-cols-\[1\.08fr\2c 0\.92fr\]');
    expect($css)->toContain('lg\:grid-cols-\[260px_minmax\(0\2c 1fr\)\]');
    expect($css)->toContain('bg-\[color\:var\(--arena-line\)\]');
    expect($css)->toContain('text-\[color\:var\(--arena-gold-soft\)\]');
    expect($css)->toContain('shadow-\[0_25px_60px_rgba\(0\2c 0\2c 0\2c 0\.5\)\]');
    expect($css)->toContain('hover\:bg-white\/10');
});

it('enlaza la hoja despues del bloque de estilos del layout', function () {
    // El CDN inyectaba sus reglas al final de la cabecera, asi que las
    // utilidades ganaban a las clases arena-* cuando compartian propiedad. El
    // marcado depende de ello: "arena-field px-4 py-2" o
    // "arena-nav-link block w-full" solo tienen sentido si gana la utilidad.
    // Enlazar la hoja antes del <style> cambia el relleno de campos y botones
    // y rompe el menu movil.
    $layout = file_get_contents(resource_path('views/layouts/arena.blade.php'));

    $styleEnd = strpos($layout, '</style>');
    $link = strpos($layout, "asset('css/site.css')");

    expect($styleEnd)->not->toBeFalse();
    expect($link)->not->toBeFalse();
    expect($link)->toBeGreaterThan($styleEnd);
});

it('los estaticos se sirven comprimidos y cacheados', function () {
    // three.min.js son 589 KB sin comprimir y 146 KB con gzip. Medido en el
    // navegador contra un servidor sin compresion: en movil esa diferencia es
    // la mitad de la espera de carga.
    $htaccess = file_get_contents(public_path('.htaccess'));

    expect($htaccess)->toContain('mod_deflate')
        ->and($htaccess)->toContain('application/javascript')
        ->and($htaccess)->toContain('model/gltf-binary');
});

it('las hojas y los scripts llevan version en la URL', function () {
    // El cacheo de un ano solo es seguro si al cambiar el archivo cambia la
    // URL. Si algun dia se quita el ?v=, el jugador se queda con la version
    // vieja hasta que vacie la cache.
    $layout = file_get_contents(resource_path('views/layouts/arena.blade.php'));

    $champion = file_get_contents(resource_path('views/components/arena-champion.blade.php'));

    expect($layout)->toContain("asset('css/site.css') }}?v=")
        ->and($champion)->toContain("asset('js/arena-champion.js') }}?v=");
});
