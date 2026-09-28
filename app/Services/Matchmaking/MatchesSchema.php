<?php

namespace App\Services\Matchmaking;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Las columnas reales de la tabla matches. El emparejador escribe solo lo que
 * la base tiene: una migracion a medias no puede tumbar la cola.
 *
 * Parte del emparejador (antes todo vivia en ArenaMatchmakingService).
 */
class MatchesSchema
{
    private ?Collection $matchesColumnsCache = null;

    public function getMatchesColumns(): Collection
    {
        if ($this->matchesColumnsCache !== null) {
            return $this->matchesColumnsCache;
        }

        if (!Schema::hasTable('matches')) {
            return $this->matchesColumnsCache = collect();
        }

        return $this->matchesColumnsCache = collect(Schema::getColumns('matches'))->keyBy('name');
    }

    public function extractEnumOptions(string $columnType): array
    {
        if ($columnType === '' || !str_starts_with(strtolower($columnType), 'enum(')) {
            return [];
        }

        preg_match_all("/'([^']+)'/", $columnType, $matches);

        return $matches[1] ?? [];
    }

    public function extractColumnLength(string $columnType): ?int
    {
        if (preg_match('/\((\d+)\)/', $columnType, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
