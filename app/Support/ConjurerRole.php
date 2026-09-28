<?php

namespace App\Support;

use App\Models\Player;

class ConjurerRole
{
    /**
     * El rol con el que entra un conjurador a la cola.
     *
     * En el duelo no se pregunta: un conjurador que entra a pelear solo tiene
     * que hacer daño, porque no hay a quien apoyar. Ademas el rol solo existe
     * para una regla de plantilla -"un soporte por equipo"- que con equipos de
     * uno no significa nada. Asi que en 1v1 se fija ofensivo y punto; en 2v2 y
     * 3v3 sigue siendo obligatorio elegir.
     */
    public static function resolve(Player $player, ?string $role, ?string $arenaMode = null): ?string
    {
        if ($player->subclass !== 'conjurer') {
            return null;
        }

        if (!ArenaMode::supportsPremade($arenaMode ?? ArenaMode::FALLBACK)) {
            return 'offensive';
        }

        if (!in_array($role, ['support', 'offensive'], true)) {
            throw new \RuntimeException('Los conjuradores deben seleccionar un rol.');
        }

        return $role;
    }
}
