<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\MatchPing;
use App\Models\PartyMember;
use App\Models\User;
use App\Support\ArenaMode;

/**
 * Que tiene que saber un jugador AHORA MISMO.
 *
 * Lo pregunta el service worker cuando llega un toque de push. El toque viene
 * vacio -no lleva texto dentro- y eso, que parece una limitacion, resulta ser
 * lo correcto: lo que se enseña es el estado de este instante y no el de hace
 * treinta segundos. Si el cruce ya caduco mientras el aviso viajaba, no sale
 * una notificacion diciendo "acepta ahora" sobre algo que ya no existe.
 *
 * Cada aviso lleva `tag`. El navegador agrupa por esa etiqueta: dos toques
 * del mismo cruce se sustituyen en vez de apilarse, que es la diferencia
 * entre un aviso y un bombardeo.
 */
class AvisosPendientesService
{
    /** Un aviso del chat mas viejo que esto ya no se anuncia. */
    private const PING_FRESCO_MINUTOS = 3;

    /**
     * @return list<array{tag: string, titulo: string, cuerpo: string, url: string}>
     */
    public function para(User $user): array
    {
        $playerIds = $user->players()->where('is_active', true)->pluck('id')->all();

        if ($playerIds === []) {
            return [];
        }

        $avisos = [];

        foreach ($this->invitaciones($playerIds) as $aviso) {
            $avisos[] = $aviso;
        }

        $match = $this->matchEnCurso($playerIds);

        if ($match) {
            foreach ($this->delEnfrentamiento($match, $playerIds) as $aviso) {
                $avisos[] = $aviso;
            }
        }

        return $avisos;
    }

    /** @param  list<int>  $playerIds */
    private function matchEnCurso(array $playerIds): ?ArenaMatch
    {
        return ArenaMatch::query()
            ->whereIn('status', ['pending_acceptance', 'in_progress'])
            ->where(function ($q) use ($playerIds) {
                foreach ($playerIds as $id) {
                    // Las alineaciones viven en dos columnas JSON, asi que se
                    // busca por el id dentro del texto y se confirma despues
                    // en PHP: un LIKE puede colar un 12 dentro de un 120.
                    $q->orWhere('team_a', 'like', '%"player_id":' . $id . '%')
                        ->orWhere('team_b', 'like', '%"player_id":' . $id . '%');
                }
            })
            ->latest('id')
            ->get()
            ->first(fn (ArenaMatch $m) => $this->participa($m, $playerIds));
    }

    /** @param  list<int>  $playerIds */
    private function participa(ArenaMatch $match, array $playerIds): bool
    {
        foreach ($match->getAllPlayers() as $jugador) {
            if (in_array((int) ($jugador['player_id'] ?? 0), $playerIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $playerIds
     * @return list<array{tag: string, titulo: string, cuerpo: string, url: string}>
     */
    private function delEnfrentamiento(ArenaMatch $match, array $playerIds): array
    {
        $modo = ArenaMode::displayName($match->arena_mode);
        $lobby = route('lobby');

        if ($match->status === 'pending_acceptance') {
            // Si ya caduco no se anuncia: el jugador abriria el sitio para
            // encontrarse con que no hay nada, que es peor que no avisar.
            if ($match->isExpired()) {
                return [];
            }

            return [[
                'tag' => 'cruce:' . $match->id,
                'titulo' => 'Rival encontrado',
                'cuerpo' => $modo . '. Tienes que aceptar para que empiece.',
                'url' => $lobby,
            ]];
        }

        $avisos = [[
            'tag' => 'combate:' . $match->id,
            'titulo' => '¡A pelear!',
            'cuerpo' => 'Quedad en ' . $match->zone_name . '.',
            'url' => $lobby,
        ]];

        // El reporte del rival esperando respuesta: es lo unico del flujo que
        // se queda parado hasta que alguien lo mira.
        $report = $match->report;
        $miLado = null;

        foreach ($playerIds as $id) {
            $miLado = $match->getTeamSideForPlayer($id) ?: $miLado;
        }

        if ($report && $report->status === 'pending_confirmation' && $miLado && $report->reporting_team !== $miLado) {
            $avisos[] = [
                'tag' => 'reporte:' . $match->id,
                'titulo' => 'Hay un resultado por confirmar',
                'cuerpo' => 'El rival ya subio el suyo. Confirmalo o rechazalo.',
                'url' => $lobby,
            ];
        }

        foreach ($this->ultimoAvisoDelRival($match, $playerIds) as $aviso) {
            $avisos[] = $aviso;
        }

        return $avisos;
    }

    /**
     * Lo ultimo que dijo el rival por el chat, si es reciente.
     *
     * Solo el ultimo: durante un combate pueden llegar varios seguidos y no
     * se trata de sacar una notificacion por cada uno.
     *
     * @param  list<int>  $playerIds
     * @return list<array{tag: string, titulo: string, cuerpo: string, url: string}>
     */
    private function ultimoAvisoDelRival(ArenaMatch $match, array $playerIds): array
    {
        $ping = MatchPing::query()
            ->where('match_id', $match->id)
            ->whereNotIn('player_id', $playerIds)
            ->where('created_at', '>=', now()->subMinutes(self::PING_FRESCO_MINUTOS))
            ->latest('id')
            ->first();

        if (!$ping) {
            return [];
        }

        return [[
            'tag' => 'chat:' . $match->id,
            'titulo' => 'Aviso del rival',
            'cuerpo' => $ping->texto(),
            'url' => route('lobby'),
        ]];
    }

    /**
     * @param  list<int>  $playerIds
     * @return list<array{tag: string, titulo: string, cuerpo: string, url: string}>
     */
    private function invitaciones(array $playerIds): array
    {
        $cuantas = PartyMember::query()
            ->whereIn('player_id', $playerIds)
            ->where('is_accepted_invite', false)
            ->whereHas('party', fn ($q) => $q->where('status', 'forming'))
            ->count();

        if ($cuantas === 0) {
            return [];
        }

        return [[
            'tag' => 'party',
            'titulo' => $cuantas === 1 ? 'Te han invitado a un equipo' : 'Tienes ' . $cuantas . ' invitaciones',
            'cuerpo' => 'Entra a la arena para aceptar.',
            'url' => route('lobby'),
        ]];
    }
}
