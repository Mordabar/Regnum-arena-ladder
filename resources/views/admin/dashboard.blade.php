@extends('layouts.admin')

@section('title', 'Resumen')
@section('page-title', 'Resumen')
@section('page-subtitle', 'Estado de la arena en este momento')

@section('page-actions')
    <form method="POST" action="{{ route('admin.operations.process-queue') }}">
        @csrf
        <button type="submit" class="ap-btn ap-btn-sm">
            <x-admin.icon name="refresh" class="h-3.5 w-3.5" />
            Emparejar ahora
        </button>
    </form>
    <form method="POST" action="{{ route('admin.operations.expire-pending') }}">
        @csrf
        <button type="submit" class="ap-btn ap-btn-sm ap-btn-quiet">Caducar aceptaciones</button>
    </form>
@endsection

@section('content')
@php
    $needsDecision = ($stats['pending_report_confirmation'] ?? 0) + ($stats['disputed'] ?? 0);
@endphp

{{-- 1. Lo unico que exige una persona. Va primero y ocupa el ancho completo
     porque es la razon por la que alguien abre este panel. --}}
<section class="ap-rise mb-6">
    @if($needsDecision > 0)
        <div class="ap-alertband">
            <div class="ap-alertband-body">
            <x-admin.icon name="alert" class="h-5 w-5 shrink-0" style="color: var(--ap-warn)" />
            <div class="min-w-0">
                <p class="m-0 text-[13.5px] font-semibold">
                    {{ $needsDecision }} {{ $needsDecision === 1 ? 'caso espera' : 'casos esperan' }} tu decision
                </p>
                <p class="ap-section-note">
                    {{ $stats['pending_report_confirmation'] }} sin confirmar por el rival ·
                    {{ $stats['disputed'] }} en disputa abierta
                </p>
            </div>
            </div>
            <a href="{{ route('admin.inbox') }}" class="ap-btn ap-btn-primary">
                Revisar bandeja
                <x-admin.icon name="arrow-right" class="h-3.5 w-3.5" />
            </a>
        </div>
    @else
        <div class="ap-alertband ap-alertband-quiet">
            <div class="ap-alertband-body">
            <x-admin.icon name="check" class="h-5 w-5 shrink-0" style="color: var(--ap-ok)" />
            <div class="min-w-0">
                <p class="m-0 text-[13.5px] font-semibold">Nada pendiente de moderacion</p>
                <p class="ap-section-note">No hay reportes sin confirmar ni disputas abiertas.</p>
            </div>
            </div>
        </div>
    @endif
</section>

{{-- 2. Lo que esta pasando ahora. Numeros que cambian solos. --}}
<section class="ap-rise ap-delay-1 mb-6">
    <div class="ap-section-head">
        <div>
            <h2 class="ap-section-title">Actividad en curso</h2>
            <p class="ap-section-note">Se mueve solo con el matchmaking automatico.</p>
        </div>
    </div>
    <div class="grid gap-3 grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('admin.matches.index') }}" class="ap-metric">
            <span class="ap-metric-label"><x-admin.icon name="clock" class="h-3.5 w-3.5" /> En cola</span>
            <span class="ap-metric-value ap-num">{{ $stats['waiting_queues'] }}</span>
            <span class="ap-metric-note">jugadores buscando partida</span>
        </a>
        <a href="{{ route('admin.matches.index', ['status' => 'pending_acceptance']) }}" class="ap-metric {{ $stats['pending_acceptance'] > 0 ? 'ap-metric-warn' : '' }}">
            <span class="ap-metric-label"><x-admin.icon name="check" class="h-3.5 w-3.5" /> Esperando aceptacion</span>
            <span class="ap-metric-value ap-num">{{ $stats['pending_acceptance'] }}</span>
            <span class="ap-metric-note">caducan solas si nadie acepta</span>
        </a>
        <a href="{{ route('admin.matches.index', ['status' => 'in_progress']) }}" class="ap-metric">
            <span class="ap-metric-label"><x-admin.icon name="swords" class="h-3.5 w-3.5" /> En progreso</span>
            <span class="ap-metric-value ap-num">{{ $stats['in_progress'] }}</span>
            <span class="ap-metric-note">jugandose ahora mismo</span>
        </a>
        <a href="{{ route('admin.matches.index', ['status' => 'completed']) }}" class="ap-metric">
            <span class="ap-metric-label"><x-admin.icon name="gauge" class="h-3.5 w-3.5" /> Completados</span>
            <span class="ap-metric-value ap-num">{{ $stats['completed'] }}</span>
            <span class="ap-metric-note">historico con puntos repartidos</span>
        </a>
    </div>
</section>

