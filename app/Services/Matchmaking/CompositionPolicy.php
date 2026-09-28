<?php

namespace App\Services\Matchmaking;

use App\Models\Queue;
use App\Support\ArenaMode;
use Illuminate\Support\Collection;

/**
 * La composicion de los equipos: como se arma un equipo de un reino y cuanto
 * penaliza que dos equipos enfrentados no se parezcan (conjuradores, soportes,
 * subclases repetidas).
 *
 * Parte del emparejador (antes todo vivia en ArenaMatchmakingService).
 */
class CompositionPolicy
{
    public function __construct(
        private readonly DuelPolicy $duelPolicy,
    ) {
    }

    private const TEAM_SEARCH_WINDOW = 10;
    private const TEAM_DUPLICATE_SUBCLASS_PENALTY = 14;
    private const TEAM_TRIPLE_SUBCLASS_PENALTY = 28;
    private const TEAM_EXTRA_CONJURER_PENALTY = 8;
    private const TEAM_ARCHETYPE_PENALTY = 6;
    private const PAIR_CONJURER_MISMATCH_PENALTY = 12;
    private const PAIR_SUPPORT_MISMATCH_PENALTY = 16;
    private const PAIR_SUBCLASS_MISMATCH_WEIGHT = 5;

    public function evaluateQueueTeam(Collection $team): ?array
    {
        // Todas las entradas de un equipo comparten modalidad (se agrupa por
        // arena_mode antes de llegar aqui), asi que la primera define el tamaño.
        $teamSize = ArenaMode::teamSize($team->first()?->arena_mode);

        if ($team->count() !== $teamSize) {
            return null;
        }

        // Un mismo usuario no puede ocupar dos puestos del equipo con dos de
        // sus personajes: fisicamente solo puede jugar uno. buildPremadeTeams
        // ya lo validaba por su cuenta, pero el camino random no lo hacia y es
        // alcanzable (varios personajes por cuenta, reencolados tras cancelar).
        // Al vivir aqui, la regla cubre las dos ramas.
        $userIds = $team->map(fn (Queue $queue) => (int) ($queue->player->user_id ?? 0))->filter();

        if ($userIds->count() !== $teamSize || $userIds->unique()->count() !== $teamSize) {
            return null;
        }

        $profile = $this->buildQueueTeamProfile($team);

        // El rol del conjurador es una regla de plantilla -"un soporte por
        // equipo"- y solo se exige donde hay plantilla. En el duelo no se
        // pregunta, se fija ofensivo al encolar, y una fila antigua o un dato
        // raro con el rol en blanco no puede dejar a alguien esperando para
        // siempre en una cola donde la regla ni existe.
        if ($teamSize >= 2 && ($profile['support_conjurers'] > 1 || $profile['invalid_conjurer_roles'] > 0)) {
            return null;
        }

        $compositionPenalty = 0;
        foreach ($profile['subclasses'] as $count) {
            if ($count > 1) {
                $compositionPenalty += ($count - 1) * self::TEAM_DUPLICATE_SUBCLASS_PENALTY;
            }

            // Penalizacion extra solo tiene sentido a partir de 3: en 2v2 un
            // equipo entero de la misma subclase ya lo cubre la regla anterior.
            if ($count === $teamSize && $teamSize >= 3) {
                $compositionPenalty += self::TEAM_TRIPLE_SUBCLASS_PENALTY;
            }
        }

        $compositionPenalty += max(0, $profile['conjurer_count'] - 1) * self::TEAM_EXTRA_CONJURER_PENALTY;

        // "Mezcla al menos dos roles" es una regla de plantilla. Con un jugador
        // por equipo no hay plantilla que mezclar: cobrarsela dejaba a todo el
        // mundo con el mismo recargo fijo, que no ordena nada y solo ensuciaba
        // la cifra.
        if ($teamSize >= 2) {
            $compositionPenalty += max(0, 2 - $profile['archetype_count']) * self::TEAM_ARCHETYPE_PENALTY;
        }

        return [
            'profile' => $profile,
            'composition_penalty' => $compositionPenalty,
        ];
    }

    public function buildQueueTeamProfile(Collection $team): array
    {
        $subclasses = [];
        $archetypes = [];
        $supportConjurers = 0;
        $conjurerCount = 0;
        $invalidConjurerRoles = 0;

        foreach ($team as $queue) {
            $subclass = (string) $queue->player->subclass;
            $subclasses[$subclass] = ($subclasses[$subclass] ?? 0) + 1;

            $archetype = $this->resolveSubclassArchetype($subclass);
            $archetypes[$archetype] = ($archetypes[$archetype] ?? 0) + 1;

            if ($subclass !== 'conjurer') {
                continue;
            }

            $conjurerCount++;
            if (!in_array($queue->conjurer_role, ['support', 'offensive'], true)) {
                $invalidConjurerRoles++;
                continue;
            }

            if ($queue->conjurer_role === 'support') {
                $supportConjurers++;
            }
        }

        return [
            'subclasses' => $subclasses,
            'unique_subclasses' => count($subclasses),
            'archetype_count' => count($archetypes),
            'conjurer_count' => $conjurerCount,
            'support_conjurers' => $supportConjurers,
            'invalid_conjurer_roles' => $invalidConjurerRoles,
        ];
    }

