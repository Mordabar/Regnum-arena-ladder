<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Modalidades de arena.
 *
 * 2v2 y 3v3 conviven: cada una se enciende o apaga por separado desde el panel
 * admin y ambas alimentan el mismo ladder (mismos PL, mismo MMR, misma tabla).
 * Lo unico que cambia entre modalidades es cuanta gente entra por equipo.
 */
final class ArenaMode
{
    public const TWO_V_TWO = '2v2';
    public const THREE_V_THREE = '3v3';

    /** Modalidad => jugadores por equipo. */
    public const MODES = [
        self::TWO_V_TWO => 2,
        self::THREE_V_THREE => 3,
    ];

    /** Modalidad usada cuando no hay ninguna encendida, para no romper rutas ni etiquetas. */
    public const FALLBACK = self::TWO_V_TWO;

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::MODES);
    }

    /**
     * Devuelve la modalidad si es valida, o null. No aplica ningun default.
     */
    public static function normalize(?string $mode): ?string
    {
        $normalized = strtolower(trim((string) $mode));

        return array_key_exists($normalized, self::MODES) ? $normalized : null;
    }

    /**
     * Normaliza y, si el valor no sirve, cae en la modalidad por defecto.
     *
     * Ojo: esto no valida que la modalidad este encendida. Quien reciba input
     * del usuario debe comprobar isEnabled() por separado.
     */
    public static function resolve(?string $mode = null): string
    {
        return self::normalize($mode) ?? self::default();
    }

    /**
     * Primera modalidad encendida. Si no hay ninguna, cae en FALLBACK para que
     * las vistas y rutas sigan resolviendo.
     */
    public static function default(): string
    {
        return self::enabled()[0] ?? self::FALLBACK;
    }

    public static function isEnabled(?string $mode): bool
    {
        $normalized = self::normalize($mode);

        if ($normalized === null) {
            return false;
        }

        return (bool) AppSetting::getValue(self::settingKey($normalized), $normalized === self::TWO_V_TWO);
    }

    /**
     * @return list<string>
     */
    public static function enabled(): array
    {
        return array_values(array_filter(self::all(), static fn (string $mode) => self::isEnabled($mode)));
    }

    public static function anyEnabled(): bool
    {
        return self::enabled() !== [];
    }

    public static function teamSize(?string $mode): int
    {
        return self::MODES[self::normalize($mode) ?? self::FALLBACK];
    }

    public static function label(?string $mode): string
    {
        return self::normalize($mode) ?? self::FALLBACK;
    }

    public static function settingKey(string $mode): string
    {
        return 'mode_' . $mode . '_enabled';
    }
}
