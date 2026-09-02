{{-- Formularios para entrar en cola, party e invitaciones.

     Vive aparte desde que el lobby y la arena son la misma pantalla: el mismo
     bloque se usa en el hub y no hay dos copias que se puedan desincronizar. --}}
    {{-- ── QUEUE MODES (only if user can join) ── --}}
    @if($canJoinQueue)
        <div id="queue-modes" class="mt-5 flex flex-col gap-5 arena-animate-in arena-stagger-2">
            <section class="arena-panel p-6">

                @if(isset($pendingInvites) && $pendingInvites->isNotEmpty())
                    <div class="mb-6 space-y-3">
                        @foreach($pendingInvites as $invite)
                            <div class="arena-card p-4 border border-[color:var(--arena-gold-soft)]/50 bg-[color:var(--arena-gold-soft)]/5">
                                <div class="flex items-start justify-between flex-wrap gap-4">
                                    <div>
                                        <p class="text-sm font-semibold text-white"><x-admin.icon name="inbox" class="h-4 w-4 inline-block -mt-0.5" /> Invitación a Party</p>
                                        <p class="mt-1 text-sm text-[color:var(--arena-muted)]">
                                            <span class="text-[color:var(--arena-sand)]">{{ $invite->party->leader->character_name }}</span> 
                                            ha invitado a tu personaje <strong class="text-white">{{ $invite->player->character_name }}</strong>.
                                        </p>
                                    </div>
                                    <div class="flex gap-2">
                                        <form method="POST" action="{{ route('party.accept', ['party' => $invite->party_id, 'member' => $invite->id]) }}">
                                            @csrf
                                            <button class="arena-btn-safe px-4 py-2">Aceptar</button>
                                        </form>
                                        <form method="POST" action="{{ route('party.reject', ['party' => $invite->party_id, 'member' => $invite->id]) }}">
                                            @csrf
                                            <button class="arena-btn-danger px-4 py-2">Rechazar</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Las pestanas viven sobre la figura, en el escenario. --}}

                {{-- Random tab --}}
                <div id="tab-random" role="tabpanel" class="mt-6" style="animation: arenaFadeIn 0.25s ease-out">
                    <p class="mb-4 text-sm text-[color:var(--arena-muted)] arena-body-text">
                        Entras con un solo personaje. El sistema busca {{ $teamSize - 1 }} aliado(s) de tu reino.
                    </p>

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

                        <button type="submit" class="arena-btn-safe w-full" @disabled($featured?->isQueueLocked())>
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                            Entrar a Random {{ $arenaMode }}
                        </button>
                    </form>
                    @endif
                </div>

                {{-- Premade tab --}}
                <div id="tab-premade" role="tabpanel" class="mt-6 hidden">
                    <p class="mb-4 flex flex-wrap items-center gap-x-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
                        <span>Forma tu escuadra con {{ $teamSize - 1 }} aliado(s) y lánzate a la arena.</span>
                        <span class="arena-chip text-[color:var(--arena-ice)]">{{ $premadeDailyLimit }}/día</span>
                    </p>

                    @if(isset($activeParty) && $activeParty)
                        <div class="arena-card p-6 border border-[color:var(--arena-gold-soft)]/30">
                            <h3 class="text-xl font-bold text-white mb-2 flex items-center gap-2">
                                <x-admin.icon name="swords" class="h-5 w-5" /> Party Activa {{ \App\Support\ArenaMode::label($activePartyMode) }}
                                ({{ \App\Models\Player::REALMS[$activeParty->realm] ?? strtoupper($activeParty->realm) }})
                            </h3>
                            @unless($activePartyModeIsOpen)
                                <p class="mb-4 rounded-2xl border border-amber-700/40 bg-amber-900/20 px-4 py-3 text-sm text-amber-200 arena-body-text">
                                    La modalidad {{ \App\Support\ArenaMode::label($activePartyMode) }} está apagada ahora mismo.
                                    Esta party queda guardada y podrá buscar match cuando vuelva a activarse.
                                </p>
                            @endunless
                            <p class="text-sm text-[color:var(--arena-muted)] mb-5">
                                Estado: 
                                @if($activeParty->status === 'queued') <span class="text-emerald-400">Buscando oponente...</span>
                                @elseif($activeParty->status === 'ready') <span class="text-amber-400">Lista para buscar match</span>
                                @else <span class="text-amber-400">Esperando que los aliados acepten la invitación</span>
                                @endif
                            </p>
                            
                            <div class="space-y-3">
                                @foreach($activeParty->members as $member)
                                    <div class="flex items-center justify-between bg-black/40 p-4 rounded-xl border border-[color:var(--arena-line)]">
                                        <div>
                                            <p class="font-semibold text-white">{{ $member->player->character_name }} {!! $member->is_leader ? '<span class="ml-2 px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-500 text-[10px] uppercase font-bold tracking-wider">Líder</span>' : '' !!}</p>
                                            <p class="text-xs text-[color:var(--arena-muted)] mt-1">{{ \App\Models\Player::SUBCLASSES[$member->player->subclass] ?? ucfirst($member->player->subclass) }}</p>
                                        </div>
                                        <div>
                                            @if($member->is_accepted_invite)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-900/30 px-2 py-1 text-xs font-semibold text-emerald-300">
                                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg> En Party
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-900/30 px-2 py-1 text-xs font-semibold text-amber-300">
                                                    Invitado
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-6 flex flex-wrap gap-4 pt-4 border-t border-[color:var(--arena-line)]">
                                @php
                                    // Check if the current user owns the leader player object
                                    $isLeader = false;
                                    foreach($players as $p) {
                                        if ($p->id === $activeParty->leader_player_id) $isLeader = true;
                                    }
                                @endphp
                                @if($isLeader)
                                    @if($activeParty->status === 'ready')
                                        @if($activePartyModeIsOpen)
                                            <form method="POST" action="{{ route('party.enqueue', $activeParty) }}" class="flex-1 w-full md:w-auto">
                                                @csrf
                                                <button class="arena-btn-safe w-full justify-center py-3">▶ Iniciar Búsqueda Matchmaking</button>
                                            </form>
                                        @else
                                            <div class="flex-1 w-full text-sm text-[color:var(--arena-muted)] bg-black/20 p-3 rounded-lg border border-[color:var(--arena-line)] text-center">
                                                Búsqueda no disponible mientras {{ \App\Support\ArenaMode::label($activePartyMode) }} esté apagada.
                                            </div>
                                        @endif
                                    @elseif($activeParty->status === 'forming')
                                        <div class="flex-1 w-full text-sm text-[color:var(--arena-sand)] bg-[color:var(--arena-gold-soft)]/10 p-3 rounded-lg border border-[color:var(--arena-gold-soft)]/20 text-center">
                                            Debes esperar a que tus amigos acepten la invitación.
                                        </div>
                                    @endif
                                @endif
                                <form method="POST" action="{{ route('party.leave', $activeParty) }}" class="flex-none basis-full md:basis-auto">
                                    @csrf
                                    <button class="arena-btn-danger w-full justify-center">{{ $activeParty->status === 'queued' ? 'Cancelar Búsqueda y Abandonar' : 'Abandonar Party' }}</button>
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
