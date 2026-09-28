<?php

namespace App\Services\Matchmaking;

use App\Models\ArenaMatch;
use App\Services\ArenaZoneService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Elige la zona del combate: la frontera de los dos reinos primero, variando
 * y sin mandar dos combates a la misma zona si hay otra.
 *
 * Parte del emparejador (antes todo vivia en ArenaMatchmakingService).
 */
class ZonePicker
{
    public function __construct(
        private readonly MatchesSchema $schema,
        private readonly ArenaZoneService $zoneService,
    ) {
    }

    /** Las ultimas zonas que salieron, por par de reinos. Ver sortearConVariedad(). */
    private const ZONAS_RECIENTES_KEY = 'arena:zonas-recientes';

    /**
     * En que zona se juega este cruce.
     *
     * $activeMatches son los enfrentamientos vivos, y llega YA cargado desde
     * fuera. Antes lo consultaba aqui dentro, o sea una consulta a la base y un
     * repaso del mapa entero por cada partida creada: con la cola llena, crear
     * 450 partidas eran 450 consultas sobre una lista que crecia con cada una, y
     * ahi se iban tres cuartas partes del tiempo del emparejamiento -45 de los
     * 49 segundos que tardaba una cola de 900-. Se carga una vez por barrido y
     * cada partida nueva se le añade en memoria, que es lo mismo que leerla de
     * la base pero sin ir.
     */
    public function pickZone(string $teamARealm, string $teamBRealm, ?Collection $activeMatches = null): string
    {
        // Sin lista, se consulta: asi quien llame a esto suelto -los tests de
        // zonas, por ejemplo- sigue viendo el mismo comportamiento de siempre.
        $activeMatches ??= $this->cargarEnfrentamientosVivos();

        $activeZones = $activeMatches
            ->pluck('zone')
            ->filter()
            ->map(function ($zone) {
                return ArenaMatch::normalizeZoneKey((string) $zone) ?? (string) $zone;
            })
            ->unique()
            ->all();

        $allZones = $this->getCompatibleZonePool();
        $availableZones = collect($allZones)
            ->reject(function (string $zone) use ($activeZones) {
                $zoneKey = ArenaMatch::normalizeZoneKey($zone) ?? $zone;

                return in_array($zoneKey, $activeZones, true);
            })
            ->values()
            ->all();

        // El sorteo se hace entre las zonas de la frontera de estos dos reinos,
        // no entre las catorce. Antes salia cualquiera y mandaba a la gente a
        // cruzar el mapa entero para encontrarse; ahora un Syrtis contra Ignis
        // cae en su frontera mientras quede alguna libre, pero sigue siendo
        // sorteo: repartir los combates entre las zonas del cruce evita que
        // todo el mundo acabe en la misma.
        $preferredZones = ArenaMatch::preferredZonesFor($teamARealm, $teamBRealm);

        // Se compara por clave canonica, como hace el filtro de ocupadas. Con
        // una columna "zone" antigua y corta el catalogo llega en alias, y
        // comparar las cadenas a pelo dejaba la recomendacion en nada sin que
        // se notara: volvia a salir cualquier zona del mapa.
        $preferredAvailable = array_values(array_filter($availableZones, function (string $zone) use ($preferredZones) {
            $zoneKey = ArenaMatch::normalizeZoneKey($zone) ?? $zone;

            return in_array($zoneKey, $preferredZones, true);
        }));

        if ($preferredAvailable !== []) {
            return $this->sortearConVariedad($preferredAvailable, $teamARealm, $teamBRealm);
        }

        // Ninguna recomendada libre. Antes que hacer esperar a nadie se juega
        // en cualquier otra: la recomendacion ordena, no bloquea.
        if ($availableZones !== []) {
            return $this->sortearConVariedad($availableZones, $teamARealm, $teamBRealm);
        }

        $incomingRealms = [$teamARealm, $teamBRealm];
        sort($incomingRealms);

        $zoneScores = collect($allZones)->mapWithKeys(function (string $zone) use ($activeMatches, $incomingRealms, $preferredZones) {
            $zoneKey = ArenaMatch::normalizeZoneKey($zone) ?? $zone;

            $score = $activeMatches->reduce(function (int $carry, ArenaMatch $activeMatch) use ($zoneKey, $incomingRealms) {
                $activeZoneKey = ArenaMatch::normalizeZoneKey((string) $activeMatch->zone) ?? (string) $activeMatch->zone;
                if ($activeZoneKey !== $zoneKey) {
                    return $carry;
                }

                $activeRealms = [(string) $activeMatch->team_a_realm, (string) $activeMatch->team_b_realm];
                sort($activeRealms);

                if ($activeRealms === $incomingRealms) {
                    return $carry + 100;
                }

                $sharedRealms = count(array_intersect($incomingRealms, $activeRealms));

                if ($sharedRealms === 1) {
                    return $carry + 1;
                }

                return $carry + 10;
            }, 0);

            // Con todo el mapa ocupado la recomendacion sigue pesando, pero ya
            // no manda: un cruce del mismo par de reinos suma 100, asi que una
            // zona lejana solo gana cuando todas las de la frontera arrastran
            // cinco combates o mas de este mismo cruce.
            if ($preferredZones !== [] && !in_array($zoneKey, $preferredZones, true)) {
                $score += 500;
            }

            return [$zone => $score];
        });

        $bestScore = (int) $zoneScores->min();
        $pool = $zoneScores
            ->filter(fn (int $score) => $score === $bestScore)
            ->keys()
            ->values()
            ->all();

        return $this->sortearConVariedad($pool, $teamARealm, $teamBRealm);
    }

