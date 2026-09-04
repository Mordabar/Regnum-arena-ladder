<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Anota cuando fue la ultima vez que el usuario paso por el ladder.
 *
 * Se escribe como mucho una vez cada cuarto de hora por usuario: basta para
 * decidir si alguien lleva dos semanas sin aparecer, y evita una escritura en
 * cada peticion, incluidas las del sondeo de estado, que van cada pocos
 * segundos.
 */
class TrackUserActivity
{
    private const REFRESH_MINUTES = 15;

    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user && $this->isStale($user)) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    private function isStale($user): bool
    {
        return $user->last_seen_at === null
            || $user->last_seen_at->lt(now()->subMinutes(self::REFRESH_MINUTES));
    }
}
