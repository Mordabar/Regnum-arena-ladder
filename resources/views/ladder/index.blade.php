@extends('layouts.arena')

@section('title', 'Ladder - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    {{-- Breadcrumbs --}}
    <x-arena-breadcrumbs :items="[['label' => 'Ladder']]" class="mb-6" />

    {{-- ── HERO ── --}}
    <section class="arena-panel-strong mb-8 p-6 md:p-8 arena-animate-in">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="arena-kicker">Ranking público</p>
                <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)]">{{ \App\Models\AppSetting::getValue('season_name', 'Alpha Season') }}</h1>
                <p class="mt-2 max-w-3xl text-[color:var(--arena-sand)] arena-body-text">{{ \App\Models\AppSetting::getValue('home_tagline', 'Conquest PvP por reino y subclase') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <x-arena-realm-icon realm="ignis" size="md" />
                <x-arena-realm-icon realm="alsius" size="md" />
                <x-arena-realm-icon realm="syrtis" size="md" />
            </div>
        </div>
    </section>

    @php
        $champions = collect($topByRealm)
            ->map(fn ($realmPlayers) => $realmPlayers->first())
            ->filter()
            ->values();
    @endphp

    @if($champions->isNotEmpty())
        {{-- El podio de los reinos. Tres figuras, no una por fila: el navegador
             solo aguanta un punado de escenarios 3D a la vez, y de todas formas
             lo que se quiere ver de un vistazo es quien manda en cada reino. --}}
        <section class="arena-panel mb-8 p-6 arena-animate-in">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="text-2xl font-semibold text-white">Quien manda en cada reino</h2>
                <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">El primero de cada tabla, ahora mismo.</p>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                @foreach($champions as $champion)
                    <a href="{{ route('ladder.show', $champion) }}"
                       class="arena-champion-podium arena-card arena-card-{{ $champion->realm }} arena-card-interactive p-4">
                        <x-arena-champion
                            :id="'podium-' . $champion->realm"
                            :realm="$champion->realm"
                            :subclass="$champion->subclass"
                            :race="$champion->race"
                            :gender="$champion->gender"
                            :parallax="false"
                            height="200px"
                            class="arena-champion-podium-stage" />
                        <div class="mt-3 min-w-0">
                            <p class="arena-kicker flex items-center gap-2">
                                <x-arena-realm-icon :realm="$champion->realm" size="xs" />
                                {{ \App\Models\Player::REALMS[$champion->realm] ?? ucfirst($champion->realm) }}
                            </p>
                            <h3 class="mt-1.5 truncate text-lg font-semibold text-white">{{ $champion->cleanName() }}</h3>
                            <p class="mt-1 flex flex-wrap items-center gap-x-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
                                <span>{{ \App\Models\Player::SUBCLASSES[$champion->subclass] ?? ucfirst($champion->subclass) }}</span>
                                <span class="font-semibold text-amber-300">{{ number_format((float) $champion->pl_points, 1) }} PL</span>
                            </p>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="mb-8 grid gap-6 xl:grid-cols-[0.75fr,1.25fr]">
        {{-- ── TOP POR REINO ── --}}
        <section class="arena-panel p-6 arena-animate-in arena-stagger-1">
            <h2 class="text-2xl font-semibold text-white">Top por reino</h2>
            <div class="mt-5 space-y-5">
                @foreach($topByRealm as $realm => $realmPlayers)
                    <div class="arena-card arena-card-{{ $realm }} p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <x-arena-realm-icon :realm="$realm" size="sm" />
                                <h3 class="font-semibold text-white">{{ \App\Models\Player::REALMS[$realm] ?? ucfirst($realm) }}</h3>
                            </div>
                            <span class="text-xs uppercase tracking-[0.22em] text-[color:var(--arena-muted)] arena-body-text">Top 5</span>
                        </div>
                        <div class="space-y-2 text-sm">
                            @forelse($realmPlayers as $index => $player)
                                <div class="arena-card arena-card-interactive flex items-center justify-between gap-3 px-3 py-2.5">
                                    <div class="flex items-center gap-3">
                                        <span class="w-6 text-center text-sm font-bold {{ $index === 0 ? 'arena-medal-1' : ($index === 1 ? 'arena-medal-2' : ($index === 2 ? 'arena-medal-3' : 'text-amber-300')) }}">
                                            #{{ $index + 1 }}
                                        </span>
                                        <a href="{{ route('ladder.show', $player) }}" class="font-medium text-white hover:text-[color:var(--arena-gold-soft)] transition-colors arena-body-text">{{ $player->character_name }}</a>
                                    </div>
                                    <span class="font-semibold text-amber-300 arena-body-text">{{ number_format((float) $player->pl_points, 1) }} PL</span>
                                </div>
                            @empty
                                <p class="text-[color:var(--arena-muted)] arena-body-text">Sin jugadores registrados.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ── TABLA GENERAL ── --}}
        <section class="arena-panel p-6 arena-animate-in arena-stagger-2">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <h2 class="text-2xl font-semibold text-white">Tabla general</h2>
                <form method="GET" class="grid gap-3 md:grid-cols-4">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Buscar personaje" class="arena-field px-4 py-2">
                    <select name="realm" class="arena-select px-4 py-2">
                        <option value="">Todos los reinos</option>
                        @foreach(\App\Models\Player::REALMS as $key => $label)
                            <option value="{{ $key }}" @selected(request('realm') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="subclass" class="arena-select px-4 py-2">
                        <option value="">Todas las subclases</option>
                        @foreach(\App\Models\Player::SUBCLASSES as $key => $label)
                            <option value="{{ $key }}" @selected(request('subclass') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="arena-btn-secondary px-4 py-2">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                        Filtrar
                    </button>
                </form>
            </div>

            {{-- Desktop table --}}
            <div class="arena-scroll mt-6 overflow-x-auto hidden md:block">
                <table class="arena-table min-w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th class="pb-3 pr-4">#</th>
                            <th class="pb-3 pr-4">Jugador</th>
                            <th class="pb-3 pr-4">Reino</th>
                            <th class="pb-3 pr-4">Subclase</th>
                            <th class="pb-3 pr-4">PL</th>
                            <th class="pb-3 pr-4">MMR</th>
                            <th class="pb-3 pr-4">W/L</th>
                            <th class="pb-3 pr-4">Partidas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach($players as $index => $player)
                            @php $rank = $players->firstItem() + $index; @endphp
                            <tr class="arena-animate-in" style="animation-delay: {{ ($index % 25) * 30 }}ms">
                                <td class="py-3 pr-4 font-bold {{ $rank <= 3 ? 'arena-medal-' . $rank : 'text-amber-300' }}">
                                    {{ $rank }}
                                </td>
                                <td class="py-3 pr-4">
                                    <a href="{{ route('ladder.show', $player) }}" class="font-medium text-white hover:text-[color:var(--arena-gold-soft)] transition-colors arena-body-text">{{ $player->character_name }}</a>
                                </td>
                                <td class="py-3 pr-4">
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-arena-realm-icon :realm="$player->realm" size="xs" />
                                        <span class="text-[color:var(--arena-text)] arena-body-text">{{ \App\Models\Player::REALMS[$player->realm] ?? ucfirst($player->realm) }}</span>
                                    </span>
                                </td>
                                <td class="py-3 pr-4 text-[color:var(--arena-text)] arena-body-text">{{ \App\Models\Player::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass) }}</td>
                                <td class="py-3 pr-4 font-semibold text-amber-300 arena-body-text">{{ number_format((float) $player->pl_points, 1) }}</td>
                                <td class="py-3 pr-4 text-sky-300 arena-body-text">{{ $player->mmr }}</td>
                                <td class="py-3 pr-4 text-[color:var(--arena-text)] arena-body-text">{{ $player->wins }}/{{ $player->losses }}</td>
                                <td class="py-3 pr-4 text-[color:var(--arena-text)] arena-body-text">{{ $player->matches_played }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list --}}
            <div class="mt-6 space-y-3 md:hidden">
                @foreach($players as $index => $player)
                    @php $rank = $players->firstItem() + $index; @endphp
                    <a href="{{ route('ladder.show', $player) }}" class="arena-card arena-card-interactive arena-card-{{ $player->realm }} block p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="w-7 text-center text-sm font-bold {{ $rank <= 3 ? 'arena-medal-' . $rank : 'text-amber-300' }}">
                                    {{ $rank }}
                                </span>
                                <x-arena-realm-icon :realm="$player->realm" size="sm" />
                                <div>
                                    <p class="font-medium text-white arena-body-text">{{ $player->character_name }}</p>
                                    <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">{{ \App\Models\Player::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass) }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold text-amber-300 arena-body-text">{{ number_format((float) $player->pl_points, 1) }} PL</p>
                                <p class="text-xs text-sky-300 arena-body-text">{{ $player->mmr }} MMR</p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $players->links() }}
            </div>
        </section>
    </div>

    {{-- ── CIERRES RECIENTES ── --}}
    <section class="arena-panel p-6 arena-animate-in arena-stagger-3">
        <h2 class="text-2xl font-semibold text-white">Cierres recientes</h2>
        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            @forelse($recentMatches as $match)
                <article class="arena-card arena-card-interactive p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-white arena-body-text">{{ $match->match_code }}</h3>
                            <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">{{ $match->zone_name }} · {{ $match->status_name }}</p>
                        </div>
                        @if($match->winner_realm)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-900/40 px-3 py-1 text-xs text-emerald-200">
                                <x-arena-realm-icon :realm="$match->winner_realm" size="xs" />
                                {{ \App\Models\ArenaMatch::REALMS[$match->winner_realm] ?? strtoupper((string) $match->winner_realm) }}
                            </span>
                        @endif
                    </div>
                </article>
            @empty
                <p class="text-[color:var(--arena-muted)] arena-body-text">Aún no hay cierres recientes.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
