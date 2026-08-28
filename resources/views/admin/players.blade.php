@extends('layouts.arena')

@section('title', 'Admin Players - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <x-arena-breadcrumbs :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Jugadores'],
    ]" class="mb-6" />

    <section class="arena-panel-strong mb-8 p-6 md:p-8 arena-animate-in">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="arena-kicker">Admin</p>
                <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)]">Jugadores</h1>
            </div>
            <form method="GET" class="flex flex-wrap gap-3">
                <select name="realm" class="arena-select">
                    <option value="">Todos los reinos</option>
                    @foreach(\App\Models\Player::REALMS as $key => $label)
                        <option value="{{ $key }}" @selected(request('realm') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="status" class="arena-select">
                    <option value="">Todos</option>
                    <option value="active" @selected(request('status') === 'active')>Activos</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactivos</option>
                    <option value="locked" @selected(request('status') === 'locked')>Bloqueados</option>
                </select>
                <button type="submit" class="arena-btn-secondary">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                    Filtrar
                </button>
            </form>
        </div>
    </section>

    {{-- Create player --}}
    <details class="arena-panel mb-8 group arena-animate-in arena-stagger-1">
        <summary class="cursor-pointer p-6 flex items-center justify-between">
            <div>
                <p class="arena-kicker">Operación manual</p>
                <h2 class="mt-1 text-2xl font-semibold text-white">Crear jugador desde admin</h2>
            </div>
            <svg class="h-5 w-5 text-[color:var(--arena-muted)] transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
        </summary>
        <div class="px-6 pb-6">
            <p class="mb-4 text-sm text-[color:var(--arena-muted)] arena-body-text">Crea un usuario administrado por el panel y un personaje listo para ladder o testing.</p>
            <form method="POST" action="{{ route('admin.players.store') }}" class="grid gap-4 lg:grid-cols-4">
                @csrf
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Propietario</span>
                    <input type="text" name="owner_label" class="arena-field" value="{{ old('owner_label') }}" placeholder="Admin Managed" required>
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Email opcional</span>
                    <input type="email" name="owner_email" class="arena-field" value="{{ old('owner_email') }}" placeholder="owner@example.com">
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Personaje</span>
                    <input type="text" name="character_name" class="arena-field" value="{{ old('character_name') }}" required>
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Reino</span>
                    <select name="realm" class="arena-select" required>
                        @foreach(\App\Models\Player::REALMS as $key => $label)
                            <option value="{{ $key }}" @selected(old('realm') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Subclase</span>
                    <select name="subclass" class="arena-select" required>
                        @foreach(\App\Models\Player::SUBCLASSES as $key => $label)
                            <option value="{{ $key }}" @selected(old('subclass') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">PL inicial</span>
                    <input type="number" step="0.1" min="0" max="500" name="pl_points" class="arena-field" value="{{ old('pl_points', 0) }}">
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">MMR inicial</span>
                    <input type="number" min="100" max="5000" name="mmr" class="arena-field" value="{{ old('mmr', 800) }}">
                </label>
                <div class="flex items-end">
                    <button type="submit" class="arena-btn w-full">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/></svg>
                        Crear jugador
                    </button>
                </div>
            </form>
        </div>
    </details>

    {{-- Player list --}}
    <div class="space-y-4">
        @foreach($players as $player)
            @php
                $activeQueue = $player->queues->first();
                $isLocked = $player->queue_locked_until && $player->queue_locked_until->isFuture();
            @endphp
            <article class="arena-panel arena-card-{{ $player->realm }} p-5 arena-animate-in" style="animation-delay: {{ $loop->index * 50 }}ms">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <x-arena-realm-icon :realm="$player->realm" size="lg" />
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-xl font-semibold text-white arena-body-text">{{ $player->character_name }}</h2>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $player->is_active ? 'bg-emerald-900/30 text-emerald-300' : 'bg-white/5 text-[color:var(--arena-muted)]' }}">
                                    {{ $player->is_active ? 'Activo' : 'Inactivo' }}
                                </span>
                                @if($isLocked)
                                    <span class="rounded-full bg-rose-900/30 px-2 py-0.5 text-xs font-semibold text-rose-300">Bloqueado</span>
                                @endif
                                @if($activeQueue)
                                    <span class="rounded-full bg-sky-900/30 px-2 py-0.5 text-xs font-semibold text-sky-300">En cola</span>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                                {{ \App\Models\Player::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass) }}
                                · {{ $player->user?->discord_username }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-3 text-sm arena-body-text">
                                <span class="text-amber-300">{{ number_format((float) $player->pl_points, 1) }} PL</span>
                                <span class="text-sky-300">{{ $player->mmr }} MMR</span>
                                <span class="text-white">W/L {{ $player->wins }}/{{ $player->losses }}</span>
                                <span class="text-[color:var(--arena-muted)]">Trust {{ $player->trust_score }}</span>
                                <span class="text-[color:var(--arena-muted)]">Strikes {{ $player->penalty_strikes }}</span>
                            </div>
                            @if($isLocked)
                                <p class="mt-1 text-xs text-rose-200 arena-body-text">
                                    Hasta {{ $player->queue_locked_until->format('d/m H:i') }}
                                    @if($player->queue_lock_reason_name) · {{ $player->queue_lock_reason_name }} @endif
                                </p>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 xl:grid-cols-3">
                    <div class="arena-card p-4">
                        <p class="text-xs font-semibold text-emerald-300 arena-body-text">Rutina segura</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('admin.players.update', $player) }}">
                                @csrf
                                <input type="hidden" name="action" value="unlock_queue">
                                <button type="submit" class="arena-btn-safe px-3 py-1.5 text-xs">Desbloquear</button>
                            </form>
                            @if(!$activeQueue)
                                <form method="POST" action="{{ route('admin.players.update', $player) }}" class="flex gap-2">
                                    @csrf
                                    <input type="hidden" name="action" value="enqueue_random">
                                    @if($player->subclass === 'conjurer')
                                        <select name="conjurer_role" class="arena-select text-xs py-1.5 min-w-[100px]">
                                            <option value="offensive">Ofensivo</option>
                                            <option value="support">Soporte</option>
                                        </select>
                                    @endif
                                    <button type="submit" class="arena-btn-secondary px-3 py-1.5 text-xs">Encolar</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('admin.players.update', $player) }}">
                                @csrf
                                <input type="hidden" name="action" value="toggle_active">
                                <button type="submit" class="{{ $player->is_active ? 'arena-btn-warning' : 'arena-btn-secondary' }} px-3 py-1.5 text-xs">
                                    {{ $player->is_active ? 'Desactivar' : 'Reactivar' }}
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="arena-card p-4">
                        <p class="text-xs font-semibold text-amber-300 arena-body-text">Acciones delicadas</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('admin.players.update', $player) }}">
                                @csrf
                                <input type="hidden" name="action" value="lock_12h">
                                <button type="submit" class="arena-btn-warning px-3 py-1.5 text-xs">Bloquear 12h</button>
                            </form>
                            @if($activeQueue)
                                <form method="POST" action="{{ route('admin.players.update', $player) }}">
                                    @csrf
                                    <input type="hidden" name="action" value="remove_from_queue">
                                    <button type="submit" class="arena-btn-warning px-3 py-1.5 text-xs">Sacar de cola</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    <div class="arena-card p-4">
                        <p class="text-xs font-semibold text-rose-300 arena-body-text">Acción destructiva</p>
                        <p class="mt-1 text-xs text-[color:var(--arena-muted)] arena-body-text">
                            @if($player->matches_played > 0)
                                Elimina el personaje y purga matches, resultados y reportes ligados para limpiar ladder.
                            @else
                                Elimina el personaje y cualquier residuo administrativo seguro asociado.
                            @endif
                        </p>
                        <form method="POST" action="{{ route('admin.players.destroy', $player) }}" class="mt-3">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="arena-btn-danger px-3 py-1.5 text-xs w-full" onclick="return confirm('¿Eliminar a {{ $player->character_name }}?')">Eliminar</button>
                        </form>
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    <div class="mt-6">
        {{ $players->links() }}
    </div>
</div>
@endsection
