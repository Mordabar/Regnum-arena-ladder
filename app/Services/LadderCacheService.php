<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LadderCacheService
{
    private const TOP_BY_REALM_CACHE_KEY = 'ladder:top-by-realm:v2';
    private const RECENT_MATCHES_CACHE_KEY = 'ladder:recent-matches:v1';
    private const TOP_BY_REALM_TTL_MINUTES = 5;
    private const RECENT_MATCHES_TTL_MINUTES = 2;

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

    public function getRecentMatches(): Collection
    {
        return Cache::remember(
            self::RECENT_MATCHES_CACHE_KEY,
            now()->addMinutes(self::RECENT_MATCHES_TTL_MINUTES),
            fn () => ArenaMatch::query()
                ->select('id', 'match_code', 'zone', 'status', 'winner_realm', 'completed_at')
                ->whereIn('status', ['completed', 'disputed', 'void'])
                ->latest('completed_at')
                ->take(8)
                ->get()
        );
    }

    public function forgetTopByRealm(): void
    {
        Cache::forget(self::TOP_BY_REALM_CACHE_KEY);
    }

    public function forgetRecentMatches(): void
    {
        Cache::forget(self::RECENT_MATCHES_CACHE_KEY);
    }

    public function forgetSummary(): void
    {
        $this->forgetTopByRealm();
        $this->forgetRecentMatches();
    }
}
