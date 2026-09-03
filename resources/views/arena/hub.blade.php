@extends('layouts.arena')

@php
    use App\Models\Player as PlayerModel;
    use App\Support\ArenaMode;
@endphp

@section('title', $pageTitle . ' — Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <x-arena-breadcrumbs :items="[['label' => 'Lobby']]" class="mb-5" />

    {{-- ── CABECERA ───────────────────────────────────────────────────────
         El lobby y la arena eran dos paginas que ensenaban lo mismo, y desde
         el lobby "Pelear" te llevaba a otra pantalla en vez de a la cola. Ahora
         son una sola: aqui se elige guerrero Y se entra a combatir. --}}
    @include('arena._console_head')

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
        @include('arena._console')

        {{-- Los formularios de nombre y borrado, fuera del panel para que sus
             capas no queden atrapadas por el recorte del escenario. --}}
        @if(!$hasActiveState)
            @foreach($players as $player)
                @if($player->is_active)
                    {{-- Nombre, raza y sexo se pueden cambiar, como en el juego.
                         El reino y la subclase no: son lo que decide contra
                         quien peleas y como, y cambiarlos seria otro personaje
                         con el historial del anterior. --}}
                    <x-arena-modal :id="'modal-rename-'.$player->id" :title="'Editar a ' . $player->cleanName()">
                        <form method="POST" action="{{ route('player.update', $player) }}" class="space-y-4">
                            @csrf
                            @method('PUT')

                            <label class="block">
                                <span class="mb-2 block text-sm arena-body-text">Nombre del personaje</span>
                                <input type="text" name="character_name" value="{{ $player->character_name }}" class="arena-field" required>
                            </label>

                            <div>
                                <span class="mb-2 block text-sm arena-body-text">Raza</span>
                                <div class="arena-edit-choices">
                                    @foreach(PlayerModel::RACES[$player->realm] ?? [] as $raceKey => $raceLabel)
                                        <label class="arena-choice">
                                            <input type="radio" name="race" value="{{ $raceKey }}" @checked($player->race === $raceKey) required>
                                            <span class="arena-choice-body arena-choice-body-row">
                                                <span class="arena-choice-mark">
                                                    <x-arena-icon :name="$raceIcons[$raceKey] ?? 'human'" class="h-4 w-4" />
                                                </span>
                                                <span class="min-w-0">
                                                    <span class="arena-choice-title">{{ $raceLabel }}</span>
                                                    <span class="arena-choice-note">{{ PlayerModel::RACE_NOTES[$raceKey] ?? '' }}</span>
                                                </span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div>
                                <span class="mb-2 block text-sm arena-body-text">Sexo</span>
                                <div class="arena-edit-choices">
                                    @foreach(PlayerModel::GENDERS as $genderKey => $genderLabel)
                                        <label class="arena-choice">
                                            <input type="radio" name="gender" value="{{ $genderKey }}" @checked(($player->gender ?: 'male') === $genderKey) required>
                                            <span class="arena-choice-body arena-choice-body-row">
                                                <span class="arena-choice-mark">
                                                    <x-arena-icon :name="$genderKey" class="h-4 w-4" />
                                                </span>
                                                <span class="arena-choice-title">{{ $genderLabel }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">
                                El reino y la subclase no se pueden cambiar.
                            </p>
                            <div class="flex gap-3 pt-1">
                                <button type="submit" class="arena-btn-secondary px-4 py-2">Guardar cambios</button>
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

<x-arena-party-invites :invites="$pendingInvites" />

@include('arena._match_extras')
@endsection

@push('scripts')
@if($hasRoster)
<script>
    /* Raíl de guerreros: cambiar de guerrero repinta el escenario y el panel de
       acciones sin recargar. Con cola o combate activo los demás están
       deshabilitados, porque el estado pertenece a un personaje concreto. */
    (function () {
        var champions = @json($championData);

        // Nada se guarda en una variable de arranque: el panel entero se
        // repinta cuando cambia el estado, y los nodos de antes ya no estan.
        var todosLosSlots = function () {
            return document.querySelectorAll('[data-champion-slot]');
        };

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

            var stage = document.querySelector('[data-champion-id="' + 'hub-stage' + '"]');
            if (stage) { stage.dataset.championRealm = c.realm; }

            var viewer = window.arenaChampionViewers && window.arenaChampionViewers['hub-stage'];
            if (viewer) { viewer.set(c.realm, c.subclass, c.race, c.gender); }

            document.querySelectorAll('[data-champion-panel]').forEach(function (panel) {
                panel.hidden = panel.dataset.playerId !== String(id);
            });
            todosLosSlots().forEach(function (slot) {
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

            // El lider de la premade tambien es el guerrero del escenario:
            // armar party empezaba pidiendo elegir personaje otra vez.
            var leader = document.querySelector('[data-party-leader-select]');
            if (leader && leader.value !== String(id)) {
                leader.value = String(id);
                leader.dispatchEvent(new Event('change', { bubbles: true }));
            }

            // El rol de conjurador depende de la subclase que se acaba de
            // elegir, y ese bloque vive en otro archivo.
            document.dispatchEvent(new CustomEvent('arena:champion-changed', { detail: { id: id } }));
        }

        /* El cajon de la escuadra en movil. En escritorio el rail siempre se
           ve y estas clases no pintan nada. */
        (function () {
            var abrir = function (open) {
                var rail = document.querySelector('[data-roster-rail]');
                var scrim = document.querySelector('.arena-roster-scrim');
                if (!rail) { return; }

                rail.classList.toggle('is-open', open);
                if (scrim) { scrim.hidden = !open; }
                document.body.classList.toggle('arena-roster-locked', open);
            };

            document.addEventListener('click', function (event) {
                if (event.target.closest('[data-roster-open]')) { abrir(true); return; }
                if (event.target.closest('[data-roster-close]')) { abrir(false); return; }

                // Elegir guerrero cierra el cajon: es lo que se venia a hacer.
                if (event.target.closest('[data-roster-rail] [data-champion-slot]')) { abrir(false); }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') { abrir(false); }
            });

            // Un panel nuevo llega siempre con el cajon cerrado; el cuerpo tiene
            // que enterarse o el scroll se queda bloqueado.
            document.addEventListener('arena:dom-updated', function () {
                document.body.classList.remove('arena-roster-locked');
                var scrim = document.querySelector('.arena-roster-scrim');
                if (scrim) { scrim.hidden = true; }
            });
        })();

        // Por delegacion en el documento: asi elegir guerrero sigue funcionando
        // despues de que el sondeo cambie el panel por uno nuevo.
        document.addEventListener('click', function (event) {
            var slot = event.target.closest('[data-champion-slot]');
            if (!slot) { return; }

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
    })();
</script>
@endif
@endpush
