{{-- Ventanas del lobby: invitar a party y las reglas.

     Todo lo demas (elegir guerrero, elegir modalidad, entrar) vive en la
     consola, sobre la figura. Aqui solo queda lo que se abre cuando hace
     falta. --}}

@if($canJoinQueue && $modesAreOpen && !$activeParty)
    <x-arena-modal id="modal-premade" :title="'Invitar aliado ' . $arenaMode">
        <p class="arena-queue-hint mb-4">
            Juegas con el guerrero que tienes elegido. Busca a
            {{ $teamSize - 1 }} aliado(s) de tu reino y les llega la invitacion:
            el grupo se arma cuando la acepten, y hasta entonces tu hueco y el
            suyo se ven en el escenario.
        </p>

                <form method="POST" action="{{ route('party.create') }}" class="space-y-4" id="premadeForm">
                @csrf
                <input type="hidden" name="queue_type" value="premade">
                <input type="hidden" name="arena_mode" value="{{ $arenaMode }}">

                {{-- El lider no se elige: es el guerrero que estas
                     viendo. Volver a pedirlo era pedir dos veces lo
                     mismo, y dejaba la duda de cual mandaba. --}}
                <input type="hidden" id="partyLeaderSelect" name="party_player_ids[]" data-party-leader-select
                       value="{{ $featured?->id }}"
                       data-user="{{ $featured?->user_id }}"
                       data-realm="{{ $featured?->realm }}"
                       data-realm-label="{{ $featured ? (\App\Models\Player::REALMS[$featured->realm] ?? ucfirst($featured->realm)) : '' }}"
                       data-subclass="{{ $featured?->subclass }}"
                       data-subclass-label="{{ $featured ? (\App\Models\Player::SUBCLASSES[$featured->subclass] ?? ucfirst($featured->subclass)) : '' }}"
                       data-character-name="{{ $featured?->character_name }}"
                       data-owner-label="{{ auth()->user()->discord_username }}">

                <div class="arena-invite-leader">
                    <x-arena-champion
                        :id="'premade-leader'"
                        :realm="$featured?->realm ?? 'ignis'"
                        :subclass="$featured?->subclass ?? 'knight'"
                        :race="$featured?->race"
                        :gender="$featured?->gender"
                        :parallax="false"
                        height="72px"
                        class="arena-duel-portrait" />
                    <span class="min-w-0">
                        <b data-champion-name>{{ $featured?->cleanName() }}</b>
                        <span>
                            {{ $featured ? (\App\Models\Player::REALMS[$featured->realm] ?? $featured->realm) : '' }}
                            · <span data-champion-subclass-name>{{ $featured ? (\App\Models\Player::SUBCLASSES[$featured->subclass] ?? $featured->subclass) : '' }}</span>
                        </span>
                    </span>
                    <span class="arena-invite-leader-tag">Tu</span>
                </div>

                <p id="premadeRealmHint" class="arena-queue-hint">
                    Solo veras companeros de tu reino y de otros usuarios.
                </p>

                <div id="premadeRoleDiv0" class="hidden arena-card p-4">
                    <label for="premadeLeaderRole" class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Rol del conjurador — Slot 1</label>
                    <select id="premadeLeaderRole" name="party_conjurer_roles[]" class="arena-select">
                        <option value="offensive">Ofensivo</option>
                        <option value="support">Soporte</option>
                    </select>
                </div>

                {{-- Slots de compañeros (2 en 2v2, 2 y 3 en 3v3) --}}
                @foreach($premadeSlots as $slot)
                    <div class="arena-card p-4">
                        <div class="flex items-start justify-between gap-3">
                            <label for="premadeSearch{{ $slot }}" class="block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Slot {{ $slot }} — Compañero</label>
                            <button type="button" class="arena-btn-ghost px-3 py-1.5 text-xs" data-premade-clear="{{ $slot }}">Limpiar</button>
                        </div>
                        <input type="hidden" name="party_player_ids[]" id="partyMemberInput{{ $slot }}">
                        <input type="text" id="premadeSearch{{ $slot }}" class="arena-field mt-2" placeholder="Primero elige tu líder" autocomplete="off" disabled>
                        <div id="premadeSelected{{ $slot }}" class="mt-3 hidden"></div>
                        <div id="premadeResults{{ $slot }}" class="mt-3 hidden space-y-2"></div>
                    </div>

                    <div id="premadeRoleDiv{{ $slot - 1 }}" class="hidden arena-card p-4">
                        <label for="premadeRole{{ $slot }}" class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Rol del conjurador — Slot {{ $slot }}</label>
                        <select id="premadeRole{{ $slot }}" name="party_conjurer_roles[]" class="arena-select">
                            <option value="offensive">Ofensivo</option>
                            <option value="support">Soporte</option>
                        </select>
                        <p class="mt-2 text-xs text-[color:var(--arena-muted)] arena-body-text">El equipo no puede tener 2 conjuradores soporte.</p>
                    </div>
                @endforeach

                <button type="submit" id="premadeSubmitButton" class="arena-btn-safe w-full" disabled>
                    Enviar la invitacion
                </button>
            </form>
    </x-arena-modal>
@endif

<x-arena-modal id="modal-arena-rules" title="Reglas de juego">
    <ul class="space-y-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
        <li><strong class="text-white">Random:</strong> entras con 1 personaje y el sistema completa tu equipo con gente de tu reino.</li>
        <li><strong class="text-white">Premade:</strong> {{ $teamSize }} personajes exactos, todos del mismo reino y de {{ $teamSize }} usuarios distintos. Maximo {{ $premadeDailyLimit }} al dia por equipo.</li>
        <li><strong class="text-white">Random contra premade:</strong> el equipo random gana mas puntos si vence, y pierde menos si cae.</li>
        <li><strong class="text-white">Conjuradores:</strong> solo puede haber uno de soporte por equipo.</li>
        <li><strong class="text-white">Anonimato:</strong> del rival ves reino y subclase, nunca el nombre, hasta que el enfrentamiento se cierra.</li>
        <li><strong class="text-white">Reporte:</strong> quien reporta sube entre 1 y 3 capturas. El rival confirma o rechaza; si deja pasar el plazo sin decir nada, el reporte se da por bueno.</li>
        <li><strong class="text-white">Sin reporte:</strong> si nadie reporta antes de que se agote el reloj, el enfrentamiento se anula y no reparte puntos.</li>
        <li><strong class="text-white">Abandonos:</strong> rechazar cruces a menudo o abandonar partidas baja tu confianza y bloquea la cola un tiempo.</li>
    </ul>
</x-arena-modal>
