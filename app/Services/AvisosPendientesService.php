<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\MatchPing;
use App\Models\PartyMember;
use App\Models\Player;
use App\Models\User;
use App\Support\ArenaMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 * Cada aviso lleva:
 *   - `tag`: el navegador agrupa por ella. Es UNA por hecho y la misma que usa
 *     la pagina, asi que si llegan los dos avisos sale uno, y un hecho nuevo
 *     del mismo combate sustituye al anterior (la cancelacion pisa el "rival
 *     encontrado" en vez de dejarlo colgado).
 *   - `en`: cuando paso. El worker enseña SOLO el mas reciente, que es el que
 *     provoco el toque.
 *
 * Las URL van relativas: con absolutas, un APP_URL que no coincidiera con el
 * dominio real (con o sin www, http o https) mandaba al jugador a otro origen,
 * sin sesion.
 */
class AvisosPendientesService
{
    /** Un aviso del chat mas viejo que esto ya no se anuncia. */
    private const PING_FRESCO_MINUTOS = 3;

    /** Un combate cerrado hace mas de esto ya no es noticia. */
    private const RESULTADO_FRESCO_MINUTOS = 10;

    /** Cuanto se recuerda un hecho que ya no esta en la base (un cruce borrado). */
    private const HECHO_MINUTOS = 10;

    private const CLAVE_PRUEBA = 'arena:avisos:prueba:';
    private const CLAVE_HECHOS = 'arena:avisos:hechos:';

    /**
     * @return list<array{tag: string, titulo: string, cuerpo: string, url: string, en: string}>
     */
    public function para(User $user): array
    {
        $playerIds = $user->players()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $avisos = $playerIds === [] ? [] : array_merge(
            $this->invitaciones($playerIds),
            $this->delEnfrentamientoEnCurso($playerIds),
            $this->resultadoReciente($playerIds),
        );

        $avisos = array_merge($avisos, $this->sinHechosTapados($avisos, $this->hechosRecientes((int) $user->id)));

        return $this->conPrueba($user, $avisos);
    }

    /* ── Hechos que ya no estan en la base ─────────────────────────────── */

    /**
     * Apunta un hecho para que el worker lo encuentre.
     *
     * Hay cosas que, cuando el toque llega, ya no estan en la base: un cruce
     * que nadie acepto SE BORRA. Sin esto, el push de "cruce cancelado"
     * preguntaba que habia pasado y la respuesta era nada, y en pantalla se
     * quedaba para siempre el "rival encontrado, tienes que aceptar".
     *
     * @param  iterable<int|array{player_id?: int|string}>  $jugadores
     * @param  list<int>  $exceptoPlayerIds
     */
    public function registrarHecho(iterable $jugadores, string $tag, string $titulo, string $cuerpo, array $exceptoPlayerIds = [], ?string $url = null): void
    {
        $playerIds = collect($jugadores)
            ->map(fn ($j) => (int) (is_array($j) ? ($j['player_id'] ?? 0) : $j))
            ->filter()
            ->reject(fn (int $id) => in_array($id, $exceptoPlayerIds, true))
            ->unique();

        if ($playerIds->isEmpty()) {
            return;
        }

        $hecho = [
            'tag' => $tag,
            'titulo' => $titulo,
            'cuerpo' => $cuerpo,
            'url' => $url ?? route('lobby', [], false),
            'en' => now()->utc()->toISOString(),
        ];

        foreach (Player::query()->whereIn('id', $playerIds)->pluck('user_id')->filter()->unique() as $userId) {
            $clave = self::CLAVE_HECHOS . $userId;

            // El mismo tag sustituye al anterior: un combate tiene un solo
            // estado en cada momento.
            $lista = collect(Cache::get($clave, []))
                ->reject(fn (array $h) => $h['tag'] === $tag)
                ->push($hecho)
                ->take(-5)
                ->values()
                ->all();

            Cache::put($clave, $lista, now()->addMinutes(self::HECHO_MINUTOS));
        }
    }

    /**
     * Un hecho viejo no puede tapar un cruce vivo.
     *
     * Al rechazar un cruce se apunta "Cruce cancelado" y, en la misma
     * peticion, el emparejador ya puede haber metido al jugador en otro. Los
     * dos llevan la hora del mismo segundo -la base la guarda sin fraccion,
     * el hecho con ella- y el worker, que enseña solo el mas reciente,
     * elegia la cancelacion: el "Rival encontrado" nuevo no salia y el cruce
     * caducaba sin que nadie se enterara. Si hay un cruce o combate vivo de
     * OTRO enfrentamiento, los hechos de los demas ya no son noticia.
     */
    private function sinHechosTapados(array $avisos, array $hechos): array
    {
        $vivos = collect($avisos)
            ->pluck('tag')
            ->filter(fn ($tag) => preg_match('/^(cruce|combate):/', (string) $tag))
            ->map(fn ($tag) => substr((string) $tag, strpos((string) $tag, ':') + 1))
            ->unique();

        if ($vivos->isEmpty()) {
            return $hechos;
        }

        return array_values(array_filter($hechos, function (array $hecho) use ($vivos) {
            $id = substr((string) $hecho['tag'], strpos((string) $hecho['tag'], ':') + 1);

            return $vivos->contains($id);
        }));
    }

    private function hechosRecientes(int $userId): array
    {
        $limite = now()->subMinutes(self::HECHO_MINUTOS)->utc()->toISOString();

        return collect(Cache::get(self::CLAVE_HECHOS . $userId, []))
            ->filter(fn (array $h) => ($h['en'] ?? '') >= $limite)
            ->values()
            ->all();
    }

    /* ── La prueba de ida y vuelta ─────────────────────────────────────── */

    /**
     * La prueba que se lanza al activar los avisos: durante un minuto es "lo
     * ultimo que ha pasado", y por tanto lo que el worker enseña.
     */
    public function marcarPrueba(int $userId): void
    {
        Cache::put(self::CLAVE_PRUEBA . $userId, now()->utc()->toISOString(), now()->addMinute());
    }

    private function conPrueba(User $user, array $avisos): array
    {
        $en = Cache::get(self::CLAVE_PRUEBA . $user->id);

        if ($en) {
            $avisos[] = [
                'tag' => 'arena:prueba',
                'titulo' => 'Avisos activados',
                'cuerpo' => 'Asi te llegaran los cruces, aunque cierres la pagina.',
                'url' => route('lobby', [], false),
                'en' => $en,
                'prueba' => true,
            ];
        }

        return $avisos;
    }

    /* ── El enfrentamiento en curso ────────────────────────────────────── */

    /**
     * Los enfrentamientos vivos de estos jugadores.
     *
     * SIN `LIKE` sobre las columnas de alineacion. Son JSON, y en MySQL una
     * columna JSON se guarda normalizada -`"player_id": 12`, con espacio-, asi
     * que un `LIKE '%"player_id":12%'` no casaba NUNCA y todos los avisos
     * salian genericos. Los enfrentamientos vivos son pocos a la vez: se
     * traen por estado y se filtra en PHP, que no depende de como guarde el
     * JSON cada base de datos.
     *
     * @param  list<int>  $playerIds
     * @return Collection<int, ArenaMatch>
     */
    private function enfrentamientosDe(array $playerIds, array $estados, ?Carbon $desde = null, string $campoFecha = 'updated_at'): Collection
    {
        return ArenaMatch::query()
            ->whereIn('status', $estados)
            ->when($desde, fn ($q) => $q->where($campoFecha, '>=', $desde))
            ->latest('id')
            ->limit(200)
            ->get()
            ->filter(fn (ArenaMatch $m) => $this->participa($m, $playerIds))
            ->values();
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

    /** @param  list<int>  $playerIds */
    private function miLado(ArenaMatch $match, array $playerIds): ?string
    {
        foreach ($playerIds as $id) {
            if ($lado = $match->getTeamSideForPlayer($id)) {
                return $lado;
            }
        }

        return null;
    }

    /** @param  list<int>  $playerIds */
    private function delEnfrentamientoEnCurso(array $playerIds): array
    {
        $match = $this->enfrentamientosDe($playerIds, ['pending_acceptance', 'in_progress'])->first();

        if (!$match) {
            return [];
        }

        $lobby = route('lobby', [], false);
        $avisos = [];

        if ($match->status === 'pending_acceptance') {
            // Si ya caduco no se anuncia: el jugador abriria el sitio para
            // encontrarse con que no hay nada, que es peor que no avisar.
            if (!$match->isExpired()) {
                $avisos[] = [
                    'tag' => 'cruce:' . $match->id,
                    'titulo' => 'Rival encontrado',
                    'cuerpo' => ArenaMode::displayName($match->arena_mode) . '. Tienes que aceptar para que empiece.',
                    'url' => $lobby,
                    'en' => $this->cuando($match->created_at),
                    // Hay dos minutos para aceptar: este aviso no se va solo.
                    'fijo' => true,
                ];
            }
        } else {
            $avisos[] = [
                'tag' => 'combate:' . $match->id,
                'titulo' => '¡A pelear!',
                'cuerpo' => 'Quedad en ' . $match->zone_name . '.',
                'url' => $lobby,
                'en' => $this->cuando($match->started_at ?? $match->accepted_at ?? $match->updated_at),
            ];

            // El reporte del rival esperando respuesta: es lo unico del flujo
            // que se queda parado hasta que alguien lo mira.
            $report = $match->report;
            $miLado = $this->miLado($match, $playerIds);

            if ($report && $report->status === 'pending_confirmation' && $miLado && $report->reporting_team !== $miLado) {
                $avisos[] = [
                    'tag' => 'reporte:' . $match->id,
                    // El mismo titulo que pone la pagina: si llegan los dos
                    // avisos, el sistema enseña uno, y tienen que decir lo mismo.
                    'titulo' => 'Resultado por confirmar',
                    'cuerpo' => 'El rival ya subio el suyo. Confirmalo o rechazalo.',
                    'url' => $lobby,
                    'en' => $this->cuando($report->created_at),
                ];
            }
        }

        // El chat funciona tambien mientras se acepta: un "voy de camino" en
        // ese momento tiene que llegar como lo que es, no volver a sonar como
        // "rival encontrado".
        return array_merge($avisos, $this->ultimoAvisoDelChat($match, $playerIds));
    }

    /**
     * Lo ultimo que se dijo por el chat, si es reciente y no lo dije yo.
     *
     * Con el titulo de quien lo dijo: en 2v2 y 3v3 el compañero tambien
     * escribe, y un "estoy en el punto" de tu compañero anunciado como "aviso
     * del rival" es informacion tactica falsa. Sin nombres: el rival es
     * anonimo hasta que el combate se cierra.
     *
     * @param  list<int>  $playerIds
     */
    private function ultimoAvisoDelChat(ArenaMatch $match, array $playerIds): array
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

        $miLado = $this->miLado($match, $playerIds);
        $suLado = $match->getTeamSideForPlayer((int) $ping->player_id);
        $esMiEquipo = $miLado !== null && $miLado === $suLado;

        return [[
            'tag' => 'chat:' . $match->id,
            'titulo' => $esMiEquipo ? 'Aviso de tu equipo' : 'Aviso del rival',
            'cuerpo' => $ping->texto(),
            'url' => route('lobby', [], false),
            'en' => $this->cuando($ping->created_at),
        ]];
    }

    /**
     * El combate que se acaba de cerrar, con victoria, derrota o empate.
     *
     * Era el unico momento del flujo que no tenia aviso: el rival confirma tu
     * reporte con la pagina cerrada y no te enterabas de si habias subido o
     * bajado hasta volver a entrar.
     *
     * @param  list<int>  $playerIds
     */
    private function resultadoReciente(array $playerIds): array
    {
        $match = $this->enfrentamientosDe(
            $playerIds,
            ['completed'],
            now()->subMinutes(self::RESULTADO_FRESCO_MINUTOS),
            'completed_at'
        )->sortByDesc('completed_at')->first();

        if (!$match) {
            return [];
        }

        $miLado = $this->miLado($match, $playerIds);

        $cuerpo = match (true) {
            in_array($match->winner_team, [null, '', 'draw'], true) => 'Empate. El ladder ya lo ha contado.',
            $match->winner_team === $miLado => 'Victoria. El ladder ya la ha contado.',
            default => 'Derrota. El ladder ya la ha contado.',
        };

        return [[
            'tag' => 'resultado:' . $match->id,
            'titulo' => 'Resultado confirmado',
            'cuerpo' => $cuerpo,
            'url' => route('matches.index', [], false),
            'en' => $this->cuando($match->completed_at),
        ]];
    }

    /** @param  list<int>  $playerIds */
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
            'url' => route('lobby', [], false),
            'en' => $this->cuando($pendientes->max('created_at')),
        ]];
    }

    /** La hora de un hecho, en el formato que ordena bien como texto. */
    private function cuando($fecha): string
    {
        return $fecha ? Carbon::parse($fecha)->utc()->toISOString() : now()->utc()->toISOString();
    }
}
