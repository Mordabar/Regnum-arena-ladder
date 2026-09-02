@extends('layouts.arena')

@php
    use App\Models\Player as PlayerModel;
    use App\Support\ArenaMode;

    $hasRoster = $players->isNotEmpty();
    $hasActiveState = (bool) ($currentQueue || $currentMatch);
    $modesAreOpen = !empty($enabledModes);
    // Ojo: $canJoinQueue NO depende de $modesAreOpen. El bloque de abajo tambien
    // tiene las invitaciones y el panel de party (con "Abandonar Party"), y con
    // las modalidades apagadas el jugador igual tiene que poder salir.
    $canJoinQueue = $hasRoster && !$hasActiveState;
    $activePartyMode = $activeParty->arena_mode ?? null;
    $activePartyModeIsOpen = $activeParty ? ArenaMode::isEnabled($activePartyMode) : false;
    $queueTypeLabel = $currentQueue
        ? trim((\App\Models\Queue::QUEUE_TYPES[$currentQueue->queue_type] ?? ucfirst($currentQueue->queue_type)) . ' ' . ArenaMode::label($currentQueue->arena_mode))
        : null;
    $premadeSlots = range(2, $teamSize);
    $queueReportPendingConfirmation = $currentMatch?->report && $currentMatch->report->status === 'pending_confirmation';
    $shouldPoll = $hasRoster;
    $shouldAutoRefresh = $hasActiveState || $activeParty || $pendingInvites->isNotEmpty();

    $stepperCurrent = match(true) {
        !$hasRoster => 1,
        $canJoinQueue => 2,
        (bool)($currentMatch && $currentMatch->status === 'pending_acceptance') => 3,
        (bool)$currentQueue && !$currentMatch => 3,
        (bool)$currentMatch && $currentMatch->status === 'in_progress' && !$currentMatch->report => 4,
        $queueReportPendingConfirmation => 4,
        default => 2,
    };

    // El guerrero del escenario: el que esta jugando manda sobre el resto.
    $activePlayerId = $currentQueue?->player_id ?? $matchLineup['viewer_player_id'] ?? null;
    // Con un enfrentamiento en marcha las figuras que importan son las del
    // combate, no el escaparate: dos escenarios 3D compitiendo por la atencion
    // en la misma columna es ruido, y en movil es scroll muerto.
    $showStage = !$hasActiveState;
    // Con cola o combate activo manda el personaje que esta jugando; si no, el
    // que pida la URL; si tampoco, el primero.
    $featured = $players->firstWhere('id', $activePlayerId)
        ?? $requestedPlayer
        ?? $players->first();
    $lockedToPlayer = $hasActiveState;

    $championData = $players->mapWithKeys(fn ($p) => [$p->id => [
        'name' => $p->cleanName(),
        'realm' => $p->realm,
        'realmName' => PlayerModel::REALMS[$p->realm] ?? ucfirst($p->realm),
        'subclass' => $p->subclass,
        'subclassName' => PlayerModel::SUBCLASSES[$p->subclass] ?? ucfirst($p->subclass),
        'race' => $p->race,
        'raceName' => $p->raceName(),
        'gender' => $p->gender ?: 'male',
        'pl' => number_format((float) $p->pl_points, 1),
        'mmr' => $p->mmr,
        'wins' => $p->wins,
        'losses' => $p->losses,
        'status' => $p->statusLabel(),
        'active' => (bool) $p->is_active,
        'locked' => $p->isQueueLocked(),
    ]]);

    $pageTitle = $currentMatch
        ? ($currentMatch->status === 'pending_acceptance' ? 'Combate encontrado' : 'Combate activo')
        : ($currentQueue ? 'Buscando combate…' : 'Lobby');
@endphp

