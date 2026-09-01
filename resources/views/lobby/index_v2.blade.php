@php
    $user = auth()->user();
    // Los eliminados no aparecen: su fila solo existe para conservar el
    // historial de enfrentamientos. Recuperarlos se le pide a un admin.
    $players = $user->players()
        ->visibleToOwner()
        ->orderByDesc('is_active')
        ->orderByDesc('pl_points')
        ->orderByDesc('mmr')
        ->orderBy('character_name')
        ->get();
    $activePlayers = $players->where('is_active', true);
    $canCreateMore = $players->count() < 5;
    $featured = $players->first();

    // El rail y el escenario comparten estos datos: el escenario los pinta y el
    // rail los usa para cambiarlos sin recargar la pagina.
    $championData = $players->mapWithKeys(fn ($p) => [$p->id => [
        'name' => $p->cleanName(),
        'realm' => $p->realm,
        'realmName' => \App\Models\Player::REALMS[$p->realm] ?? ucfirst($p->realm),
        'subclass' => $p->subclass,
        'subclassName' => \App\Models\Player::SUBCLASSES[$p->subclass] ?? ucfirst($p->subclass),
        'pl' => number_format((float) $p->pl_points, 1),
        'mmr' => $p->mmr,
        'wins' => $p->wins,
        'losses' => $p->losses,
        'matches' => $p->matches_played,
        'status' => $p->statusLabel(),
        'active' => (bool) $p->is_active,
    ]]);
@endphp

@extends('layouts.arena')

