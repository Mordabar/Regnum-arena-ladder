@extends('layouts.admin')

@section('title', $match->match_code)
@section('page-title', $match->match_code)
@section('page-subtitle', 'Detalle del enfrentamiento y decisiones de moderacion')

@section('page-actions')
    <a href="{{ route('admin.matches.index') }}" class="ap-btn ap-btn-sm ap-btn-quiet">Volver a la lista</a>
    <a href="{{ route('admin.inbox') }}" class="ap-btn ap-btn-sm">Bandeja</a>
@endsection

@section('content')
@php
    $realmName = fn ($realm) => \App\Models\ArenaMatch::REALMS[$realm] ?? strtoupper((string) $realm);
    $report = $match->report;
    $claimed = $report?->claimed_winner_team;
    $isClosed = in_array($match->status, ['completed', 'void', 'cancelled'], true);
    // Anular una partida ya puntuada no es lo mismo que anular una que nunca
    // repartio nada: hay que devolver los puntos, y conviene decirlo.
    // Sobre la coleccion ya cargada por el controlador, no otra consulta.
    $yaPuntuado = $match->results->isNotEmpty();
    $cuantosJugadores = max(2, (int) ($match->player_count ?: $match->getAllPlayers()->count()));
    $textoAnular = $yaPuntuado
        ? [
            'text' => 'El enfrentamiento se anula y se deshace lo que repartio: cada jugador recupera los puntos que gano o perdio aqui, y sus partidas posteriores se recalculan.',
            'label' => 'Anular y devolver los puntos',
            'confirm' => 'Vas a anular un enfrentamiento YA PUNTUADO. Se devolveran los puntos a los ' . $cuantosJugadores . ' jugadores.',
            'danger' => true,
        ]
        : [
            'text' => 'El enfrentamiento se anula. Nadie gana ni pierde puntos, y no cuenta en el historial competitivo.',
            'label' => 'Anular enfrentamiento',
            'confirm' => 'Vas a anular este enfrentamiento. No repartira puntos.',
            'danger' => true,
        ];
@endphp

{{-- Cabecera de contexto: todo lo que hay que saber antes de decidir nada. --}}
<section class="ap-card ap-rise mb-4 p-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <x-admin.status :value="$match->status" />
                <x-admin.mode :mode="$match->arena_mode" />
                <span class="ap-badge ap-badge-neutral">{{ $match->queue_mode === 'premade' ? 'Premade' : 'Cola aleatoria' }}</span>
            </div>
            <p class="ap-section-note mt-2">
                <x-admin.realm :realm="$match->team_a_realm" /> contra <x-admin.realm :realm="$match->team_b_realm" />
                · empezo <x-admin.ago :date="$match->started_at ?? $match->created_at" />
                @if($match->completed_at) · cerrado <x-admin.ago :date="$match->completed_at" /> @endif
            </p>
        </div>
        <button type="button" class="ap-btn ap-btn-sm" data-modal-open="modal-admin-zone-map">
            <x-admin.icon name="map" class="h-3.5 w-3.5" />
            {{ $match->zone_name }}
        </button>
    </div>
</section>

<div class="grid gap-4 lg:grid-cols-2 mb-4">
    {{-- Equipos --}}
    @foreach(['team_a', 'team_b'] as $side)
        @php $realm = $side === 'team_a' ? $match->team_a_realm : $match->team_b_realm; @endphp
        <section class="ap-card ap-rise ap-delay-{{ $loop->index + 1 }} p-4">
            <div class="ap-section-head">
                <div>
                    <h2 class="ap-section-title">
                        Equipo {{ $side === 'team_a' ? 'A' : 'B' }} · {{ $realmName($realm) }}
                    </h2>
                    <p class="ap-section-note">
                        @if($match->winner_team === $side)
                            Declarado ganador.
                        @elseif($claimed === $side)
                            El reporte dice que gano este equipo.
                        @else
                            &nbsp;
                        @endif
                    </p>
                </div>
                <x-admin.realm :realm="$realm" />
            </div>
            <div class="flex flex-col gap-1.5">
                @foreach($match->getTeamBySide($side) as $player)
                    <div class="ap-list-row" style="border-color: var(--ap-line); background: var(--ap-surface-raised)">
                        <div class="ap-list-main">
                            <div class="ap-list-title">{{ $player['character_name'] }}</div>
                            <div class="ap-list-meta">
                                {{ \App\Models\Player::SUBCLASSES[$player['subclass']] ?? ucfirst($player['subclass']) }}
                                @if(!empty($player['conjurer_role']))
                                    · {{ $player['conjurer_role'] === 'support' ? 'Soporte' : 'Ofensivo' }}
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>