@section('title', $pageTitle . ' — Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <x-arena-breadcrumbs :items="[['label' => 'Lobby']]" class="mb-5" />

    {{-- ── CABECERA ───────────────────────────────────────────────────────
         El lobby y la arena eran dos paginas que ensenaban lo mismo, y desde
         el lobby "Pelear" te llevaba a otra pantalla en vez de a la cola. Ahora
         son una sola: aqui se elige guerrero Y se entra a combatir. --}}
    <section class="arena-panel-strong mb-5 p-6 md:p-7 arena-animate-in">
        <div class="flex flex-wrap items-start justify-between gap-5">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <p class="arena-kicker">Arena {{ $arenaMode }}</p>
                    @if($shouldAutoRefresh)
                        <span id="statePollingActive" class="arena-chip text-xs bg-black/40 border border-emerald-500/30 text-emerald-300 px-2 py-1">
                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-400 mr-1 animate-pulse"></span>
                            En vivo
                        </span>
                    @endif
                </div>
                <h1 class="mt-2 text-3xl font-bold text-[color:var(--arena-gold-soft)] md:text-4xl">
                    @if($matchIsPendingAcceptance)
                        Cruce encontrado
                    @elseif($currentMatch)
                        Tu combate
                    @elseif($currentQueue)
                        Buscando combate…
                    @else
                        Bienvenido, {{ auth()->user()->discord_username }}
                    @endif
                </h1>
                <p class="mt-2 max-w-2xl text-[color:var(--arena-sand)] arena-body-text">
                    @if($matchIsPendingAcceptance)
                        Confirma abajo antes de que se agote el reloj. Si alguien no acepta, el cruce se cancela.
                    @elseif($currentMatch)
                        Sigue el estado del enfrentamiento y reporta el resultado al terminar.
                    @elseif($currentQueue)
                        Espera aquí. En cuanto haya rival te avisamos y el combate aparece en esta misma pantalla.
                    @else
                        Elige un guerrero y entra a la arena. Todo pasa en esta pantalla.
                    @endif
                </p>

                @if(count($enabledModes) > 1 && $canJoinQueue)
                    <div class="mt-4 inline-flex rounded-2xl border border-[color:var(--arena-line)] bg-black/20 p-1" role="tablist" aria-label="Modalidad de arena">
                        @foreach($enabledModes as $mode)
                            <a href="{{ route('lobby', ['mode' => $mode]) }}"
                               role="tab"
                               aria-selected="{{ $mode === $arenaMode ? 'true' : 'false' }}"
                               class="rounded-xl px-5 py-2 text-sm font-semibold transition-all {{ $mode === $arenaMode
                                    ? 'bg-[linear-gradient(180deg,rgba(63,45,31,0.85),rgba(22,15,11,0.95))] text-[color:var(--arena-gold-soft)] shadow-[0_4px_16px_rgba(0,0,0,0.2),inset_0_1px_0_rgba(255,215,134,0.12)]'
                                    : 'text-[color:var(--arena-muted)] hover:text-[color:var(--arena-sand)] hover:bg-white/[0.04]' }}">
                                {{ $mode }}
                            </a>
                        @endforeach
                    </div>
                @endif

                @if(!$modesAreOpen)
                    <p class="mt-4 rounded-2xl border border-amber-700/40 bg-amber-900/20 px-4 py-3 text-sm text-amber-200 arena-body-text">
                        Las colas están cerradas por el momento. Vuelve más tarde.
                    </p>
                @endif
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('matches.index') }}" class="arena-btn-secondary px-4 py-2">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/></svg>
                    Mis combates
                </a>
                <a href="{{ route('ladder.index') }}" class="arena-btn-ghost px-4 py-2">Ladder</a>
            </div>
        </div>

        <div class="mt-6">
            <x-arena-stepper :steps="['Registra', 'Elige modo', 'Espera cruce', 'Pelea y reporta']" :current="$stepperCurrent" />
        </div>
    </section>

    @if(!$hasRoster)
        {{-- Sin guerreros no hay nada que ensenar ni que hacer. --}}
        <section class="arena-panel p-8 text-center arena-animate-in arena-stagger-1">
            <p class="arena-kicker">Reclutamiento</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Necesitas un guerrero para entrar a la arena</h2>
            <p class="mx-auto mt-3 max-w-md text-[color:var(--arena-muted)] arena-body-text">
                Crea el primero. Eliges reino, raza, sexo y subclase, y lo ves en 3D antes de confirmar.
            </p>
            <a href="{{ route('player.create') }}" class="arena-btn mt-6 inline-flex px-6 py-3">Crear mi primer guerrero</a>
        </section>
    @else
        {{-- ── CONSOLA ────────────────────────────────────────────────────
             Un solo panel. El rail elige guerrero, el escenario lo ensena y
             debajo se entra a la cola: antes elegir personaje pasaba dos veces,
             una en el rail y otra en un desplegable a media pagina de
             distancia, y las acciones del guerrero vivian en una tarjeta
             suelta entre medias. --}}
        <section class="arena-console arena-animate-in arena-stagger-1">
            <aside class="arena-console-rail">
                <div class="arena-console-rail-head">
                    <p class="arena-kicker">Tu escuadra</p>
                    <span class="arena-console-count">{{ $players->count() }}/5</span>
                </div>

                @if($lockedToPlayer)
                    <p class="arena-console-note">
                        Con cola o combate activo no puedes cambiar de guerrero.
                    </p>
                @endif

                <div class="arena-console-slots">
                    @foreach($players as $player)
                        @php($isFeatured = $featured && $player->id === $featured->id)
                        {{-- Enlaces de verdad, no botones: sin JavaScript el rail
                             sigue cambiando de guerrero, recargando con ?player. --}}
                        <a href="{{ route('lobby', ['mode' => $arenaMode, 'player' => $player->id]) }}"
                           class="arena-roster-slot {{ $lockedToPlayer && !$isFeatured ? 'is-locked' : '' }}"
                           data-champion-slot
                           data-player-id="{{ $player->id }}"
                           aria-pressed="{{ $isFeatured ? 'true' : 'false' }}"
                           @if($lockedToPlayer && !$isFeatured) aria-disabled="true" tabindex="-1" @endif
                           style="--slot-realm: var(--arena-{{ $player->realm === 'ignis' ? 'fire' : ($player->realm === 'alsius' ? 'ice' : 'forest') }})">
                            <span class="arena-roster-crest">
                                <x-arena-realm-icon :realm="$player->realm" size="sm" />
                            </span>
                            <span class="min-w-0">
                                <span class="arena-roster-name">{{ $player->cleanName() }}</span>
                                <span class="arena-roster-meta">
                                    {{ PlayerModel::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass) }}
                                    · {{ $player->raceName() }}
                                </span>
                            </span>
                            <span class="arena-roster-pl">
                                {{ number_format((float) $player->pl_points, 1) }}
                                @if($player->isQueueLocked())
                                    <span class="arena-roster-lock" title="Bloqueado para la cola hasta {{ $player->queue_locked_until?->format('d/m H:i') }}">Bloqueado</span>
                                @endif
                            </span>
                        </a>
                    @endforeach

                    @if($players->count() < 5)
                        <a href="{{ route('player.create') }}" class="arena-roster-slot arena-roster-empty">+ Crear guerrero</a>
                    @endif
                </div>
            </aside>

            <div class="arena-console-main">
                @if($showStage)
                    <div class="arena-console-stage">
                        <x-arena-champion
                            id="hub-stage"
                            :realm="$featured->realm"
                            :subclass="$featured->subclass"
                            :race="$featured->race"
                            :gender="$featured->gender"
                            height="clamp(300px, 40vh, 440px)">

                            <div class="arena-champion-overlay">
                                {{-- Las acciones del guerrero viven con el
                                     guerrero, no en una tarjeta aparte. --}}
                                <div class="arena-console-tools">
                                    @foreach($players as $player)
                                        <div data-champion-panel data-player-id="{{ $player->id }}"
                                             class="arena-console-tools-set"
                                             @if(!$featured || $player->id !== $featured->id) hidden @endif>
                                            @if($player->is_active && !$hasActiveState)
                                                <button type="button" class="arena-console-tool" data-modal-open="modal-rename-{{ $player->id }}"
                                                        aria-label="Editar el nombre de {{ $player->cleanName() }}" title="Editar nombre">
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                                    <span>Nombre</span>
                                                </button>
                                                @if($players->count() > 1)
                                                    <button type="button" class="arena-console-tool is-danger" data-modal-open="modal-delete-{{ $player->id }}"
                                                            aria-label="Eliminar a {{ $player->cleanName() }}" title="Eliminar guerrero">
                                                        <x-admin.icon name="trash" class="h-4 w-4" />
                                                        <span>Eliminar</span>
                                                    </button>
                                                @endif
                                            @elseif(!$player->is_active)
                                                <span class="arena-console-tool is-muted">Deshabilitado por un administrador</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                <div class="arena-champion-stats-inside arena-stats-row">
                                    <div class="arena-stat-pill"><span>PL</span><b data-champion-pl>{{ number_format((float) $featured->pl_points, 1) }}</b></div>
                                    <div class="arena-stat-pill"><span>MMR</span><b data-champion-mmr>{{ $featured->mmr }}</b></div>
                                    <div class="arena-stat-pill"><span>V/D</span><b data-champion-record>{{ $featured->wins }}/{{ $featured->losses }}</b></div>
                                </div>

                                <div class="arena-console-ident" aria-live="polite">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <h2 class="arena-champion-name" data-champion-name>{{ $featured->cleanName() }}</h2>
                                        <span class="arena-champion-status" data-champion-status @if($featured->is_active) hidden @endif>{{ $featured->statusLabel() }}</span>
                                    </div>
                                    <p class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                                        <span class="arena-champion-realm" data-champion-realm-name>{{ PlayerModel::REALMS[$featured->realm] ?? $featured->realm }}</span>
                                        <span data-champion-race-name>{{ $featured->raceName() }}</span>
                                        <span data-champion-subclass-name>{{ PlayerModel::SUBCLASSES[$featured->subclass] ?? $featured->subclass }}</span>
                                    </p>
                                </div>
                            </div>
                        </x-arena-champion>

                        {{-- En movil las cifras no caben sobre la figura sin
                             taparle la cara: van justo debajo. --}}
                        <div class="arena-champion-stats-outside">
                            <div class="arena-stats-row">
                                <div class="arena-stat-pill"><span>PL</span><b data-champion-pl>{{ number_format((float) $featured->pl_points, 1) }}</b></div>
                                <div class="arena-stat-pill"><span>MMR</span><b data-champion-mmr>{{ $featured->mmr }}</b></div>
                                <div class="arena-stat-pill"><span>V/D</span><b data-champion-record>{{ $featured->wins }}/{{ $featured->losses }}</b></div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Estado: cruce, combate en curso o cola. Uno solo a la vez. --}}
                @if($matchIsPendingAcceptance && $matchLineup)
                    <x-arena-duel-panel :match="$currentMatch" :lineup="$matchLineup" :player="$matchPlayer" />
                @elseif($currentMatch && $currentMatch->isActive())
                    <x-arena-live-match :match="$currentMatch" :lineup="$matchLineup" :report-pending="$queueReportPendingConfirmation" />
                @elseif($currentQueue)
                    @include('arena._queue_state')
                @endif

                @include('arena._join')
            </div>
        </section>

        {{-- Los formularios de nombre y borrado, fuera del panel para que sus
             capas no queden atrapadas por el recorte del escenario. --}}
        @if(!$hasActiveState)
            @foreach($players as $player)
                @if($player->is_active)
                    <x-arena-modal :id="'modal-rename-'.$player->id" :title="'Cambiar el nombre de ' . $player->cleanName()">
                        <form method="POST" action="{{ route('player.update', $player) }}" class="space-y-3">
                            @csrf
                            @method('PUT')
                            <label class="block">
                                <span class="mb-2 block text-sm arena-body-text">Nombre del personaje</span>
                                <input type="text" name="character_name" value="{{ $player->character_name }}" class="arena-field" required>
                            </label>
                            <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">
                                Reino, raza, sexo y subclase no se pueden cambiar.
                            </p>
                            <div class="flex gap-3 pt-1">
                                <button type="submit" class="arena-btn-secondary px-4 py-2">Guardar</button>
                                <button type="button" class="arena-btn-ghost px-4 py-2" data-modal-close="modal-rename-{{ $player->id }}">Cancelar</button>
                            </div>
                        </form>
                    </x-arena-modal>

                    @if($players->count() > 1)
                        <x-arena-modal :id="'modal-delete-'.$player->id" :title="'Eliminar a ' . $player->cleanName()" variant="danger">
                            <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">
                                @if($player->matches_played > 0)
                                    Este personaje tiene {{ $player->matches_played }} partidas registradas.
                                    Su historial se conserva para no falsear las partidas ya jugadas, pero
                                    saldrá del ranking y de este lobby, y el nombre quedará libre. Si más
                                    adelante lo quieres de vuelta, tendrás que pedírselo a un administrador.
                                @else
                                    Este personaje será eliminado permanentemente. No tiene partidas jugadas.
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
                @endif
            @endforeach
        @endif
    @endif