@section('title', 'Lobby - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <x-arena-breadcrumbs :items="[['label' => 'Lobby']]" class="mb-6" />

    {{-- ── HERO ── --}}
    <section class="arena-panel-strong mb-6 p-6 md:p-7 arena-animate-in">
        <div class="flex flex-wrap items-start justify-between gap-6">
            <div>
                <p class="arena-kicker">Centro de operaciones</p>
                <h1 class="mt-2 text-3xl font-bold text-[color:var(--arena-gold-soft)] md:text-4xl">
                    Bienvenido, {{ $user->discord_username }}
                </h1>
                <p class="mt-2 max-w-2xl text-[color:var(--arena-sand)] arena-body-text">
                    Elige a tu guerrero, entra a la arena y revisa tu progreso en el ladder.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('queue.index', ['mode' => \App\Support\ArenaMode::default()]) }}" class="arena-btn px-5 py-2.5">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                    Buscar combate
                </a>
                <a href="{{ route('ladder.index') }}" class="arena-btn-secondary px-5 py-2.5">Ver ladder</a>
            </div>
        </div>
    </section>

    @if($players->isEmpty())
        {{-- Sin guerreros no hay nada que ensenar en 3D: lo unico que toca es
             mandar al jugador a crear el primero. --}}
        <section class="arena-panel p-8 text-center arena-animate-in arena-stagger-1">
            <p class="arena-kicker">Reclutamiento</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Todavía no tienes guerreros</h2>
            <p class="mx-auto mt-3 max-w-md text-[color:var(--arena-muted)] arena-body-text">
                Crea el primero para entrar a la arena. Eliges reino y subclase, y los ves en 3D antes de confirmar.
            </p>
            <a href="{{ route('player.create') }}" class="arena-btn mt-6 inline-flex px-6 py-3">Crear mi primer guerrero</a>
        </section>
    @else
        <div class="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)] items-start">

            {{-- ── RAÍL DE PERSONAJES ── --}}
            <section class="arena-panel p-4 arena-animate-in arena-stagger-1 order-2 lg:order-1">
                <div class="flex items-baseline justify-between gap-3 px-1 pb-3">
                    <div>
                        <p class="arena-kicker">Tu escuadra</p>
                        <h2 class="mt-1 text-lg font-semibold text-white">Guerreros</h2>
                    </div>
                    <span class="text-xs text-[color:var(--arena-muted)]">{{ $players->count() }}/5</span>
                </div>

                <div class="flex flex-col gap-2" role="listbox" aria-label="Elige tu guerrero">
                    @foreach($players as $player)
                        <button type="button"
                                role="option"
                                class="arena-roster-slot"
                                data-champion-slot
                                data-player-id="{{ $player->id }}"
                                data-realm="{{ $player->realm }}"
                                data-subclass="{{ $player->subclass }}"
                                aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                                style="--slot-realm: var(--arena-{{ $player->realm === 'ignis' ? 'fire' : ($player->realm === 'alsius' ? 'ice' : 'forest') }})">
                            <span class="arena-roster-crest">
                                <x-arena-realm-icon :realm="$player->realm" size="sm" />
                            </span>
                            <span class="min-w-0">
                                <span class="arena-roster-name">{{ $player->cleanName() }}</span>
                                <span class="arena-roster-meta">
                                    {{ \App\Models\Player::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass) }}
                                    · {{ \App\Models\Player::REALMS[$player->realm] ?? ucfirst($player->realm) }}
                                </span>
                            </span>
                            <span class="arena-roster-pl">{{ number_format((float) $player->pl_points, 1) }}</span>
                        </button>
                    @endforeach

                    @if($canCreateMore)
                        <a href="{{ route('player.create') }}" class="arena-roster-slot arena-roster-empty">
                            + Crear guerrero
                        </a>
                    @else
                        <p class="px-3 py-2 text-center text-xs text-[color:var(--arena-muted)] arena-body-text">
                            Has llenado los 5 slots.
                        </p>
                    @endif
                </div>
            </section>

            {{-- ── ESCENARIO + DETALLE ── --}}
            <div class="flex flex-col gap-5 order-1 lg:order-2">
                <x-arena-champion
                    id="lobby-stage"
                    :realm="$featured->realm"
                    :subclass="$featured->subclass"
                    height="clamp(340px, 46vh, 520px)"
                    class="arena-animate-in arena-stagger-2">

                    <div class="arena-champion-overlay">
                        <div class="arena-stats-row absolute right-4 top-4 flex flex-wrap justify-end gap-2">
                            <div class="arena-stat-pill"><span>PL</span><b data-champion-pl>—</b></div>
                            <div class="arena-stat-pill"><span>MMR</span><b data-champion-mmr>—</b></div>
                            <div class="arena-stat-pill"><span>V/D</span><b data-champion-record>—</b></div>
                        </div>

                        <div class="absolute inset-x-5 bottom-5" aria-live="polite">
                            <div class="flex flex-wrap items-center gap-3">
                                <h2 class="arena-champion-name" data-champion-name>—</h2>
                                <span class="arena-champion-status" data-champion-status hidden></span>
                            </div>
                            <p class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                                <span class="arena-champion-realm" data-champion-realm-name>—</span>
                                <span data-champion-subclass-name>—</span>
                                <span data-champion-matches>—</span>
                            </p>
                        </div>
                    </div>
                </x-arena-champion>

                {{-- Acciones del guerrero seleccionado --}}
                @foreach($players as $player)
                    <section class="arena-panel p-5 arena-animate-in arena-stagger-3"
                             data-champion-panel
                             data-player-id="{{ $player->id }}"
                             @if(!$loop->first) hidden @endif>

                        @if($player->is_active)
                            <div class="flex flex-wrap items-center gap-3">
                                <a href="{{ route('queue.index', ['mode' => \App\Support\ArenaMode::default()]) }}" class="arena-btn px-5 py-2.5">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                                    Pelear con {{ $player->cleanName() }}
                                </a>

                                <details class="arena-details">
                                    <summary>Editar nombre</summary>
                                    <form method="POST" action="{{ route('player.update', $player) }}" class="mt-4 space-y-3">
                                        @csrf
                                        @method('PUT')
                                        <label class="block">
                                            <span class="mb-2 block text-sm arena-body-text">Nombre del personaje</span>
                                            <input type="text" name="character_name" value="{{ $player->character_name }}" class="arena-field" required>
                                        </label>
                                        <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">
                                            La subclase y el reino no se pueden cambiar una vez creado el personaje.
                                        </p>
                                        <button type="submit" class="arena-btn-secondary px-4 py-2">Guardar cambios</button>
                                    </form>
                                </details>

                                @if($players->count() > 1)
                                    <button type="button" class="arena-btn-ghost px-4 py-2 text-sm" data-modal-open="modal-delete-{{ $player->id }}">
                                        Eliminar
                                    </button>
                                    <x-arena-modal :id="'modal-delete-'.$player->id" :title="'Eliminar a ' . $player->cleanName()" variant="danger">
                                        <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">
                                            @if($player->matches_played > 0)
                                                Este personaje tiene {{ $player->matches_played }} partidas registradas.
                                                Su historial de enfrentamientos se conserva para no falsear las partidas
                                                ya jugadas, pero saldrá del ranking y de este lobby, y el nombre quedará
                                                libre para volver a crearlo. Si más adelante quieres este personaje tal
                                                cual, tendrás que pedírselo a un administrador.
                                            @else
                                                Este personaje será eliminado permanentemente. No tiene partidas
                                                jugadas, así que no queda nada que conservar.
                                            @endif
                                        </p>
                                        <div class="mt-5 flex gap-3">
                                            <form method="POST" action="{{ route('player.destroy', $player) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="arena-btn-danger">Eliminar definitivamente</button>
                                            </form>
                                            <button type="button" class="arena-btn-ghost" data-modal-close="modal-delete-{{ $player->id }}">Cancelar</button>
                                        </div>
                                    </x-arena-modal>
                                @endif
                            </div>
                        @else
                            <p class="text-sm text-amber-200/80 arena-body-text">
                                Un administrador deshabilitó este personaje. Escribe al soporte del Discord
                                si crees que es un error.
                            </p>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection

