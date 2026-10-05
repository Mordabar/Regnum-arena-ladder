<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\ArenaSeason;
use Illuminate\Support\Facades\Schema;

/**
 * Competitivo o amistoso.
 *
 * Una partida competitiva mueve el ranking (PL, MMR, victorias) y cuenta para
 * la temporada. Una amistosa es PvP sin mas: se empareja, se juega y no deja
 * rastro en el ladder.
 *
 * - El amistoso se enciende y apaga desde el panel, como las modalidades.
 * - El competitivo no tiene interruptor propio: esta abierto mientras haya una
 *   temporada abierta que no haya llegado a su fecha de fin. Cuando acaba y no
 *   se abre otra, el ladder queda en pausa y solo quedan los amistosos.
 *
 * Cada tipo vale para las tres modalidades (1v1, 2v2, 3v3).
 */
final class Competition
{
    public const RANKED = 'ranked';
    public const FRIENDLY = 'friendly';

    public const SETTING_FRIENDLY = 'friendly_enabled';

    public static function friendlyEnabled(): bool
    {
        return (bool) AppSetting::getValue(self::SETTING_FRIENDLY, true);
    }

    /**
     * El ladder esta en juego: hay una temporada abierta y no ha vencido.
     *
     * Sin la tabla de temporadas (un esquema viejo) se considera abierto: antes
     * de las temporadas el ladder siempre estaba en juego.
     */
    public static function rankedOpen(): bool
    {
        if (!\App\Support\Esquema::tabla('arena_seasons')) {
            return true;
        }

        $actual = ArenaSeason::current();

        if ($actual === null) {
            return false;
        }

        // Pasada su fecha con cierre automatico ya no cuenta, aunque el cron
        // todavia no la haya cerrado: no se pueden seguir sumando puntos a una
        // temporada que acabo.
        return !$actual->vencida();
    }

    public static function isOpen(?string $kind): bool
    {
        return match (self::normalize($kind)) {
            self::RANKED => self::rankedOpen(),
            self::FRIENDLY => self::friendlyEnabled(),
            default => false,
        };
    }

    /** @return list<string> los tipos que se pueden jugar ahora mismo */
    public static function open(): array
    {
        return array_values(array_filter(
            [self::RANKED, self::FRIENDLY],
            static fn (string $kind) => self::isOpen($kind)
        ));
    }

    public static function normalize(mixed $kind): ?string
    {
        // ?kind[]=x llega como array: no es un tipo, y castearlo a string rompe.
        if (!is_string($kind)) {
            return null;
        }

        $kind = strtolower(trim($kind));

        return in_array($kind, [self::RANKED, self::FRIENDLY], true) ? $kind : null;
    }

    /** Lo que se elige cuando nadie pide nada: competitivo si esta abierto. */
    public static function default(): string
    {
        return self::rankedOpen() || !self::friendlyEnabled() ? self::RANKED : self::FRIENDLY;
    }

    /** El tipo pedido si se puede jugar; si no, el que si. */
    public static function resolve(mixed $kind): string
    {
        $kind = self::normalize($kind);

        return $kind !== null && self::isOpen($kind) ? $kind : self::default();
    }

    public static function kindOf(bool $isRanked): string
    {
        return $isRanked ? self::RANKED : self::FRIENDLY;
    }

    public static function label(bool $isRanked): string
    {
        return $isRanked ? 'Competitivo' : 'Amistoso';
    }
}
