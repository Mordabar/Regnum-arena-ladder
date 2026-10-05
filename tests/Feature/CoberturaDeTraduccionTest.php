<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Support\I18n\Idioma;

/**
 * Lo que se muestra o se manda por Discord sale de listas escritas en español
 * en el codigo (estados, modos). Si una lista gana un valor y el catalogo no,
 * ese texto sale sin traducir en un idioma y nadie lo nota.
 */
function catalogoDe(string $idioma): array
{
    return json_decode((string) file_get_contents(lang_path($idioma . '.json')), true);
}

it('los estados y modos que viajan por Discord estan en los idiomas de la web', function () {
    $textos = array_merge(
        array_values(ArenaMatch::STATUSES),
        array_values(MatchReport::STATUSES),
        array_values(ArenaMatch::QUEUE_MODES),
        ['Random vs Premade :modo'],
    );

    foreach (array_diff(Idioma::codigos(), [Idioma::FUENTE]) as $idioma) {
        $catalogo = catalogoDe($idioma);

        foreach ($textos as $texto) {
            expect(array_key_exists($texto, $catalogo))->toBeTrue("Falta en {$idioma}: {$texto}");
        }
    }
});

it('todos los catalogos tienen las mismas claves y los mismos placeholders', function () {
    $base = catalogoDe('en');

    foreach (['pt', 'de', 'fr', 'nl'] as $idioma) {
        $otro = catalogoDe($idioma);

        expect(array_keys($otro))->toEqualCanonicalizing(array_keys($base));

        foreach ($base as $clave => $_) {
            preg_match_all('/:[a-z_]+|\{\d+\}/i', $clave, $esperados);
            preg_match_all('/:[a-z_]+|\{\d+\}/i', $otro[$clave], $reales);

            expect($reales[0])->toEqualCanonicalizing($esperados[0], "{$idioma}: placeholders de «{$clave}»");
        }
    }
});

it('ningun mensaje para el jugador se queda escrito solo en español', function () {
    // Recorre el codigo buscando los textos que acaban en un flash, un error de
    // validacion o una excepcion que el jugador lee, y exige su clave. Los
    // textos del panel de administracion y del laboratorio de pruebas son del
    // equipo y se quedan en español.
    $excluidos = ['Admin', 'TestingLab', 'VapidKeys', 'SeasonClosingService', 'MatchModerationService', 'MatchPenaltyService',
        'PlayerCleanupService', 'ArenaMatchmakingService', 'Console'];
    $patron = '/(?:RuntimeException|withErrors\(\[[^\]]*?=>|->with\(\'(?:success|error|warning)\',|\'motivo\'\s*=>)\s*(?:__\()?\s*([\'"])((?:\\\\.|(?!\1).)+)\1/s';
    $catalogo = catalogoDe('en');
    $faltan = [];

    $archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($archivos as $archivo) {
        $ruta = (string) $archivo;

        if (!str_ends_with($ruta, '.php') || collect($excluidos)->contains(fn ($x) => str_contains($ruta, $x))) {
            continue;
        }

        preg_match_all($patron, (string) file_get_contents($ruta), $m);

        foreach ($m[2] as $texto) {
            $texto = str_replace("\\'", "'", $texto);
            $esEspañol = preg_match('/[áéíóúñ¿¡]|\b(el|la|los|las|de|que|tu|te|se|no|ya|un|una|para|con|por)\b/i', $texto) === 1;

            if ($esEspañol && !str_contains($texto, '$') && !array_key_exists($texto, $catalogo)) {
                $faltan[] = basename($ruta) . ': ' . $texto;
            }
        }
    }

    expect($faltan)->toBe([]);
});
