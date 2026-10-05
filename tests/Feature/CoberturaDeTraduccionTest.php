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
    // Recorre el codigo buscando los textos que acaban en un flash, un abort,
    // una excepcion, una etiqueta o un __(), y exige su clave en el catalogo.
    // Lo del panel de administracion, el laboratorio de pruebas y los
    // mensajes internos (esquema, claves VAPID, cierre de temporadas) lo lee el
    // equipo, no el jugador, y se queda en español.
    $excluidos = ['Admin', 'TestingLab', 'VapidKeys', 'SeasonClosingService', 'MatchModerationService', 'MatchPenaltyService',
        'PlayerCleanupService', 'Console', 'AppSetting', 'LadderScoringService', 'ArenaMatchmakingService'];

    $disparador = "(?:RuntimeException|InvalidArgumentException|abort\\(\\s*\\d+\\s*,|withErrors\\(\\[[^\\]]*?=>"
        . "|->with\\(\\s*'(?:success|error|warning)'\\s*,|'(?:motivo|message|label|title|error)'\\s*=>"
        . "|withMessages\\(\\[[^\\]]*?=>|__\\()\\s*\\(?\\s*";
    $patron = '/' . $disparador . '([\'"])((?:\\\\.|(?!\\1).)+)\\1(\\s*\\.)?/s';
    $catalogo = catalogoDe('en');
    $faltan = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $archivo) {
        $ruta = (string) $archivo;

        if (!str_ends_with($ruta, '.php') || collect($excluidos)->contains(fn ($x) => str_contains($ruta, $x))) {
            continue;
        }

        preg_match_all($patron, (string) file_get_contents($ruta), $m, PREG_SET_ORDER);

        foreach ($m as $hallazgo) {
            $texto = str_replace("\\'", "'", $hallazgo[2]);
            $concatenado = ($hallazgo[3] ?? '') !== '';

            if (!preg_match('/[A-Za-zÁ-ú]{3}/u', $texto) || preg_match('/^[a-z_.:\-\/0-9]+$/', $texto)) {
                continue; // claves tecnicas
            }

            // Una concatenacion no se puede traducir: hay que usar placeholders.
            // Un literal con variable dentro, lo mismo; solo se acepta si ya esta en el catalogo.
            if (!array_key_exists($texto, $catalogo) && (!str_contains($texto, '$') || $concatenado)) {
                $faltan[] = basename($ruta) . ': ' . ($concatenado ? '[concatenado] ' : '') . $texto;
            }
        }
    }

    expect(array_values(array_unique($faltan)))->toBe([]);
});

it('los nombres de bloqueo y de motivo que ve el jugador estan traducidos', function () {
    foreach (array_diff(Idioma::codigos(), [Idioma::FUENTE]) as $idioma) {
        $catalogo = catalogoDe($idioma);

        foreach (array_values(\App\Models\Player::PENALTY_TYPES) as $texto) {
            expect(array_key_exists($texto, $catalogo))->toBeTrue("Falta en {$idioma}: {$texto}");
        }
    }
});
