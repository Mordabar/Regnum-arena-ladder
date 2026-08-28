<?php

namespace App\Http\Controllers;

use App\Models\ArenaSeason;
use App\Models\SeasonPlayerStat;

class HallOfFameController extends Controller
{
    public function index()
    {
        $seasons = ArenaSeason::query()
            ->where('status', ArenaSeason::STATUS_ARCHIVED)
            ->latest('ends_at')
            ->get()
            ->map(function (ArenaSeason $season) {
                $leaders = SeasonPlayerStat::query()
                    ->with('player')
                    ->where('season_id', $season->id)
                    ->where('is_hall_eligible', true)
                    ->where('matches_played', '>', 0)
                    ->orderByDesc('pl_points')
                    ->orderByDesc('mmr')
                    ->orderByDesc('wins')
                    ->orderBy('player_id')
                    ->take(3)
                    ->get();

                $season->setRelation('leaders', $leaders);

                return $season;
            });

        return view('hall-of-fame.index', compact('seasons'));
    }
}