</div>

@include('arena._match_extras')
@endsection

@push('scripts')
@if($hasRoster && $showStage)
<script>
    /* Raíl de guerreros: cambiar de guerrero repinta el escenario y el panel de
       acciones sin recargar. Con cola o combate activo los demás están
       deshabilitados, porque el estado pertenece a un personaje concreto. */
    (function () {
        var champions = @json($championData);
        var slots = document.querySelectorAll('[data-champion-slot]');
        if (!slots.length) { return; }

        var stage = document.querySelector('[data-champion-id="hub-stage"]');

        function paint(id) {
            var c = champions[id];
            if (!c) { return; }

            var paintAll = function (selector, value) {
                document.querySelectorAll(selector).forEach(function (n) { n.textContent = value; });
            };

            paintAll('[data-champion-name]', c.name);
            paintAll('[data-champion-realm-name]', c.realmName);
            paintAll('[data-champion-race-name]', c.raceName);
            paintAll('[data-champion-subclass-name]', c.subclassName);
            paintAll('[data-champion-pl]', c.pl);
            paintAll('[data-champion-mmr]', c.mmr);
            paintAll('[data-champion-record]', c.wins + '/' + c.losses);

            document.querySelectorAll('[data-champion-status]').forEach(function (s) {
                s.textContent = c.status;
                s.hidden = c.active;
            });

            if (stage) { stage.dataset.championRealm = c.realm; }

            var viewer = window.arenaChampionViewers && window.arenaChampionViewers['hub-stage'];
            if (viewer) { viewer.set(c.realm, c.subclass, c.race, c.gender); }

            document.querySelectorAll('[data-champion-panel]').forEach(function (panel) {
                panel.hidden = panel.dataset.playerId !== String(id);
            });
            slots.forEach(function (slot) {
                slot.setAttribute('aria-pressed', slot.dataset.playerId === String(id) ? 'true' : 'false');
            });

            // El formulario de cola y el rail son la misma eleccion: el
            // guerrero que se ve en el escenario es con el que se entra. Antes
            // habia que elegirlo dos veces y el desplegable empezaba vacio.
            document.querySelectorAll('[data-queue-player-id]').forEach(function (input) {
                input.value = id;
            });

            var select = document.querySelector('[data-queue-player-select]');
            if (select) {
                select.value = String(id);
                select.dataset.subclass = c.subclass;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }

            // El rol de conjurador depende de la subclase que se acaba de
            // elegir, y ese bloque vive en otro archivo.
            document.dispatchEvent(new CustomEvent('arena:champion-changed', { detail: { id: id } }));
        }

        slots.forEach(function (slot) {
            slot.addEventListener('click', function (event) {
                if (slot.getAttribute('aria-disabled') === 'true') {
                    event.preventDefault();
                    return;
                }

                // Con JavaScript se repinta en el sitio; el enlace sigue ahi
                // por si no lo hay, y para poder abrirlo en otra pestana.
                if (event.metaKey || event.ctrlKey || event.shiftKey) { return; }
                event.preventDefault();
                paint(slot.dataset.playerId);

                // La URL acompana a lo que se ve, para que recargar no devuelva
                // al primer guerrero de la lista.
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({}, '', slot.getAttribute('href'));
                }
            });
        });
    })();
</script>
@endif
@endpush
