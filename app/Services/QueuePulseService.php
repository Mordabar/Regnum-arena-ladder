<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Queue;
use App\Support\ArenaMode;
use Illuminate\Support\Facades\DB;

/**
 * Cuanta gente hay esperando en cola ahora mismo, por reino.
 *
 * Existe porque el problema real de una cola vacia no es la espera, es no saber
 * si hay alguien mas al otro lado. Un jugador que ve "Ignis 3 · Alsius 1" sabe
 * que la arena esta viva y aguanta; uno que no ve nada se va a los dos minutos.
 */
class QueuePulseService
{
    /**
     * @return array{
     *     mode: string,
     *     team_size: int,
     *     total: int,
     *     realms: array<int, array{key: string, name: string, waiting: int}>,
     *     hint: string|null
     * }
     */
    public function forMode(?string $mode, ?string $viewerRealm = null): array
    {
        $mode = ArenaMode::normalize($mode) ?? ArenaMode::FALLBACK;
        $teamSize = ArenaMode::teamSize($mode);

        $counts = Queue::query()
            ->join('players', 'players.id', '=', 'queues.player_id')
            ->where('queues.status', 'waiting')
            ->where('queues.arena_mode', $mode)
            // Una cola caducada sigue en la tabla hasta que pasa el mantenimiento.
            // Contarla mentiria: diria que hay gente esperando que ya no esta.
            ->where(function ($query) {
                $query->whereNull('queues.expires_at')
                    ->orWhere('queues.expires_at', '>', now());
            })
            ->groupBy('players.realm')
            ->select('players.realm', DB::raw('count(*) as waiting'))
            ->pluck('waiting', 'realm');

        $realms = [];
        foreach (Player::REALMS as $key => $name) {
            $realms[] = [
                'key' => $key,
                'name' => $name,
                'waiting' => (int) ($counts[$key] ?? 0),
            ];
        }

        return [
            'mode' => $mode,
            'team_size' => $teamSize,
            'total' => (int) $counts->sum(),
            'realms' => $realms,
            'hint' => $this->hint($realms, $teamSize, $viewerRealm),
        ];
    }

    /**
     * Que falta para que se arme un cruce, contado desde el reino del jugador.
     *
     * El emparejamiento necesita un equipo completo de tu reino y otro completo
     * de un reino distinto. Decirlo en esos terminos es mas util que un numero
     * suelto: el jugador sabe si le falta gente propia o rivales.
     */
    private function hint(array $realms, int $teamSize, ?string $viewerRealm): ?string
    {
        if ($viewerRealm === null) {
            return null;
        }

        $byKey = collect($realms)->keyBy('key');
        $own = (int) ($byKey[$viewerRealm]['waiting'] ?? 0);

        $rivalBest = collect($realms)
            ->reject(fn (array $realm) => $realm['key'] === $viewerRealm)
            ->max('waiting') ?? 0;

        $ownMissing = max(0, $teamSize - $own);
        $rivalMissing = max(0, $teamSize - $rivalBest);

        if ($ownMissing === 0 && $rivalMissing === 0) {
            return 'Ya hay gente suficiente: el cruce se arma en la proxima pasada.';
        }

        $parts = [];
        if ($ownMissing > 0) {
            $parts[] = $ownMissing === 1
                ? 'falta 1 de tu reino'
                : 'faltan ' . $ownMissing . ' de tu reino';
        }
        if ($rivalMissing > 0) {
            $parts[] = $rivalMissing === 1
                ? 'falta 1 de un reino rival'
                : 'faltan ' . $rivalMissing . ' de un reino rival';
        }

        return ucfirst(implode(' y ', $parts)) . '.';
    }
}
