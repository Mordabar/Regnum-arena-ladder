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

it('los estados y modos que viajan por Discord estan en los cuatro idiomas', function () {
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