{{--
    Avisos de abandono. Cada uno se resuelve por separado porque cada uno
    señala a alguien: confirmar el de un jugador no dice nada sobre el otro.
    Se resuelven aqui y no en el selector de decision de abajo, que actua sobre
    el enfrentamiento entero.
--}}
@if($match->abandonmentReports->isNotEmpty())
    <section class="ap-card ap-rise ap-delay-2 mb-4 p-4" data-abandonment-admin>
        <div class="ap-section-head">
            <span class="ap-section-lead">
                <span class="ap-section-mark"><x-admin.icon name="shield" class="h-4 w-4" /></span>
                <h2 class="ap-section-title">Avisos de abandono</h2>
            </span>
            <span class="ap-hint">{{ $match->abandonmentReports->where('status', 'pending')->count() }} sin resolver</span>
        </div>

        <div class="mt-3 space-y-3">
            @foreach($match->abandonmentReports as $aviso)
                <article class="rounded-lg border border-[color:var(--ap-line)] p-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-semibold">
                            {{ $aviso->reporter?->character_name ?? 'Jugador retirado' }}
                            señala a
                            <span class="text-[color:var(--ap-danger)]">{{ $aviso->accused?->character_name ?? 'jugador retirado' }}</span>
                        </p>
                        <span class="ap-hint">{{ $aviso->created_at?->isoFormat('D MMM, HH:mm') }} · {{ $aviso->status_name }}</span>
                    </div>

                    @if($aviso->note)
                        <p class="mt-2 whitespace-pre-line break-words text-sm">{{ $aviso->note }}</p>
                    @endif

                    @if($aviso->evidenceItems() !== [])
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach($aviso->evidenceItems() as $prueba)
                                <a href="{{ $prueba['url'] }}" target="_blank" rel="noopener" class="ap-btn ap-btn-sm">{{ $prueba['label'] }}</a>
                            @endforeach
                        </div>
                    @endif

                    @if($aviso->status === 'pending')
                        <div class="mt-3 flex flex-wrap gap-2">
                            {{-- Con confirmacion, como el resto de acciones
                                 destructivas del panel: esto aplica strike,
                                 baja de confianza, bloqueo escalado y resta PL,
                                 y no hay boton de deshacer. --}}
                            <form method="POST" action="{{ route('admin.matches.resolve', $match) }}"
                                  data-ap-confirm="Vas a sancionar a {{ $aviso->accused?->character_name ?? 'el señalado' }}: pierde PL y confianza, y no podra entrar en cola durante horas. ¿Seguro?">
                                @csrf
                                <input type="hidden" name="action" value="confirm_abandonment">
                                <input type="hidden" name="abandonment_id" value="{{ $aviso->id }}">
                                <button type="submit" class="ap-btn ap-btn-sm ap-btn-danger">
                                    Confirmar: sancionar a {{ $aviso->accused?->character_name ?? 'el señalado' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.matches.resolve', $match) }}"
                                  data-ap-confirm="Vas a descartar el aviso. Nadie sera sancionado.">
                                @csrf
                                <input type="hidden" name="action" value="dismiss_abandonment">
                                <input type="hidden" name="abandonment_id" value="{{ $aviso->id }}">
                                <button type="submit" class="ap-btn ap-btn-sm ap-btn-quiet">Descartar aviso</button>
                            </form>
                        </div>
                        <p class="ap-hint mt-2">
                            Confirmar castiga <strong>solo</strong> al señalado: pierde PL, confianza y no puede
                            encolar durante unas horas. Nadie mas se toca: ni su equipo, si lo tiene, ni el rival.
                        </p>
                    @elseif($aviso->admin_note || $aviso->reviewed_by_admin)
                        @php
                            $quien = $aviso->reviewed_by_admin ? ' por ' . $aviso->reviewed_by_admin : '';
                            $porQue = $aviso->admin_note ? ': ' . $aviso->admin_note : '';
                        @endphp
                        <p class="ap-hint mt-2 break-words">Resuelto{{ $quien }}{{ $porQue }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endif

<div class="grid gap-4 lg:grid-cols-2 mb-4">
    {{-- Reporte y pruebas --}}
    <section class="ap-card ap-rise ap-delay-3 p-4">
        <div class="ap-section-head">
            <span class="ap-section-lead">
                <span class="ap-section-mark"><x-admin.icon name="inbox" class="h-4 w-4" /></span>
                <h2 class="ap-section-title">Lo que reportaron los jugadores</h2>
            </span>
            @if($report)<x-admin.status kind="report" :value="$report->status" />@endif
        </div>

        @if($report)
            <div class="flex flex-col gap-2">
                <div class="ap-kv">
                    <span class="ap-kv-key">Quien reporto</span>
                    <span class="ap-kv-value">{{ $report->reporter?->character_name ?? 'jugador eliminado' }}</span>
                </div>
                <div class="ap-kv">
                    <span class="ap-kv-key">Ganador que reclama</span>
                    <span class="ap-kv-value">
                        @if($claimed === 'draw')
                            Empate
                        @elseif($claimed === 'team_a')
                            Equipo A · {{ $realmName($match->team_a_realm) }}
                        @else
                            Equipo B · {{ $realmName($match->team_b_realm) }}
                        @endif
                    </span>
                </div>
            </div>

            @if(count($report->evidenceItems()))
                <p class="ap-label mt-3 mb-1.5">Capturas aportadas</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($report->evidenceItems() as $evidence)
                        <a href="{{ $evidence['url'] }}" target="_blank" rel="noopener" class="ap-btn ap-btn-sm">
                            <x-admin.icon name="external" class="h-3.5 w-3.5" />
                            {{ $evidence['label'] }}
                        </a>
                    @endforeach
                </div>
            @endif

            @if($report->reporter_note)
                <p class="ap-label mt-3 mb-1.5">Version de quien reporto</p>
                <p class="ap-quote">“{{ $report->reporter_note }}”</p>
            @endif

            @if($report->rejection_note)
                <p class="ap-label mt-3 mb-1.5">Version del rival, que lo rechazo</p>
                <p class="ap-quote">“{{ $report->rejection_note }}”</p>
            @endif

            {{-- Las pruebas del rechazo van aparte de las del reporte: en una
                 disputa lo util es tener las dos versiones separadas y poder
                 compararlas, no una lista donde no se sabe quien aporto que. --}}
            @if(count($report->rejectionEvidenceItems()))
                <p class="ap-label mt-3 mb-1.5">Capturas que aporto el rival al rechazar</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($report->rejectionEvidenceItems() as $evidence)
                        <a href="{{ $evidence['url'] }}" target="_blank" rel="noopener" class="ap-btn ap-btn-sm">
                            <x-admin.icon name="external" class="h-3.5 w-3.5" />
                            {{ $evidence['label'] }}
                        </a>
                    @endforeach
                </div>
            @elseif($report->rejected_at || $report->status === 'rejected')
                {{-- Se mira el rechazo en si, no su nota: un rechazo sin nota
                     dejaba la pantalla sin una sola señal de que alguien habia
                     rechazado algo. --}}
                <p class="ap-hint mt-2">El rival rechazo sin aportar capturas.</p>
            @endif

            @if($report->admin_note)
                <p class="ap-label mt-3 mb-1.5">Nota de moderacion</p>
                <p class="ap-quote">{{ $report->admin_note }}</p>
            @endif

            @if($report->reviewed_at || data_get($report->resolution_payload, 'original_claimed_winner_team'))
                <p class="ap-hint mt-3">
                    @if(data_get($report->resolution_payload, 'original_claimed_winner_team') && data_get($report->resolution_payload, 'original_claimed_winner_team') !== $claimed)
                        Moderacion corrigio el ganador reportado antes de cerrar.
                    @endif
                    @if($report->reviewer)
                        Revisado por {{ $report->reviewer->display_name ?? $report->reviewer->name ?? 'un administrador' }}@if($report->reviewed_at), <x-admin.ago :date="$report->reviewed_at" />@endif.
                    @endif
                </p>
            @endif
        @else
            <div class="ap-empty">
                <x-admin.icon name="inbox" class="h-6 w-6" />
                <p class="m-0">Nadie ha reportado el resultado todavia.</p>
            </div>
        @endif
    </section>

    {{-- Puntos aplicados --}}
    <section class="ap-card ap-rise ap-delay-4 p-4">
        <x-admin.section-head title="Puntos ya aplicados" icon="trophy"
                                note="Lo que este enfrentamiento sumo o resto en el ranking." />

        @if($match->results->isEmpty())
            <div class="ap-empty">
                <x-admin.icon name="gauge" class="h-6 w-6" />
                <p class="m-0">Aun no se ha repartido nada. El ranking no ha cambiado por esta partida.</p>
            </div>
        @else
            <div style="overflow-x: auto">
                <table class="ap-table">
                    <thead>
                        <tr>
                            <th scope="col">Jugador</th>
                            <th scope="col">Resultado</th>
                            <th scope="col" style="text-align: right">Puntos</th>
                            <th scope="col" style="text-align: right">MMR</th>
                            <th scope="col">Origen</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($match->results as $result)
                            <tr>
                                <th scope="row" style="font-weight: 500">{{ $result->player?->character_name ?? 'jugador eliminado' }}</th>
                                <td>
                                    <span class="ap-badge {{ $result->result === 'win' ? 'ap-badge-ok' : 'ap-badge-neutral' }}">
                                        <span class="ap-badge-dot"></span>{{ $result->result === 'win' ? 'Victoria' : 'Derrota' }}
                                    </span>
                                </td>
                                <td class="ap-num" style="text-align: right; color: {{ $result->pl_change >= 0 ? 'var(--ap-ok)' : 'var(--ap-danger)' }}">
                                    {{ $result->pl_change >= 0 ? '+' : '' }}{{ number_format((float) $result->pl_change, 1) }}
                                </td>
                                <td class="ap-num" style="text-align: right; color: {{ $result->mmr_change >= 0 ? 'var(--ap-ok)' : 'var(--ap-danger)' }}">
                                    {{ $result->mmr_change >= 0 ? '+' : '' }}{{ $result->mmr_change }}
                                </td>
                                <td>{{ $result->reported_by_admin ? 'Moderacion' : 'Jugadores' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>

{{-- Un solo formulario de decision.
     Antes habia cinco formularios en paralelo, cada uno con su boton: con las
     manos en el teclado y prisa, es facil enviar el que no era. Aqui se elige
     primero la decision, se ve que consecuencias tiene y se confirma una vez. --}}
<section class="ap-card ap-rise p-4" id="ap-decide">
    <div class="ap-section-head">
        <div class="ap-section-lead">
            <span class="ap-section-mark ap-section-mark-warn"><x-admin.icon name="scale" class="h-4 w-4" /></span>
            <div class="min-w-0">
            <h2 class="ap-section-title">Tomar una decision</h2>
            <p class="ap-section-note">
                @if($isClosed)
                    Este enfrentamiento ya esta cerrado. Lo que hagas aqui vuelve a mover el ranking.
                @else
                    Elige que hacer, revisa las consecuencias y confirma.
                @endif
            </p>
            </div>
        </div>
    </div>

    @if($match->notes)
        {{-- El registro interno del enfrentamiento. Estaba al reves: se le
             volcaba en crudo al jugador, con las sanciones de los demas y su
             letra pequeña, y aqui no salia. Es informacion de moderacion y
             este es su sitio. --}}
        <details class="ap-card mb-4 p-4">
            <summary class="cursor-pointer text-[13.5px] font-semibold">
                Registro interno del enfrentamiento
            </summary>
            <pre class="ap-hint mt-2 whitespace-pre-wrap break-words">{{ trim($match->notes) }}</pre>
        </details>
    @endif

    <form method="POST" action="{{ route('admin.matches.resolve', $match) }}" id="ap-decision-form">
        @csrf

        <div class="grid gap-3 md:grid-cols-2">
            <div class="ap-field">
                <label class="ap-label" for="d-action">Decision</label>
                <select name="action" id="d-action" class="ap-select">
                    @if($report && $report->status === 'pending_confirmation')
                        <option value="confirm_report">Confirmar el reporte por el rival</option>
                    @endif
                    <option value="force_complete">Cerrar con un resultado</option>
                    <option value="dispute">Abrir disputa y congelar</option>
                    <option value="abandonment_walkover">Alguien abandono: derrota y sancion</option>
                    <option value="support_infraction">Infraccion de soporte</option>
                    <option value="void">{{ $yaPuntuado ? 'Anular y devolver los puntos' : 'Anular sin puntos' }}</option>
                    {{-- Interrumpido: alguien ajeno al PvP se metio y el combate
                         no pudo decidirse. Ni abandono -nadie se fue- ni
                         anulacion -no hubo reporte malo-. No cuenta y no
                         castiga a nadie. --}}
                    <option value="interrupted">Interrumpido por un jugador externo</option>
                </select>
            </div>

            <div class="ap-field" data-ap-when="force_complete">
                <label class="ap-label" for="d-winner">Quien gano</label>
                <select name="winner_team" id="d-winner" class="ap-select">
                    <option value="team_a" @selected($claimed === 'team_a')>Equipo A · {{ $realmName($match->team_a_realm) }}</option>
                    <option value="team_b" @selected($claimed === 'team_b')>Equipo B · {{ $realmName($match->team_b_realm) }}</option>
                    <option value="draw" @selected($claimed === 'draw')>Empate, sin ganador</option>
                </select>
                <span class="ap-hint">Viene preseleccionado lo que dice el reporte, si lo hay.</span>
            </div>

            {{-- Sin JavaScript se ven todos los campos. Es feo pero honesto: si
                 este selector estuviera oculto por defecto y el script fallara,
                 al elegir "abandono" se sancionaria al primer jugador de la
                 lista sin que nadie lo viera. --}}
            <div class="ap-field" data-ap-when="abandonment_walkover support_infraction">
                <label class="ap-label" for="d-player">Jugador afectado</label>
                <select name="player_id" id="d-player" class="ap-select">
                    @foreach($match->getAllPlayers() as $player)
                        <option value="{{ $player['player_id'] }}">{{ $player['character_name'] }}</option>
                    @endforeach
                </select>
                <span class="ap-hint">Su bando pierde la partida y el jugador queda bloqueado.</span>
            </div>

            <div class="ap-field md:col-span-2">
                <label class="ap-label" for="d-note">Nota interna (opcional)</label>
                <textarea name="note" id="d-note" rows="2" class="ap-textarea"
                          placeholder="Por que decides esto. Queda guardado con el caso."></textarea>
            </div>
        </div>

        <div class="ap-decision-summary" id="ap-decision-summary"></div>

        <button type="submit" class="ap-btn ap-btn-primary mt-3" id="ap-decision-submit">Confirmar decision</button>
    </form>
</section>

{{-- Auditoria de zona --}}
<div id="modal-admin-zone-map" class="ap-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="ap-zone-title">
    <div class="ap-modal-backdrop" data-modal-close="modal-admin-zone-map"></div>
    <div class="ap-modal-panel">
        <div class="ap-section-head">
            <div>
                <h2 class="ap-section-title" id="ap-zone-title">{{ $match->zone_name }}</h2>
                <p class="ap-section-note">Zona asignada a este enfrentamiento.</p>
            </div>
            <button type="button" class="ap-icon-btn" data-modal-close="modal-admin-zone-map" aria-label="Cerrar">
                <x-admin.icon name="close" class="h-4 w-4" />
            </button>
        </div>
        <x-arena-zone-map :zone-key="$match->zone_key" :meeting-point="$match->meeting_point" height="420px" />
    </div>
</div>

@push('scripts')
<script>
    // La decision manda: los campos que no aplican se ocultan y el resumen
    // dice en una frase que va a pasar al confirmar.
    (function () {
        const form = document.getElementById('ap-decision-form');
        if (!form) return;

        const select = document.getElementById('d-action');
        const summary = document.getElementById('ap-decision-summary');
        const submit = document.getElementById('ap-decision-submit');
        const groups = form.querySelectorAll('[data-ap-when]');

        const copy = {
            confirm_report: {
                text: 'Se aplica el reporte tal cual, como si el rival lo hubiera confirmado. Sirve para ensayar el flujo completo y para desatascar un reporte que el rival no va a contestar.',
                label: 'Confirmar por el rival',
                confirm: 'Vas a dar por bueno el reporte y mover el ranking.',
                danger: false,
            },
            force_complete: {
                text: 'Se cierra el enfrentamiento con el resultado elegido y se reparten puntos y MMR entre todos los participantes.',
                label: 'Cerrar y repartir puntos',
                confirm: 'Vas a cerrar el enfrentamiento y mover el ranking.',
                danger: false,
            },
            dispute: {
                text: 'El enfrentamiento queda congelado en disputa. No reparte puntos hasta que lo resuelvas.',
                label: 'Abrir disputa',
                confirm: null,
                danger: false,
            },
            abandonment_walkover: {
                text: 'El bando del jugador elegido pierde, y el jugador recibe bloqueo de cola y baja de confianza.',
                label: 'Aplicar derrota por abandono',
                confirm: 'Vas a dar la partida por perdida a su bando y sancionar al jugador.',
                danger: true,
            },
            support_infraction: {
                text: 'Se sanciona al jugador por incumplir las reglas de soporte, con bloqueo y perdida de confianza.',
                label: 'Aplicar sancion',
                confirm: 'Vas a sancionar al jugador por infraccion de soporte.',
                danger: true,
            },
            interrupted: {
                text: 'Un jugador ajeno al PvP interrumpio el combate. No cuenta para nadie, no reparte puntos y no sanciona a nadie. Si ya habia puntuado, se devuelven.',
                label: 'Marcar como interrumpido',
                confirm: 'Vas a dejar el enfrentamiento sin efecto para los cuatro.',
                danger: true,
            },
            void: @json($textoAnular),
        };

        const render = () => {
            const action = select.value;
            // Sin respaldo, una opcion sin entrada aqui reventaba en la linea
            // de abajo y dejaba el resumen, la etiqueta del boton y el aviso de
            // confirmacion con los del action ANTERIOR: el admin leia "cerrar y
            // repartir puntos" mientras iba a hacer lo contrario.
            const info = copy[action] ?? {
                text: 'Revisa la decision antes de confirmar.',
                label: 'Aplicar decision',
                confirm: 'Vas a aplicar esta decision sobre el enfrentamiento.',
                danger: true,
            };

            groups.forEach((group) => {
                group.hidden = !group.dataset.apWhen.split(' ').includes(action);
            });

            summary.textContent = info.text;
            summary.classList.toggle('ap-decision-danger', info.danger);
            submit.textContent = info.label;
            submit.classList.toggle('ap-btn-danger', info.danger);
            submit.classList.toggle('ap-btn-primary', !info.danger);
            form.setAttribute('data-ap-confirm', info.confirm || '');
            if (!info.confirm) form.removeAttribute('data-ap-confirm');
        };

        select.addEventListener('change', render);
        render();
    })();
</script>
@endpush
@endsection
