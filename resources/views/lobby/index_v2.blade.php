@php
    $user = auth()->user();
    $players = $user->players()
        ->orderByDesc('is_active')
        ->orderByDesc('pl_points')
        ->orderByDesc('mmr')
        ->orderBy('character_name')
        ->get();
    $activePlayers = $players->where('is_active', true);
    $canCreateMore = $players->count() < 5;
@endphp

@extends('layouts.arena')

@section('title', 'Lobby - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    {{-- Breadcrumbs --}}
    <x-arena-breadcrumbs :items="[['label' => 'Lobby']]" class="mb-6" />

    {{-- ── HERO ── --}}
    <section class="arena-panel-strong mb-8 p-6 md:p-8 arena-animate-in">
        <div class="flex flex-wrap items-start justify-between gap-6">
            <div>
                <p class="arena-kicker">Centro de operaciones</p>
                <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)]">Bienvenido, {{ $user->discord_username }}</h1>
                <p class="mt-3 max-w-3xl text-[color:var(--arena-sand)] arena-body-text">
                    Administra tus guerreros, entra a la arena y revisa tu progreso en el ladder.
                </p>
            </div>

            <div class="grid min-w-[240px] gap-3 sm:grid-cols-2">
                <a href="{{ route('queue.index', ['mode' => \App\Support\ArenaMode::default()]) }}" class="arena-btn text-center">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                    Buscar combate
                </a>
                <a href="{{ route('ladder.index') }}" class="arena-btn-secondary text-center">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M5 3a1 1 0 000 2c5.523 0 10 4.477 10 10a1 1 0 102 0C17 8.373 11.627 3 5 3z"/><path d="M4 9a1 1 0 011-1 7 7 0 017 7 1 1 0 11-2 0 5 5 0 00-5-5 1 1 0 01-1-1zM3 15a2 2 0 114 0 2 2 0 01-4 0z"/></svg>
                    Ver ladder
                </a>
            </div>
        </div>
    </section>

    {{-- ── STATS ── --}}
    <div class="mb-8 grid gap-4 grid-cols-2 md:grid-cols-3">
        <article class="arena-card arena-animate-in arena-stagger-1 p-5">
            <p class="arena-kicker">Cuenta</p>
            <h2 class="mt-2 text-xl font-semibold text-white">{{ $user->discord_username }}</h2>
            <p class="mt-1 text-xs text-[color:var(--arena-muted)] arena-body-text">Miembro desde {{ $user->created_at->format('d/m/Y') }}</p>
        </article>
        <article class="arena-card arena-animate-in arena-stagger-2 p-5">
            <p class="arena-kicker">Guerreros</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ $players->count() }}<span class="text-lg text-[color:var(--arena-muted)]">/5</span></p>
            <p class="mt-1 text-xs text-[color:var(--arena-muted)] arena-body-text">{{ $activePlayers->count() }} activos</p>
        </article>
        <article class="arena-card arena-animate-in arena-stagger-3 p-5">
            <p class="arena-kicker">Formato</p>
            <div class="mt-2 flex items-center gap-2">
                <p class="text-3xl font-semibold text-white">{{ implode(' · ', \App\Support\ArenaMode::enabled()) ?: '—' }}</p>
                <div class="flex items-center gap-1">
                    <x-arena-realm-icon realm="ignis" size="xs" />
                    <x-arena-realm-icon realm="alsius" size="xs" />
                    <x-arena-realm-icon realm="syrtis" size="xs" />
                </div>
            </div>
            <p class="mt-1 text-xs text-[color:var(--arena-muted)] arena-body-text">Random y premade por reino</p>
        </article>
    </div>

    {{-- ── ROSTER + CREATE ── --}}
    <div class="grid gap-8 xl:grid-cols-[1.15fr,0.85fr]">
        <section class="arena-panel p-6 arena-animate-in arena-stagger-3">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="arena-kicker">Tu escuadra</p>
                    <h2 class="mt-1 text-2xl font-semibold text-white">Tus guerreros</h2>
                </div>
                <span class="arena-chip">
                    {{ $players->count() }}/5 slots usados
                </span>
            </div>

            @if($players->isEmpty())
                <div class="arena-card mt-6 p-5 text-center text-[color:var(--arena-muted)] arena-body-text">
                    <svg class="mx-auto h-10 w-10 text-[color:var(--arena-gold)] opacity-40" viewBox="0 0 20 20" fill="currentColor"><path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/></svg>
                    <p class="mt-3">Todavía no tienes guerreros. Crea el primero para entrar a la arena.</p>
                </div>
            @else
                <div class="mt-6 space-y-4">
                    @foreach($players as $player)
                        <article class="arena-card arena-card-{{ $player->realm }} p-5 {{ $player->is_active ? '' : 'opacity-60' }}">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="flex items-start gap-3">
                                    <x-arena-realm-icon :realm="$player->realm" size="lg" />
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-xl font-semibold text-white">{{ $player->character_name }}</h3>
                                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $player->is_active ? 'bg-emerald-900/40 text-emerald-200' : 'bg-amber-900/40 text-amber-200' }}">
                                                {{ $player->is_active ? 'Activo' : 'Inactivo' }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                                            {{ \App\Models\Player::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass) }}
                                            · {{ \App\Models\Player::REALMS[$player->realm] ?? ucfirst($player->realm) }}
                                        </p>
                                    </div>
                                </div>

                                @if($player->is_active)
                                    <a href="{{ route('queue.index', ['mode' => \App\Support\ArenaMode::default()]) }}" class="arena-btn px-4 py-2 text-sm">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                                        Pelear
                                    </a>
                                @endif
                            </div>

                            <div class="mt-4 grid gap-3 grid-cols-2 sm:grid-cols-4">
                                <div class="arena-card px-4 py-3">
                                    <p class="text-[0.65rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">PL</p>
                                    <p class="mt-1 text-lg font-semibold text-amber-300">{{ number_format((float) $player->pl_points, 1) }}</p>
                                </div>
                                <div class="arena-card px-4 py-3">
                                    <p class="text-[0.65rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">MMR</p>
                                    <p class="mt-1 text-lg font-semibold text-sky-300">{{ $player->mmr }}</p>
                                </div>
                                <div class="arena-card px-4 py-3">
                                    <p class="text-[0.65rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">W/L</p>
                                    <p class="mt-1 text-lg font-semibold text-white">{{ $player->wins }}/{{ $player->losses }}</p>
                                </div>
                                <div class="arena-card px-4 py-3">
                                    <p class="text-[0.65rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">Partidas</p>
                                    <p class="mt-1 text-lg font-semibold text-white">{{ $player->matches_played }}</p>
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                @if($player->is_active)
                                    <details class="arena-card min-w-[260px] p-4 group">
                                        <summary class="cursor-pointer text-sm font-semibold text-white arena-body-text flex items-center gap-2">
                                            <svg class="h-4 w-4 text-[color:var(--arena-muted)] transition-transform group-open:rotate-90" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                                            Editar nombre
                                        </summary>
                                        <form method="POST" action="{{ route('player.update', $player) }}" class="mt-4 space-y-4">
                                            @csrf
                                            @method('PUT')
                                            <label class="block">
                                                <span class="mb-2 block text-sm text-[color:var(--arena-text)] arena-body-text">Nombre del personaje</span>
                                                <input type="text" name="character_name" value="{{ $player->character_name }}" class="arena-field" required>
                                            </label>
                                            <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">La subclase y el reino no se pueden cambiar una vez creado el personaje.</p>
                                            <button type="submit" class="arena-btn-secondary px-4 py-2">Guardar cambios</button>
                                        </form>
                                    </details>

                                    @if($players->count() > 1)
                                        <button type="button" class="arena-btn-ghost px-4 py-2 text-sm" data-modal-open="modal-deactivate-{{ $player->id }}">
                                            {{ $player->matches_played > 0 ? 'Desactivar' : 'Eliminar' }}
                                        </button>
                                        <x-arena-modal :id="'modal-deactivate-'.$player->id" :title="($player->matches_played > 0 ? 'Desactivar' : 'Eliminar') . ' a ' . $player->character_name" variant="danger">
                                            <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">
                                                @if($player->matches_played > 0)
                                                    Este personaje tiene {{ $player->matches_played }} partidas registradas. Se desactivará pero no se eliminarán sus estadísticas.
                                                @else
                                                    Este personaje será eliminado permanentemente.
                                                @endif
                                            </p>
                                            <div class="mt-5 flex gap-3">
                                                <form method="POST" action="{{ route('player.destroy', $player) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="arena-btn-danger">Confirmar</button>
                                                </form>
                                                <button type="button" class="arena-btn-ghost" data-modal-close="modal-deactivate-{{ $player->id }}">Cancelar</button>
                                            </div>
                                        </x-arena-modal>
                                    @endif
                                @else
                                    <form method="POST" action="{{ route('player.reactivate', $player) }}">
                                        @csrf
                                        <button type="submit" class="arena-btn-secondary px-4 py-2 text-sm">Reactivar guerrero</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="space-y-8">
            @if($canCreateMore)
                <section class="arena-panel p-6 arena-animate-in arena-stagger-4">
                    <p class="arena-kicker">Reclutamiento</p>
                    <h2 class="mt-1 text-2xl font-semibold text-white">Crear guerrero</h2>
                    <p class="mt-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
                        Elige nombre, subclase y reino. Si es conjurador, el rol (soporte u ofensivo) se declara al entrar a la cola.
                    </p>

                    <form method="POST" action="{{ route('player.register') }}" class="mt-6 space-y-4">
                        @csrf
                        <label class="block">
                            <span class="mb-2 block text-sm text-[color:var(--arena-text)] arena-body-text">Nombre del personaje</span>
                            <input type="text" name="character_name" value="{{ old('character_name') }}" class="arena-field" placeholder="Ej: SarKhan4651" required>
                        </label>

                        <label class="block">
                            <span class="mb-2 block text-sm text-[color:var(--arena-text)] arena-body-text">Subclase</span>
                            <select name="subclass" class="arena-select" required>
                                <option value="">Selecciona una subclase</option>
                                @foreach(\App\Models\Player::SUBCLASSES as $key => $name)
                                    <option value="{{ $key }}" @selected(old('subclass') === $key)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <fieldset class="block">
                            <legend class="block mb-3 text-sm text-[color:var(--arena-text)] arena-body-text">Elige tu Reino</legend>
                            <div class="grid grid-cols-3 gap-3">
                                @foreach(\App\Models\Player::REALMS as $key => $name)
                                    <label class="relative cursor-pointer group">
                                        <input type="radio" name="realm" value="{{ $key }}" class="peer sr-only" required @checked(old('realm') === $key)>
                                        <div class="flex flex-col items-center gap-2 rounded-xl border border-[color:var(--arena-line)] bg-black/40 p-3 pb-4 text-center transition-all peer-checked:bg-[color:var(--arena-gold)]/10 peer-checked:shadow-[0_0_15px_rgba(216,177,92,0.15)] hover:border-white/30 
                                            {{ $key === 'ignis' ? 'peer-checked:border-[color:var(--arena-fire)]' : ($key === 'alsius' ? 'peer-checked:border-[color:var(--arena-ice)]' : 'peer-checked:border-[color:var(--arena-forest)]') }}">
                                            <x-arena-realm-icon :realm="$key" size="md" />
                                            <span class="text-xs font-semibold text-[color:var(--arena-muted)] peer-checked:text-white transition-colors">{{ $name }}</span>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <button type="submit" class="arena-btn-secondary w-full">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/></svg>
                            Registrar guerrero
                        </button>
                    </form>
                </section>
            @endif
        </div>
    </div>
</div>
@endsection
