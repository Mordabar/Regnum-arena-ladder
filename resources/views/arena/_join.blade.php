{{-- Formularios para entrar en cola, party e invitaciones.

     Vive aparte desde que el lobby y la arena son la misma pantalla: el mismo
     bloque se usa en el hub y no hay dos copias que se puedan desincronizar. --}}
    {{-- ── QUEUE MODES (only if user can join) ── --}}
    @if($canJoinQueue)
        <div id="queue-modes" class="mt-5 flex flex-col gap-5 arena-animate-in arena-stagger-2">
            <section class="arena-panel p-6">

                {{-- Dos acciones, no dos pestanas: entrar solo, o armar grupo.
                     Una pestana obliga a entender que hay algo escondido detras;
                     un boton dice lo que hace. --}}
                <div id="tab-random" class="arena-queue-actions">
                    @if(!$modesAreOpen)
                        <p class="arena-card p-4 text-sm text-[color:var(--arena-muted)] arena-body-text">
                            Las colas están cerradas por el momento.
                        </p>
                    @else
                    <form method="POST" action="{{ route('queue.join') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="queue_type" value="random">
                        <input type="hidden" name="arena_mode" value="{{ $arenaMode }}">

                        {{-- El personaje ya se eligio arriba, en el rail y en el
                             escenario. Repetir aqui un desplegable con los
                             mismos nombres obligaba a elegir dos veces y dejaba
                             la duda de cual mandaba. Va oculto, siguiendo al
                             guerrero que se ve. --}}
                        <input type="hidden" id="playerSelect" name="player_id" data-queue-player-select
                               data-subclass="{{ $featured?->subclass }}"
                               value="{{ $featured?->id }}">

                        <p class="arena-queue-with">
                            Entras con
                            <b data-champion-name>{{ $featured?->cleanName() }}</b>
                            <span data-champion-subclass-name>{{ $featured ? (\App\Models\Player::SUBCLASSES[$featured->subclass] ?? $featured->subclass) : '' }}</span>
                        </p>

                        @if($featured?->isQueueLocked())
                            <p class="rounded-2xl border border-rose-500/30 bg-rose-900/20 px-4 py-3 text-sm text-rose-200 arena-body-text">
                                Este guerrero está bloqueado para la cola hasta
                                {{ $featured->queue_locked_until?->format('d/m H:i') }}. Elige otro en tu escuadra.
                            </p>
                        @endif

                        <div id="conjurerRoleDiv" class="hidden arena-card p-4">
                            <label for="randomConjurerRole" class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Rol del conjurador</label>
                            <select id="randomConjurerRole" name="conjurer_role" class="arena-select" disabled>
                                <option value="offensive">Ofensivo</option>
                                <option value="support">Soporte</option>
                            </select>
                            <p class="mt-2 text-xs text-[color:var(--arena-muted)] arena-body-text">Solo un conjurador soporte por equipo.</p>
                        </div>

                        <div class="arena-queue-buttons">
                            <button type="submit" class="arena-btn-safe" @disabled($featured?->isQueueLocked())>
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                                Entrar a Random {{ $arenaMode }}
                            </button>
                            <button type="button" class="arena-btn-secondary" data-premade-toggle
                                    aria-controls="tab-premade" aria-expanded="false">
                                <x-admin.icon name="users" class="h-4 w-4" />
                                Armar grupo premade {{ $arenaMode }}
                            </button>
                        </div>

                        <p class="arena-queue-hint">
                            Random entra con un solo personaje y el sistema busca {{ $teamSize - 1 }} aliado(s) de tu reino.
                            Premade lo eliges tu, {{ $teamSize }} del mismo reino.
                        </p>
                    </form>
                    @endif
                </div>

                {{-- El constructor de grupo, plegado hasta que se pide. --}}
                <div id="tab-premade" class="arena-premade-builder mt-5" data-has-party="{{ $activeParty ? '1' : '0' }}" hidden>
                    @unless($activeParty)
                        <p class="mb-4 flex flex-wrap items-center gap-x-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
                            <span>Forma tu escuadra con {{ $teamSize - 1 }} aliado(s) y lánzate a la arena.</span>
                            <span class="arena-chip text-[color:var(--arena-ice)]">{{ $premadeDailyLimit }}/día</span>
                        </p>
                    @endunless

                    @if(isset($activeParty) && $activeParty)
                        {{-- La party ya se ve arriba, dentro del escenario, con
                             la figura de cada uno. Aqui solo queda el estado y
                             lo que se puede hacer con ella: repetir la lista de
                             nombres era la misma informacion dos veces, en dos
                             sitios distintos de la misma pantalla. --}}
                        @php
                            $isLeader = $players->contains(fn ($p) => $p->id === $activeParty->leader_player_id);
                            $partyReady = $activeParty->status === 'ready';
                            $partyQueued = $activeParty->status === 'queued';
                        @endphp

                        <div class="arena-party-state">
                            <p class="arena-party-state-line">
                                @if($partyQueued)
                                    <span class="arena-party-dot is-live"></span> Tu party busca rival.
                                @elseif($partyReady)
                                    <span class="arena-party-dot is-ready"></span> Party completa: ya puedes entrar a la cola.
                                @else
                                    <span class="arena-party-dot"></span> Esperando a que tus aliados acepten la invitacion.
                                @endif
                            </p>

                            @unless($activePartyModeIsOpen)
                                <p class="arena-queue-hint">
                                    La modalidad {{ \App\Support\ArenaMode::label($activePartyMode) }} esta apagada.
                                    La party queda guardada y podra buscar cuando vuelva a abrirse.
                                </p>
                            @endunless

                            <div class="arena-queue-buttons">
                                @if($isLeader && $partyReady && $activePartyModeIsOpen)
                                    <form method="POST" action="{{ route('party.enqueue', $activeParty) }}">
                                        @csrf
                                        <button class="arena-btn-safe">Entrar a Premade {{ \App\Support\ArenaMode::label($activePartyMode) }}</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('party.leave', $activeParty) }}">
                                    @csrf
                                    <button class="arena-btn-danger-ghost">{{ $partyQueued ? 'Cancelar y abandonar' : 'Abandonar party' }}</button>
                                </form>
                            </div>
                        </div>
                    @elseif(!$modesAreOpen)
                        <p class="arena-card p-4 text-sm text-[color:var(--arena-muted)] arena-body-text">
                            No se pueden armar partys mientras las colas estén cerradas.
                        </p>
                    @else
                        <form method="POST" action="{{ route('party.create') }}" class="space-y-4" id="premadeForm">
                        @csrf
                        <input type="hidden" name="queue_type" value="premade">
                        <input type="hidden" name="arena_mode" value="{{ $arenaMode }}">

                        {{-- Leader --}}
                        <div class="arena-card p-4">
                            {{-- Arranca con el guerrero que se ve en el
                                 escenario: armar party empezaba pidiendo elegir
                                 personaje otra vez, con el mismo que ya estaba
                                 elegido arriba. --}}
                            <label for="partyLeaderSelect" class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Slot 1 — Tu líder</label>
                            <select id="partyLeaderSelect" name="party_player_ids[]" class="arena-select" data-party-leader-select required>
                                @foreach($players as $player)
                                    <option
                                        value="{{ $player->id }}"
                                        data-user="{{ $player->user_id }}"
                                        data-realm="{{ $player->realm }}"
                                        data-realm-label="{{ \App\Models\Player::REALMS[$player->realm] ?? ucfirst($player->realm) }}"
                                        data-subclass="{{ $player->subclass }}"
                                        data-subclass-label="{{ \App\Models\Player::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass) }}"
                                        data-character-name="{{ $player->character_name }}"
                                        data-owner-label="{{ auth()->user()->discord_username }}"
                                        @selected($featured && $player->id === $featured->id)
                                        @disabled($player->isQueueLocked())
                                    >
                                        {{ $player->character_name }} · {{ \App\Models\Player::REALMS[$player->realm] ?? ucfirst($player->realm) }} · {{ \App\Models\Player::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass) }}{{ $player->isQueueLocked() ? ' · BLOQUEADO' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <p id="premadeRealmHint" class="mt-2 text-xs text-[color:var(--arena-muted)] arena-body-text">
                                Selecciona primero tu líder para desbloquear la búsqueda.
                            </p>
                        </div>

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

                        {{-- Summary --}}
                        <div class="arena-card p-4">
                            <p class="text-sm font-semibold text-white arena-body-text">Party en construcción</p>
                            {{-- Clases literales: Tailwind no compila nombres de clase generados dinamicamente. --}}
                            <div id="premadeSummary" class="mt-3 grid gap-3 {{ $teamSize >= 3 ? 'md:grid-cols-3' : 'md:grid-cols-2' }}">
                                @for($slot = 1; $slot <= $teamSize; $slot++)
                                    <div class="rounded-2xl border border-[color:var(--arena-line)] bg-black/10 px-4 py-3 text-sm text-[color:var(--arena-muted)]">
                                        Slot {{ $slot }} pendiente
                                    </div>
                                @endfor
                            </div>
                        </div>

                        <button type="submit" id="premadeSubmitButton" class="arena-btn-safe w-full" disabled>
                            Invitar a formar Party
                        </button>
                    </form>
                    @endif
                </div>
            </section>

            {{-- La escuadra no se repite aqui: vive en el rail de la izquierda. --}}
                {{-- Las reglas no tienen que ocupar sitio todo el rato: se
                     abren cuando hacen falta. --}}
                <div>
                    <button type="button" class="arena-btn-ghost px-4 py-2 text-sm" data-modal-open="modal-arena-rules">
                        <x-admin.icon name="sliders" class="h-4 w-4" />
                        Ver reglas de juego
                    </button>
                </div>

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
        </div>
    @endif
