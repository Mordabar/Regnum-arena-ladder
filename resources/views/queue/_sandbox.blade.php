@php
    $summary = $sandbox['summary'];
    $playersByRealm = $sandbox['playersByRealm'];
    $activeQueueByPlayer = $sandbox['activeQueueByPlayer'];
    $pendingMatches = $sandbox['pendingMatches'];
    $inProgressMatches = $sandbox['inProgressMatches'];
    $recentMatches = $sandbox['recentMatches'];
    $sandboxMatchRoute = 'admin.matches.show';
@endphp

<section id="admin-testing-lab" class="arena-panel-strong mt-8 p-6 md:p-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="arena-kicker">Panel de pruebas admin</p>
            <h2 class="mt-3 text-3xl font-bold text-white md:text-4xl">Bots de prueba sobre la cola real</h2>
            <p class="mt-3 max-w-3xl text-[color:var(--arena-muted)]">
                Este panel usa las tablas reales de `users`, `players`, `queues`, `matches`, `match_reports` y `match_results`.
                Encola a tu personaje por el flujo principal y usa bots aqui para comprobar matchmaking, zona, aceptacion y scoring.
            </p>
        </div>
        <div class="rounded-2xl border px-4 py-3 text-sm {{ $sandbox['matchesSchemaReady'] ? 'border-emerald-500/30 bg-emerald-950/20 text-emerald-100' : 'border-rose-500/30 bg-rose-950/20 text-rose-100' }}">
            {{ $sandbox['matchesSchemaReady'] ? 'Esquema MVP listo' : 'Esquema MVP incompleto' }}
        </div>
    </div>

    <div class="mt-5 rounded-2xl border border-amber-500/20 bg-amber-950/20 p-4 text-sm text-amber-100">
        Usa de preferencia un personaje tuyo dedicado a testing. Si resuelves matches mixtos con bots, el ladder de ese personaje se comporta como produccion real.
    </div>

    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-7">
        <article class="arena-card p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-[color:var(--arena-muted)]">Bots</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ $summary['players'] }}</p>
        </article>
        <article class="arena-card p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-[color:var(--arena-muted)]">Libres</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ $summary['idle_players'] }}</p>
        </article>
        <article class="arena-card p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-[color:var(--arena-muted)]">Waiting</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ $summary['waiting'] }}</p>
        </article>
        <article class="arena-card p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-[color:var(--arena-muted)]">Matched</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ $summary['matched'] }}</p>
        </article>
        <article class="arena-card p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-[color:var(--arena-muted)]">Accepted</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ $summary['accepted'] }}</p>
        </article>
        <article class="arena-card p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-[color:var(--arena-muted)]">Pending</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ $summary['pending_matches'] }}</p>
        </article>
        <article class="arena-card p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-[color:var(--arena-muted)]">In Progress</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ $summary['in_progress_matches'] }}</p>
        </article>
    </div>

    <div class="mt-8 grid gap-6 xl:grid-cols-[1.05fr,0.95fr]">
        <section class="arena-panel p-5">
            <p class="arena-kicker">Paso 1</p>
            <h3 class="mt-2 text-xl font-semibold text-white">Crear o regenerar roster de bots</h3>
            <form method="POST" action="{{ route('admin.testing.seed') }}" class="mt-5 grid gap-4 md:grid-cols-4">
                @csrf
                <label class="block">
                    <span class="mb-2 block text-sm text-[color:var(--arena-text)]">Ignis</span>
                    <input type="number" name="ignis_count" min="0" max="60" value="{{ old('ignis_count', $playersByRealm->get('ignis', collect())->count()) }}" class="arena-field">
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm text-[color:var(--arena-text)]">Syrtis</span>
                    <input type="number" name="syrtis_count" min="0" max="60" value="{{ old('syrtis_count', $playersByRealm->get('syrtis', collect())->count()) }}" class="arena-field">
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm text-[color:var(--arena-text)]">Alsius</span>
                    <input type="number" name="alsius_count" min="0" max="60" value="{{ old('alsius_count', $playersByRealm->get('alsius', collect())->count()) }}" class="arena-field">
                </label>
                <div class="flex flex-col justify-end gap-3">
                    <label class="flex items-center gap-2 text-sm text-[color:var(--arena-text)]">
                        <input type="checkbox" name="replace_existing" value="1" checked class="rounded border-slate-600 bg-slate-900">
                        Reemplazar roster actual
                    </label>
                    <button type="submit" class="arena-btn">
                        Regenerar bots
                    </button>
                </div>
            </form>
        </section>

        <section class="arena-panel p-5">
            <p class="arena-kicker">Paso 2</p>
            <h3 class="mt-2 text-xl font-semibold text-white">Encolar bots para encontrar a tu personaje</h3>
            <div class="mt-5 grid gap-4 md:grid-cols-[1fr,1fr,auto]">
                <form method="POST" action="{{ route('admin.testing.enqueue-realm') }}" class="contents">
                    @csrf
                    <label class="block">
                        <span class="mb-2 block text-sm text-[color:var(--arena-text)]">Reino</span>
                        <select name="realm" class="arena-select">
                            <option value="ignis">Ignis</option>
                            <option value="syrtis">Syrtis</option>
                            <option value="alsius">Alsius</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm text-[color:var(--arena-text)]">Cantidad</span>
                        <input type="number" name="count" min="1" max="60" value="2" class="arena-field">
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm text-[color:var(--arena-text)]">Modalidad</span>
                        <select name="arena_mode" class="arena-select">
                            @foreach(\App\Support\ArenaMode::enabled() as $sandboxMode)
                                <option value="{{ $sandboxMode }}">{{ $sandboxMode }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex items-end">
                        <button type="submit" class="arena-btn-secondary w-full">
                            Encolar por reino
                        </button>
                    </div>
                </form>
            </div>
            <p class="mt-3 text-sm text-[color:var(--arena-muted)]">
                @php
                    // Se arma el texto en PHP en vez de con directivas Blade sueltas:
                    // una directiva pegada a una palabra (rival@if) no se compila y
                    // deja un @else huerfano que rompe la pagina entera al renderizar.
                    $sandboxNeeds = collect(\App\Support\ArenaMode::enabled())
                        ->map(function (string $mode) {
                            $size = \App\Support\ArenaMode::teamSize($mode);

                            return $mode . ': ' . ($size - 1) . ' bot(s) de tu reino y ' . $size . ' del reino rival';
                        })
                        ->implode('; ');
                @endphp
                Para testear un match de tu personaje necesitas, segun la modalidad que elijas arriba
                @if($sandboxNeeds !== '')
                    <strong class="text-white">{{ $sandboxNeeds }}</strong>.
                @else
                    <strong class="text-white">activar alguna modalidad primero</strong>.
                @endif
                Se hace en 2 pasos separados.
            </p>
            <div class="mt-5 flex flex-wrap gap-3">
                <form method="POST" action="{{ route('admin.testing.process') }}">
                    @csrf
                    <button type="submit" class="arena-btn-secondary">
                        Ejecutar matchmaking
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.testing.accept') }}">
                    @csrf
                    <button type="submit" class="arena-btn-ghost">
                        Aceptar match pendientes
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.testing.accept-parties') }}">
                    @csrf
                    <button type="submit" class="arena-btn-ghost text-amber-500 hover:text-amber-400">
                        Aceptar Invitaciones Party
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.testing.invite-me') }}" class="flex items-center gap-2">
                    @csrf
                    <select name="arena_mode" class="arena-select w-auto">
                        @foreach(\App\Support\ArenaMode::enabled() as $sandboxMode)
                            <option value="{{ $sandboxMode }}">{{ $sandboxMode }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="arena-btn-ghost text-purple-400 hover:text-purple-300">
                        Hacer que un bot me invite
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.testing.resolve-all') }}">
                    @csrf
                    <button type="submit" class="arena-btn-ghost">
                        Resolver matches con bots
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.testing.reset') }}">
                    @csrf
                    <button type="submit" class="arena-btn-ghost">
                        Resetear panel
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.testing.destroy') }}" onsubmit="return confirm('Esto elimina los bots de prueba si no existen matches mixtos con personajes reales.');">
                    @csrf
                    <button type="submit" class="arena-btn-ghost">
                        Eliminar panel
                    </button>
                </form>
            </div>
        </section>
    </div>

    <div class="mt-8 grid gap-6 xl:grid-cols-[1.1fr,0.9fr]">
        <section class="arena-panel p-5">
            <p class="arena-kicker">Paso 3</p>
            <h3 class="mt-2 text-xl font-semibold text-white">Roster manual de bots</h3>
            <div class="mt-5 space-y-4">
                @forelse($playersByRealm as $realm => $realmPlayers)
                    <details class="arena-card p-4" open>
                        <summary class="cursor-pointer list-none text-lg font-semibold text-white">
                            {{ \App\Models\Player::REALMS[$realm] ?? ucfirst($realm) }}
                            <span class="ml-2 text-sm font-normal text-[color:var(--arena-muted)]">{{ $realmPlayers->count() }} bots</span>
                        </summary>
                        <div class="mt-4 grid gap-3">
                            @foreach($realmPlayers as $botPlayer)
                                @php($botQueue = $activeQueueByPlayer->get($botPlayer->id))
                                <article class="arena-card px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <h4 class="font-semibold text-white">{{ $botPlayer->character_name }}</h4>
                                            <p class="text-sm text-[color:var(--arena-muted)]">
                                                {{ \App\Models\Player::SUBCLASSES[$botPlayer->subclass] ?? ucfirst($botPlayer->subclass) }}
                                                - {{ number_format((float) $botPlayer->pl_points, 1) }} PL
                                                - {{ $botPlayer->mmr }} MMR
                                            </p>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="arena-chip">
                                                {{ $botQueue ? ucfirst($botQueue->status) : 'Libre' }}
                                            </span>
                                            <form method="POST" action="{{ route('admin.testing.toggle-bot') }}">
                                                @csrf
                                                <input type="hidden" name="player_id" value="{{ $botPlayer->id }}">
                                                <select name="arena_mode" class="arena-select text-xs py-1 w-auto">
                                                    @foreach(\App\Support\ArenaMode::enabled() as $sandboxMode)
                                                        <option value="{{ $sandboxMode }}">{{ $sandboxMode }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="{{ $botQueue && $botQueue->status === 'waiting' && !$botQueue->match_id ? 'arena-btn-ghost' : 'arena-btn-secondary' }} text-sm">
                                                    {{ $botQueue && $botQueue->status === 'waiting' && !$botQueue->match_id ? 'Sacar' : 'Encolar' }}
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </details>
                @empty
                    <div class="arena-card p-5 text-[color:var(--arena-muted)]">
                        Aun no hay bots en este sandbox. Genera un roster arriba y luego usa los botones de cola.
                    </div>
                @endforelse
            </div>
        </section>

        <section class="space-y-6">
            <section class="arena-panel p-5">
                <p class="arena-kicker">Paso 4</p>
                <h3 class="mt-2 text-xl font-semibold text-white">Pending con bots</h3>
                <div class="mt-4 space-y-3">
                    @forelse($pendingMatches as $match)
                        <article class="arena-card px-4 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-white">{{ $match->match_code }}</p>
                                    <p class="text-sm text-[color:var(--arena-muted)]">{{ $match->zone_name }} - {{ ucfirst($match->team_a_realm) }} vs {{ ucfirst($match->team_b_realm) }}</p>
                                </div>
                                <form method="POST" action="{{ route('admin.testing.accept') }}">
                                    @csrf
                                    <input type="hidden" name="match_id" value="{{ $match->id }}">
                                    <button type="submit" class="arena-btn-secondary text-sm">
                                        Aceptar bots
                                    </button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <p class="arena-card p-4 text-[color:var(--arena-muted)]">Sin pending con bots por ahora.</p>
                    @endforelse
                </div>
            </section>

            <section class="arena-panel p-5">
                <p class="arena-kicker">Paso 5</p>
                <h3 class="mt-2 text-xl font-semibold text-white">In progress con bots</h3>
                <div class="mt-4 space-y-3">
                    @forelse($inProgressMatches as $match)
                        <article class="arena-card px-4 py-4">
                            <div>
                                <p class="font-semibold text-white">{{ $match->match_code }}</p>
                                <p class="text-sm text-[color:var(--arena-muted)]">{{ $match->zone_name }} - {{ ucfirst($match->team_a_realm) }} vs {{ ucfirst($match->team_b_realm) }}</p>
                            </div>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('admin.testing.resolve', $match) }}">
                                    @csrf
                                    <input type="hidden" name="winner_team" value="team_a">
                                    <button type="submit" class="arena-btn-secondary text-sm">
                                        Gana {{ ucfirst($match->team_a_realm) }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.testing.resolve', $match) }}">
                                    @csrf
                                    <input type="hidden" name="winner_team" value="team_b">
                                    <button type="submit" class="arena-btn-secondary text-sm">
                                        Gana {{ ucfirst($match->team_b_realm) }}
                                    </button>
                                </form>
                                <a href="{{ route($sandboxMatchRoute, $match) }}" class="arena-btn-ghost text-sm">
                                    Abrir match
                                </a>
                            </div>
                        </article>
                    @empty
                        <p class="arena-card p-4 text-[color:var(--arena-muted)]">Sin matches en progreso con bots.</p>
                    @endforelse
                </div>
            </section>

            <section class="arena-panel p-5">
                <p class="arena-kicker">Paso 6</p>
                <h3 class="mt-2 text-xl font-semibold text-white">Historial reciente del panel</h3>
                <div class="mt-4 space-y-3">
                    @forelse($recentMatches as $match)
                        <article class="arena-card px-4 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-white">{{ $match->match_code }}</p>
                                    <p class="text-sm text-[color:var(--arena-muted)]">{{ $match->status_name }} - {{ $match->zone_name }}</p>
                                </div>
                                <a href="{{ route($sandboxMatchRoute, $match) }}" class="arena-btn-ghost text-sm">
                                    Ver
                                </a>
                            </div>
                        </article>
                    @empty
                        <p class="arena-card p-4 text-[color:var(--arena-muted)]">Todavia no hay matches relacionados con bots.</p>
                    @endforelse
                </div>
            </section>
        </section>
    </div>
</section>