{{-- 3. La comunidad. Cambia despacio: va despues. --}}
<section class="ap-rise ap-delay-2 mb-6">
    <div class="ap-section-head">
        <div>
            <h2 class="ap-section-title">Jugadores</h2>
            <p class="ap-section-note">Cuentas registradas y sanciones activas.</p>
        </div>
        <a href="{{ route('admin.players.index') }}" class="ap-btn ap-btn-sm ap-btn-quiet">Gestionar</a>
    </div>
    <div class="grid gap-3 grid-cols-2 lg:grid-cols-5">
        <div class="ap-metric">
            <span class="ap-metric-label">Registrados</span>
            <span class="ap-metric-value ap-num">{{ $stats['players'] }}</span>
            <span class="ap-metric-note">personajes creados en total</span>
        </div>
        <div class="ap-metric">
            <span class="ap-metric-label">Habilitados</span>
            <span class="ap-metric-value ap-num">{{ $stats['active_players'] }}</span>
            <span class="ap-metric-note">personajes que pueden entrar en cola</span>
        </div>
        <div class="ap-metric">
            <span class="ap-metric-label">Sin actividad</span>
            <span class="ap-metric-value ap-num">{{ $stats['dormant_players'] }}</span>
            <span class="ap-metric-note">
                <a href="{{ route('admin.players.index', ['status' => 'dormant']) }}" style="color: var(--ap-accent)">habilitados sin entrar en {{ \App\Models\Player::dormancyDays() }}+ dias</a>
            </span>
        </div>
        <div class="ap-metric {{ $stats['locked_players'] > 0 ? 'ap-metric-danger' : '' }}">
            <span class="ap-metric-label">Sancionados</span>
            <span class="ap-metric-value ap-num">{{ $stats['locked_players'] }}</span>
            <span class="ap-metric-note">bloqueados temporalmente</span>
        </div>
        <div class="ap-metric">
            <span class="ap-metric-label">Modos abiertos</span>
            <span class="ap-metric-value" style="font-size:19px">
                @php $open = collect(array_keys(\App\Support\ArenaMode::MODES))->filter(fn ($m) => \App\Support\ArenaMode::isEnabled($m)); @endphp
                {{ $open->isEmpty() ? 'Ninguno' : $open->implode(' · ') }}
            </span>
            <span class="ap-metric-note">
                <a href="{{ route('admin.settings') }}" style="color: var(--ap-accent)">cambiar en reglas del ladder</a>
            </span>
        </div>
    </div>
</section>

{{-- 4. Historial reciente, para contexto. --}}
<div class="grid gap-5 lg:grid-cols-2">
    <section class="ap-card ap-rise ap-delay-3 p-4">
        <div class="ap-section-head">
            <h2 class="ap-section-title">Ultimos enfrentamientos</h2>
            <a href="{{ route('admin.matches.index') }}" class="ap-btn ap-btn-sm ap-btn-quiet">Ver todos</a>
        </div>
        @forelse($recentMatches as $match)
            <a href="{{ route('admin.matches.show', $match) }}" class="ap-list-row">
                <div class="ap-list-main">
                    <div class="ap-list-title">{{ $match->match_code }}</div>
                    <div class="ap-list-meta">
                        <x-admin.realm :realm="$match->team_a_realm" /> vs <x-admin.realm :realm="$match->team_b_realm" />
                        · {{ $match->zone_name }} · <x-admin.ago :date="$match->created_at" />
                    </div>
                </div>
                <x-admin.mode :mode="$match->arena_mode" />
                <x-admin.status :value="$match->status" />
            </a>
        @empty
            <div class="ap-empty">
                <x-admin.icon name="swords" class="h-6 w-6" />
                <p class="m-0">Todavia no se ha jugado ningun enfrentamiento.</p>
            </div>
        @endforelse
    </section>

    <section class="ap-card ap-rise ap-delay-4 p-4">
        <div class="ap-section-head">
            <h2 class="ap-section-title">Ultimos reportes de resultado</h2>
            <a href="{{ route('admin.inbox') }}" class="ap-btn ap-btn-sm ap-btn-quiet">Bandeja</a>
        </div>
        @forelse($recentReports as $report)
            @if($report->match)
                <a href="{{ route('admin.matches.show', $report->match) }}" class="ap-list-row">
                    <div class="ap-list-main">
                        <div class="ap-list-title">{{ $report->match->match_code }}</div>
                        <div class="ap-list-meta">
                            Reportado por {{ $report->reporter?->character_name ?? 'jugador eliminado' }}
                            · <x-admin.ago :date="$report->created_at" />
                        </div>
                    </div>
                    <x-admin.status kind="report" :value="$report->status" />
                </a>
            @endif
        @empty
            <div class="ap-empty">
                <x-admin.icon name="inbox" class="h-6 w-6" />
                <p class="m-0">Nadie ha reportado resultados todavia.</p>
            </div>
        @endforelse
    </section>
</div>
@endsection
