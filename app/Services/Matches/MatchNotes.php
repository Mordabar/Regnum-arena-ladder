<?php

namespace App\Services\Matches;

/**
 * Las notas internas de un combate: cada cambio deja una linea con su hora
 * para que moderacion pueda reconstruir que paso.
 */
class MatchNotes
{
    public static function append(?string $notes, string $line): string
    {
        $clean = trim((string) $notes);

        return trim($clean . PHP_EOL . '[' . now()->toDateTimeString() . '] ' . $line);
    }
}
