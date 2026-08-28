<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\ArenaSeason;

final class ArenaMode
{
    public const TWO_V_TWO = '2v2';
    public const THREE_V_THREE = '3v3';

    public const MODES = [
        self::TWO_V_TWO => 2,
        self::THREE_V_THREE => 3,
    ];

    public static function all(): array
    {
        return array_keys(self::MODES);
    }

    public static function normalize(?string $mode): ?string
    {
        $normalized = strtolower(trim((string) $mode));

        return array_key_exists($normalized, self::MODES) ? $normalized : null;
    }

    public static function resolve(?string $mode = null): string
    {
        return self::normalize($mode) ?? self::default();
    }

    public static function default(): string
    {
        foreach (self::all() as $mode) {
            if (self::isEnabled($mode)) {
                return $mode;
            }
        }

        return self::TWO_V_TWO;
    }

    public static function isEnabled(string $mode): bool
    {
        $mode = self::normalize($mode) ?? self::TWO_V_TWO;
        $season = ArenaSeason::current();

        if ($season) {
            return in_array($mode, $season->enabledModes(), true);
        }

        return (bool) AppSetting::getValue('mode_' . $mode . '_enabled', true);
    }

    public static function enabled(): array
    {
        $season = ArenaSeason::current();
        if ($season) {
            return $season->enabledModes();
        }

        return array_values(array_filter(self::all(), fn (string $mode) => self::isEnabled($mode)));
    }

    public static function teamSize(string $mode): int
    {
        return self::MODES[self::normalize($mode) ?? self::TWO_V_TWO];
    }

    public static function label(string $mode): string
    {
        return self::resolve($mode);
    }

    public static function query(string $mode): array
    {
        return ['mode' => self::resolve($mode)];
    }
}
