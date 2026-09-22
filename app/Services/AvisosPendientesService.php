<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\MatchPing;
use App\Models\PartyMember;
use App\Models\User;
use App\Support\ArenaMode;
use Illuminate\Support\Facades\Cache;

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

    /** Un combate cerrado hace mas de esto ya no es noticia. */
    private const RESULTADO_FRESCO_MINUTOS = 10;

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

        foreach ($this->resultadoReciente($playerIds) as $aviso) {
            $avisos[] = $aviso;
        }

        return $this->conPrueba($user, $avisos);
    }

    /**
     * La prueba que se lanza al activar los avisos.
     *
     * El toque va vacio, asi que lo que se enseña es lo que diga este
     * servicio. Durante un minuto, la prueba es "lo ultimo que ha pasado" y
     * por tanto lo que el worker saca: sin esto, el push de prueba llegaba
     * como un "hay novedades" que no dice nada.
     */
    public function marcarPrueba(int $userId): void
    {
        Cache::put(self::CLAVE_PRUEBA . $userId, now()->utc()->toISOString(), now()->addMinute());
    }

    private const CLAVE_PRUEBA = 'arena:avisos:prueba:';

    private function conPrueba(User $user, array $avisos): array
    {
        $en = Cache::get(self::CLAVE_PRUEBA . $user->id);

        if (!$en) {
            return $avisos;
        }

        $avisos[] = [
            'tag' => 'arena:prueba',
            'titulo' => 'Avisos activados',
            'cuerpo' => 'Asi te llegaran los cruces, aunque cierres la pagina.',
            'url' => route('lobby'),
            'en' => $en,
            'prueba' => true,
        ];

        return $avisos;
    }

    /** La hora de un hecho, en el formato que ordena bien como texto. */
    private function cuando($fecha): string
    {
        return $fecha ? \Illuminate\Support\Carbon::parse($fecha)->utc()->toISOString() : now()->utc()->toISOString();
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
                'en' => $this->cuando($match->created_at),
                // Hay dos minutos para aceptar: este aviso no se va solo.
                'fijo' => true,
            ]];
        }

        $avisos = [[
            'tag' => 'combate:' . $match->id,
            'titulo' => '¡A pelear!',
            'cuerpo' => 'Quedad en ' . $match->zone_name . '.',
            'url' => $lobby,
            'en' => $this->cuando($match->started_at ?? $match->accepted_at ?? $match->updated_at),
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
                // El mismo titulo que pone la pagina: si llegan los dos avisos,
                // el sistema enseña uno, y tienen que decir lo mismo.
                'titulo' => 'Resultado por confirmar',
                'cuerpo' => 'El rival ya subio el suyo. Confirmalo o rechazalo.',
                'url' => $lobby,
                'en' => $this->cuando($report->created_at),
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
            'en' => $this->cuando($ping->created_at),
        ]];
    }

    /**
     * @param  list<int>  $playerIds
     * @return list<array{tag: string, titulo: string, cuerpo: string, url: string}>
     */
    private function invitaciones(array $playerIds): array
    {
        $pendientes = PartyMember::query()
            ->whereIn('player_id', $playerIds)
            ->where('is_accepted_invite', false)
            ->whereHas('party', fn ($q) => $q->where('status', 'forming'))
            ->get(['id', 'created_at']);

        if ($pendientes->isEmpty()) {
            return [];
        }

        $cuantas = $pendientes->count();

        return [[
            'tag' => 'party',
            'titulo' => $cuantas === 1 ? 'Invitacion de equipo' : 'Tienes ' . $cuantas . ' invitaciones de equipo',
            'cuerpo' => 'Entra a la arena para aceptar.',
            'url' => route('lobby'),
            'en' => $this->cuando($pendientes->max('created_at')),
        ]];
    }

    /**
     * El combate que se acaba de cerrar.
     *
     * Era el unico momento del flujo que no tenia aviso: el rival confirma
     * tu reporte con la pagina cerrada y no te enterabas de si habias subido
     * o bajado hasta volver a entrar.
     *
     * @param  list<int>  $playerIds
     * @return list<array{tag: string, titulo: string, cuerpo: string, url: string, en: string}>
     */
    private function resultadoReciente(array $playerIds): array
    {
        $match = ArenaMatch::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subMinutes(self::RESULTADO_FRESCO_MINUTOS))
            ->where(function ($q) use ($playerIds) {
                foreach ($playerIds as $id) {
                    $q->orWhere('team_a', 'like', '%"player_id":' . $id . '%')
                        ->orWhere('team_b', 'like', '%"player_id":' . $id . '%');
                }
            })
            ->latest('completed_at')
            ->get()
            ->first(fn (ArenaMatch $m) => $this->participa($m, $playerIds));

        if (!$match) {
            return [];
        }

        $miLado = null;
        foreach ($playerIds as $id) {
            $miLado = $match->getTeamSideForPlayer($id) ?: $miLado;
        }

        $cuerpo = match (true) {
            in_array($match->winner_team, [null, '', 'draw'], true) => 'Empate. El ladder ya lo ha contado.',
            $match->winner_team === $miLado => 'Victoria. El ladder ya la ha contado.',
            default => 'Derrota. El ladder ya la ha contado.',
        };

        return [[
            'tag' => 'resultado:' . $match->id,
            'titulo' => 'Resultado confirmado',
            'cuerpo' => $cuerpo,
            'url' => route('matches.index'),
            'en' => $this->cuando($match->completed_at),
        ]];
    }
}