    private function resolveSubclassArchetype(string $subclass): string
    {
        return match ($subclass) {
            'knight', 'barbarian' => 'frontline',
            'hunter', 'marksman', 'warlock' => 'damage',
            'conjurer' => 'utility',
            default => 'flex',
        };
    }

    public function calculatePairCompositionPenalty(array $teamA, array $teamB): int
    {
        $profileA = $teamA['profile'] ?? $this->buildQueueTeamProfile($teamA['entries']);
        $profileB = $teamB['profile'] ?? $this->buildQueueTeamProfile($teamB['entries']);

        // El duelo tiene su propia escala. La de equipos suma tres castigos
        // pensados para comparar plantillas -cuantos conjuradores, cuantos
        // soportes, cuantas subclases repetidas- y con un jugador por lado esas
        // tres cuentas miden lo mismo tres veces: un brujo contra un caballero
        // pagaba 10 por las subclases y un conjurador contra cualquiera pagaba
        // 12 mas solo por ser conjurador, asi que el conjurador era el peor
        // rival posible para todos y el espejo conjurador contra conjurador no
        // valia mas que un brujo contra un barbaro.
        if (ArenaMode::teamSize($teamA['arena_mode'] ?? null) === 1) {
            return $this->duelPolicy->calculateDuelStylePenalty($teamA, $teamB);
        }

        $subclassKeys = collect(array_keys($profileA['subclasses']))
            ->merge(array_keys($profileB['subclasses']))
            ->unique();

        $subclassPenalty = $subclassKeys->reduce(function (int $carry, string $subclass) use ($profileA, $profileB) {
            return $carry + (abs(($profileA['subclasses'][$subclass] ?? 0) - ($profileB['subclasses'][$subclass] ?? 0)) * self::PAIR_SUBCLASS_MISMATCH_WEIGHT);
        }, 0);

        $conjurerPenalty = abs($profileA['conjurer_count'] - $profileB['conjurer_count']) * self::PAIR_CONJURER_MISMATCH_PENALTY;
        $supportPenalty = abs($profileA['support_conjurers'] - $profileB['support_conjurers']) * self::PAIR_SUPPORT_MISMATCH_PENALTY;

        return $subclassPenalty + $conjurerPenalty + $supportPenalty;
    }

    public function findBestRealmTeam(Collection $available, int $teamSize): Collection
    {
        $count = $available->count();

        if ($count < $teamSize) {
            return collect();
        }

        $windowSize = min($count, self::TEAM_SEARCH_WINDOW);
        $bestTeam = null;
        $bestSpread = null;
        $bestScore = null;

        // Se evalua cada combinacion posible dentro de la ventana de busqueda.
        // Con TEAM_SEARCH_WINDOW = 10 son 45 combinaciones en 2v2 y 120 en 3v3.
        foreach ($this->combinationIndexes($windowSize, $teamSize) as $indexes) {
            $team = collect($indexes)->map(fn (int $index) => $available[$index]);

            $evaluation = $this->evaluateQueueTeam($team);
            if ($evaluation === null) {
                continue;
            }

            $mmrs = $team->map(fn (Queue $queue) => $queue->estimated_mmr ?? $queue->player->mmr ?? 800);
            $spread = $mmrs->max() - $mmrs->min();
            $score = $spread + $evaluation['composition_penalty'];

            if (
                $bestTeam === null
                || $score < $bestScore
                || ($score === $bestScore && $spread < $bestSpread)
            ) {
                $bestTeam = $team;
                $bestSpread = $spread;
                $bestScore = $score;
            }
        }

        return $bestTeam ?? collect();
    }

    /**
     * Combinaciones de $pickCount indices distintos tomados de [0, $itemCount),
     * en orden ascendente. Generaliza los bucles anidados que antes asumian
     * equipos de 2.
     *
     * @return \Generator<int, list<int>>
     */
    private function combinationIndexes(int $itemCount, int $pickCount, int $start = 0, array $prefix = []): \Generator
    {
        if ($pickCount <= 0) {
            yield $prefix;

            return;
        }

        for ($index = $start; $index <= $itemCount - $pickCount; $index++) {
            yield from $this->combinationIndexes($itemCount, $pickCount - 1, $index + 1, [...$prefix, $index]);
        }
    }
}
