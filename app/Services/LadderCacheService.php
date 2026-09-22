<?php

namespace App\Services;

use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LadderCacheService
{
    private const TOP_BY_REALM_CACHE_KEY = 'ladder:top-by-realm:v2';
    private const TOP_BY_REALM_TTL_MINUTES = 5;

    public function getTopByRealm(): Collection
    {
        return Cache::remember(
            self::TOP_BY_REALM_CACHE_KEY,
            now()->addMinutes(self::TOP_BY_REALM_TTL_MINUTES),
            fn () => collect(Player::REALMS)->mapWithKeys(function ($label, $realm) {
                return [
                    $realm => Player::query()
                        // subclass, race y gender hacen falta para dibujar la
                        // figura del podio; sin ellas todos salian igual.
                        ->select('id', 'character_name', 'realm', 'subclass', 'race', 'gender', 'pl_points', 'mmr', 'is_active', 'deactivated_reason')
                        ->where('is_active', true)
                        ->where('realm', $realm)
                        ->orderByPublicLadder()
                        ->take(5)
                        ->get(),
                ];
            })
        );
    }

    public function forgetTopByRealm(): void
    {
        Cache::forget(self::TOP_BY_REALM_CACHE_KEY);
    }

    /**
     * Antes habia tambien una lista de "cierres recientes".
     *
     * Se quito del ladder -un muestrario de codigos de partida no le dice nada
     * a quien viene a consultar el ranking- y con ella la clave y su borrado.
     * Se queda `forgetRecentMatches()` como un no-op y no se borra del todo
     * porque la llaman ocho sitios: cada uno era un `Cache::forget` de fichero
     * por cada combate cerrado, sobre una clave que ya no rellenaba nadie.
     */
    public function forgetRecentMatches(): void
    {
        // Nada que olvidar: la lista ya no existe.
    }

    public function forgetSummary(): void
    {
        $this->forgetTopByRealm();
    }
}