    /**
     * Sortea una zona sin repetir las ultimas que ya salieron.
     *
     * Azar puro no basta. Una frontera tiene tres o cuatro zonas, y sortear
     * entre cuatro sale dos veces la misma cada pocas tiradas: jugando se
     * nota, y lo que se nota es "siempre me manda al mismo sitio", aunque el
     * dado sea limpio. El filtro de zonas ocupadas tampoco ayuda, porque en
     * cuanto el combate anterior se cierra la zona vuelve al bombo.
     *
     * Asi que se recuerdan las ultimas usadas por este par de reinos y se
     * sortea entre las demas. Cuando no queda ninguna sin usar, la memoria se
     * vacia y vuelven a entrar todas: eso reparte de verdad en vez de repetir.
     *
     * @param  array<int, string>  $candidatas
     */
    private function sortearConVariedad(array $candidatas, string $realmA, string $realmB): string
    {
        if (count($candidatas) === 1) {
            return $candidatas[0];
        }

        $clave = $this->claveDeZonasRecientes($realmA, $realmB);
        $recientes = Cache::get($clave, []);
        $recientes = is_array($recientes) ? $recientes : [];

        $frescas = array_values(array_filter(
            $candidatas,
            fn (string $zona) => !in_array(ArenaMatch::normalizeZoneKey($zona) ?? $zona, $recientes, true)
        ));

        // Agotadas todas, se empieza otra vuelta.
        if ($frescas === []) {
            $recientes = [];
            $frescas = $candidatas;
        }

        $elegida = $frescas[array_rand($frescas)];

        // Se recuerda poco mas de la mitad del grupo: lo justo para que no
        // salga la misma dos veces seguidas y para que el reparto siga
        // pareciendo azar y no una rueda.
        $recientes[] = ArenaMatch::normalizeZoneKey($elegida) ?? $elegida;
        $tope = max(1, (int) ceil(count($candidatas) / 2));

        Cache::put($clave, array_slice($recientes, -$tope), now()->addHours(6));

        return $elegida;
    }

    private function claveDeZonasRecientes(string $realmA, string $realmB): string
    {
        $reinos = [$realmA, $realmB];
        sort($reinos);

        return self::ZONAS_RECIENTES_KEY . ':' . implode('-', $reinos);
    }

    /** Los enfrentamientos que ocupan zona ahora mismo. */
    public function cargarEnfrentamientosVivos(): Collection
    {
        return ArenaMatch::query()
            ->whereIn('status', ['pending_acceptance', 'accepted', 'in_progress'])
            ->get(['zone', 'team_a_realm', 'team_b_realm']);
    }

    private function getCompatibleZonePool(): array
    {
        $canonicalZones = ArenaMatch::zoneKeys();
        $zoneColumn = $this->schema->getMatchesColumns()->get('zone');

        if (!is_array($zoneColumn)) {
            return $canonicalZones;
        }

        $enumOptions = $this->schema->extractEnumOptions((string) ($zoneColumn['type'] ?? ''));
        if ($enumOptions !== []) {
            $normalizedEnumOptions = collect($enumOptions)
                ->map(function (string $zone) {
                    return ArenaMatch::normalizeZoneKey($zone);
                })
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($normalizedEnumOptions !== []) {
                return $normalizedEnumOptions;
            }
        }

        $maxLength = $this->schema->extractColumnLength((string) ($zoneColumn['type'] ?? ''));
        if ($maxLength === null) {
            return $canonicalZones;
        }

        $compatibleCanonicalZones = array_values(array_filter($canonicalZones, function (string $zone) use ($maxLength) {
            return strlen($zone) <= $maxLength;
        }));

        if ($compatibleCanonicalZones !== []) {
            return $compatibleCanonicalZones;
        }

        $fallbackZones = collect($canonicalZones)
            ->flatMap(fn (string $zone) => $this->getZoneStorageCandidates($zone))
            ->filter(function (string $zone) use ($maxLength) {
                return strlen($zone) <= $maxLength;
            })
            ->unique()
            ->values()
            ->all();

        return $fallbackZones !== [] ? $fallbackZones : $canonicalZones;
    }

    private function getZoneStorageCandidates(string $canonicalZone): array
    {
        $spaced = str_replace('_', ' ', $canonicalZone);
        $hyphenated = str_replace('_', '-', $canonicalZone);

        return match ($canonicalZone) {
            'central_ruins' => [$canonicalZone, $spaced, $hyphenated, 'centralruins', 'ruins'],
            'emerald_pass' => [$canonicalZone, $spaced, $hyphenated, 'emeraldpass', 'pass'],
            'crimson_canyon' => [$canonicalZone, $spaced, $hyphenated, 'crimsoncanyon', 'canyon'],
            'frozen_bridge' => [$canonicalZone, $spaced, $hyphenated, 'frozenbridge', 'bridge'],
            'merchant_coast' => [$canonicalZone, $spaced, $hyphenated, 'merchantcoast', 'coast'],
            'obsidian_watch' => [$canonicalZone, $spaced, $hyphenated, 'obsidianwatch', 'watch'],
            default => [$canonicalZone, $spaced, $hyphenated],
        };
    }
}
