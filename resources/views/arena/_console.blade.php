{{-- El panel del lobby, aparte para poder repintarlo solo.

     El sondeo detectaba un cambio de estado y recargaba la pagina entera: se
     perdia el scroll, parpadeaba todo y los escenarios 3D se volvian a
     descargar. Ahora el servidor devuelve este trozo y el navegador lo cambia
     en su sitio. --}}
@php
    use App\Models\Player as PlayerModel;
    use App\Support\ArenaMode;
@endphp

        <section class="arena-console arena-animate-in arena-stagger-1">
            {{-- En movil este rail es un cajon: en una pantalla estrecha la
                 escuadra entera colgaba del final de la pagina, visible todo el
                 rato incluso durante un combate, y era la parte mas larga de un
                 lobby que ya tenia demasiado a la vista. --}}
            <aside class="arena-console-rail" data-roster-rail>
                <div class="arena-console-rail-head">
                    <p class="arena-kicker">Tu escuadra</p>
                    <span class="arena-console-count">{{ $players->count() }}/5</span>
                    <button type="button" class="arena-roster-close" data-roster-close aria-label="Cerrar la escuadra">
                        <x-admin.icon name="close" class="h-4 w-4" />
                    </button>
                </div>

                @if($lockedToPlayer)
                    <p class="arena-console-note">
                        Con cola o combate activo no puedes cambiar de guerrero.
                    </p>
                @endif

                <div class="arena-console-slots">
                    @foreach($players as $player)
                        {{-- Enlaces de verdad, no botones: sin JavaScript el rail
                             sigue cambiando de guerrero, recargando con ?player.

                             El bloque de PHP va explicito: la forma corta de una
                             linea, seguida de un comentario Blade, compila una
                             apertura sin cerrar y se lleva por delante el resto
                             del archivo. --}}
                        @php
                            $isFeatured = $featured && $player->id === $featured->id;
                        @endphp
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
                                @if(count($enabledModes) > 1)
                                    {{-- La modalidad manda sobre todo lo de
                                         abajo, asi que se elige antes de mirar
                                         al guerrero. Va sobre la escena y no en
                                         una barra encima: fuera del cuadro
                                         dibujaba un segundo marco dentro del
                                         panel, dos bordes para una sola cosa. --}}
                                    <div class="arena-console-arenas" role="tablist" aria-label="Modalidad de arena">
                                        <span class="arena-console-arenas-key">Arena</span>
                                        @foreach($enabledModes as $mode)
                                            <a href="{{ route('lobby', ['mode' => $mode, 'player' => $featured?->id]) }}"
                                               role="tab"
                                               aria-selected="{{ $mode === $arenaMode ? 'true' : 'false' }}"
                                               class="arena-console-arena {{ $mode === $arenaMode ? 'is-active' : '' }}">{{ $mode }}</a>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Las acciones del guerrero viven con el
                                     guerrero, no en una tarjeta aparte. --}}
                                <div class="arena-console-tools">
                                    @foreach($players as $player)
                                        <div data-champion-panel data-player-id="{{ $player->id }}"
                                             class="arena-console-tools-set"
                                             @if(!$featured || $player->id !== $featured->id) hidden @endif>
                                            @if($player->is_active && !$hasActiveState)
                                                <button type="button" class="arena-console-tool" data-modal-open="modal-rename-{{ $player->id }}"
                                                        aria-label="Editar a {{ $player->cleanName() }}" title="Editar guerrero">
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                                    <span>Editar</span>
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

                                {{-- La party, dentro del escenario: un grupo se
                                     entiende viendolo junto, no leyendo una
                                     lista de nombres en otra tarjeta. --}}
                                @if($activeParty)
                                    <div class="arena-console-party">
                                        <span class="arena-console-party-key">
                                            Party {{ ArenaMode::label($activePartyMode) }}
                                        </span>
                                        <div class="arena-console-party-slots">
                                            @foreach($activeParty->members as $member)
                                                @continue(!$member->player)
                                                <span class="arena-console-party-slot {{ $member->is_accepted_invite ? 'is-in' : '' }}"
                                                      title="{{ $member->player->character_name }}{{ $member->is_leader ? ' · lider' : '' }}{{ $member->is_accepted_invite ? '' : ' · invitado, sin responder' }}">
                                                    <x-arena-champion
                                                        :id="'party-' . $member->id"
                                                        :realm="$member->player->realm"
                                                        :subclass="$member->player->subclass"
                                                        :race="$member->player->race"
                                                        :gender="$member->player->gender"
                                                        :parallax="false"
                                                        height="64px"
                                                        class="arena-console-party-portrait" />
                                                    <b>{{ $member->player->cleanName() }}</b>
                                                </span>
                                            @endforeach
                                            @for($i = $activeParty->members->count(); $i < $teamSize; $i++)
                                                <span class="arena-console-party-slot is-empty">
                                                    <span class="arena-console-party-portrait is-empty">+</span>
                                                    <b>Libre</b>
                                                </span>
                                            @endfor
                                        </div>
                                    </div>
                                @endif

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

                        {{-- ── BARRA DE ACCIONES ──────────────────────────────
                             Pegada al pie del escenario, como el menu de accion
                             de un juego: lo que se puede hacer con el guerrero
                             que estas viendo, dentro de su propio panel. --}}
                        @if($canJoinQueue)
                            @if($modesAreOpen && !$activeParty)
                                {{-- Un conjurador entra a cola como soporte o
                                     como ofensivo, y el emparejamiento cuenta
                                     los dos por separado. El campo se pinta
                                     siempre y se ensena solo cuando toca: el
                                     guerrero se cambia sin recargar, asi que no
                                     puede depender de quien estuviera elegido al
                                     cargar la pagina.

                                     Vive fuera de la rejilla de acciones y se
                                     ata al formulario por su id: dentro, la
                                     rejilla de dos columnas lo partiria. --}}
                                <div id="conjurerRoleDiv" class="arena-console-role {{ $featured?->subclass === 'conjurer' ? '' : 'hidden' }}">
                                    <label for="randomConjurerRole">Rol del conjurador</label>
                                    <select id="randomConjurerRole" name="conjurer_role" form="randomQueueForm"
                                            class="arena-select" @disabled($featured?->subclass !== 'conjurer')>
                                        <option value="offensive">Ofensivo</option>
                                        <option value="support">Soporte</option>
                                    </select>
                                </div>
                            @endif

                            <div class="arena-console-actions">
                                @if(!$modesAreOpen)
                                    <p class="arena-console-actions-note">Las colas estan cerradas por el momento.</p>
                                @elseif($activeParty)
                                    @php
                                        $isLeader = $players->contains(fn ($p) => $p->id === $activeParty->leader_player_id);
                                        $partyReady = $activeParty->status === 'ready';
                                        $partyQueued = $activeParty->status === 'queued';
                                    @endphp

                                    @if($isLeader && $partyReady && $activePartyModeIsOpen)
                                        <form method="POST" action="{{ route('party.enqueue', $activeParty) }}">
                                            @csrf
                                            <button class="arena-console-action is-primary">
                                                <x-admin.icon name="users" class="h-4 w-4" />
                                                Entrar con el grupo {{ ArenaMode::label($activePartyMode) }}
                                            </button>
                                        </form>
                                    @else
                                        @php
                                            $sinResponder = $activeParty->members
                                                ->filter(fn ($m) => !$m->is_accepted_invite && $m->player)
                                                ->map(fn ($m) => $m->player->cleanName());
                                        @endphp
                                        <p class="arena-console-actions-note">
                                            @if($partyQueued)
                                                <span class="arena-party-dot is-live"></span> Tu grupo busca rival.
                                            @elseif(!$activePartyModeIsOpen)
                                                <span class="arena-party-dot"></span> {{ ArenaMode::label($activePartyMode) }} esta apagada: el grupo espera.
                                            @elseif($sinResponder->isNotEmpty())
                                                <span class="arena-party-dot"></span>
                                                Invitacion enviada. Falta que {{ $sinResponder->join(' y ') }} la acepte.
                                            @else
                                                <span class="arena-party-dot"></span> Esperando a tus aliados.
                                            @endif
                                        </p>
                                    @endif

                                    <form method="POST" action="{{ route('party.leave', $activeParty) }}">
                                        @csrf
                                        <button class="arena-console-action is-danger">
                                            {{ $partyQueued ? 'Cancelar busqueda' : 'Deshacer el grupo' }}
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('queue.join') }}" id="randomQueueForm">
                                        @csrf
                                        <input type="hidden" name="queue_type" value="random">
                                        <input type="hidden" name="arena_mode" value="{{ $arenaMode }}">
                                        {{-- El guerrero ya esta elegido arriba: este campo lo sigue. --}}
                                        <input type="hidden" id="playerSelect" name="player_id" data-queue-player-select
                                               data-subclass="{{ $featured?->subclass }}"
                                               value="{{ $featured?->id }}">
                                        <button type="submit" class="arena-console-action is-primary" @disabled($featured?->isQueueLocked())>
                                            <x-admin.icon name="play" class="h-4 w-4" />
                                            Entrar a Random {{ $arenaMode }}
                                        </button>
                                    </form>

                                    <button type="button" class="arena-console-action" data-modal-open="modal-premade">
                                        <x-admin.icon name="users" class="h-4 w-4" />
                                        Invitar aliado {{ $arenaMode }}
                                    </button>
                                @endif
                            </div>

                            @if($featured?->isQueueLocked())
                                <p class="arena-queue-locked">
                                    {{ $featured->cleanName() }} esta bloqueado para la cola hasta
                                    {{ $featured->queue_locked_until?->format('d/m H:i') }}. Elige otro guerrero.
                                </p>
                            @endif

                            <div class="arena-console-foot">
                                <p class="arena-queue-with">
                                    Entras con
                                    <b data-champion-name>{{ $featured?->cleanName() }}</b>
                                    <span data-champion-subclass-name>{{ $featured ? (PlayerModel::SUBCLASSES[$featured->subclass] ?? $featured->subclass) : '' }}</span>
                                </p>
                                <div class="arena-console-foot-actions">
                                    @unless($lockedToPlayer)
                                        <button type="button" class="arena-btn-ghost px-4 py-2 text-sm arena-roster-open" data-roster-open>
                                            <x-admin.icon name="users" class="h-4 w-4" />
                                            Cambiar guerrero
                                        </button>
                                    @endunless
                                    <button type="button" class="arena-btn-ghost px-4 py-2 text-sm" data-modal-open="modal-arena-rules">
                                        <x-admin.icon name="sliders" class="h-4 w-4" />
                                        Reglas
                                    </button>
                                </div>
                            </div>
                        @endif
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
            <div class="arena-roster-scrim" data-roster-close hidden></div>
        </section>
