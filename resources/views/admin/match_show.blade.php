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

<div class="grid gap-4 lg:grid-cols-2 mb-4">
    {{-- Reporte y pruebas --}}
    <section class="ap-card ap-rise ap-delay-3 p-4">
        <div class="ap-section-head">
            <h2 class="ap-section-title">Lo que reportaron los jugadores</h2>
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
        <div class="ap-section-head">
            <div>
                <h2 class="ap-section-title">Puntos ya aplicados</h2>
                <p class="ap-section-note">Lo que este enfrentamiento sumo o resto en el ranking.</p>
            </div>
        </div>

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
        <div>
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

    <form method="POST" action="{{ route('admin.matches.resolve', $match) }}" id="ap-decision-form">
        @csrf

        <div class="grid gap-3 md:grid-cols-2">
            <div class="ap-field">
                <label class="ap-label" for="d-action">Decision</label>
                <select name="action" id="d-action" class="ap-select">
                    <option value="force_complete">Cerrar con un resultado</option>
                    <option value="dispute">Abrir disputa y congelar</option>
                    <option value="abandonment_walkover">Alguien abandono: derrota y sancion</option>
                    <option value="support_infraction">Infraccion de soporte</option>
                    <option value="void">Anular sin puntos</option>
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
                <span class="ap-hint">Su equipo pierde la partida y el jugador queda bloqueado.</span>
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
        <x-arena-zone-map :zone-key="$match->zone_key" height="420px" />
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
                text: 'El equipo del jugador elegido pierde, y el jugador recibe bloqueo de cola y baja de confianza.',
                label: 'Aplicar derrota por abandono',
                confirm: 'Vas a dar la partida por perdida a su equipo y sancionar al jugador.',
                danger: true,
            },
            support_infraction: {
                text: 'Se sanciona al jugador por incumplir las reglas de soporte, con bloqueo y perdida de confianza.',
                label: 'Aplicar sancion',
                confirm: 'Vas a sancionar al jugador por infraccion de soporte.',
                danger: true,
            },
            void: {
                text: 'El enfrentamiento se anula. Nadie gana ni pierde puntos, y no cuenta en el historial competitivo.',
                label: 'Anular enfrentamiento',
                confirm: 'Vas a anular este enfrentamiento. No repartira puntos.',
                danger: true,
            },
        };

        const render = () => {
            const action = select.value;
            const info = copy[action];

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
