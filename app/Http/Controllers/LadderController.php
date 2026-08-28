<?php

namespace App\Http\Controllers;

use App\Models\MatchResult;
use App\Models\Player;
use App\Services\LadderCacheService;
use Illuminate\Http\Request;

class LadderController extends Controller
{
    public function index(Request $request, LadderCacheService $ladderCacheService)
    {
        $query = Player::query()->where('is_active', true);
        $realm = $request->filled('realm') ? $request->string('realm')->value() : null;
        $subclass = $request->filled('subclass') ? $request->string('subclass')->value() : null;
        $search = trim((string) $request->input('search', ''));

        if ($realm) {
            $query->where('realm', $realm);
        }

        if ($subclass) {
            $query->where('subclass', $subclass);
        }

        if ($search !== '') {
            $query->where('character_name', 'like', '%' . $search . '%');
        }

        $players = $query
            ->orderByPublicLadder()
            ->paginate(25)
            ->withQueryString();

        $topByRealm = $ladderCacheService->getTopByRealm();
        $recentMatches = $ladderCacheService->getRecentMatches();

        return view('ladder.index', compact('players', 'topByRealm', 'recentMatches'));
    }

    public function show(Player $player)
    {
        $results = MatchResult::query()
            ->with('match')
            ->where('player_id', $player->id)
            ->latest('created_at')
            ->paginate(20);

        return view('ladder.show', compact('player', 'results'));
    }
}