@if($players->isNotEmpty())
@push('scripts')
<script>
    /* Raíl de personajes: cambiar de guerrero repinta el escenario 3D y el
       panel de acciones sin recargar la página. */
    (function () {
        var champions = @json($championData);
        var slots = document.querySelectorAll('[data-champion-slot]');
        if (!slots.length) { return; }

        var stage = document.querySelector('[data-champion-id="lobby-stage"]');

        function paint(id) {
            var c = champions[id];
            if (!c) { return; }

            document.querySelector('[data-champion-name]').textContent = c.name;
            document.querySelector('[data-champion-realm-name]').textContent = c.realmName;
            document.querySelector('[data-champion-subclass-name]').textContent = c.subclassName;
            document.querySelector('[data-champion-matches]').textContent =
                c.wins + ' victorias · ' + c.losses + ' derrotas';
            document.querySelector('[data-champion-pl]').textContent = c.pl;
            document.querySelector('[data-champion-mmr]').textContent = c.mmr;
            document.querySelector('[data-champion-record]').textContent = c.wins + '/' + c.losses;

            var status = document.querySelector('[data-champion-status]');
            status.textContent = c.status;
            status.hidden = c.active;

            if (stage) { stage.dataset.championRealm = c.realm; }

            var viewer = window.arenaChampionViewers && window.arenaChampionViewers['lobby-stage'];
            if (viewer) { viewer.set(c.realm, c.subclass); }

            document.querySelectorAll('[data-champion-panel]').forEach(function (panel) {
                panel.hidden = panel.dataset.playerId !== String(id);
            });
            slots.forEach(function (slot) {
                slot.setAttribute('aria-selected', slot.dataset.playerId === String(id) ? 'true' : 'false');
            });
        }

        slots.forEach(function (slot) {
            slot.addEventListener('click', function () { paint(slot.dataset.playerId); });
        });

        // Las cifras se pintan aquí y no en el HTML para que el escenario y el
        // raíl no puedan contar cosas distintas.
        paint(slots[0].dataset.playerId);
    })();
</script>
@endpush
@endif
