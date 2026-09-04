@extends('layouts.arena')

@section('title', 'Match ' . $match->match_code . ' - Regnum Arena Ladder')

@section('content')
@php
    $userPlayerIds = auth()->user()->players()->pluck('id')->all();
    $viewerPlayer = $match->getAllPlayers()->first(function ($player) use ($userPlayerIds) {
        return in_array((int) ($player['player_id'] ?? 0), $userPlayerIds, true);
    });

    $viewerSide = $viewerPlayer
        ? $match->getTeamSideForPlayer((int) $viewerPlayer['player_id'], $viewerPlayer['discord_id'] ?? null)
        : 'team_a';

    $ownSide = $viewerSide; // viewerSide always has a fallback, no ?? needed
    $rivalSide = $ownSide === 'team_a' ? 'team_b' : 'team_a';
    $ownTeam = $match->getTeamBySide($ownSide);
    $rivalTeam = $match->getTeamBySide($rivalSide);
    $ownRealm = $ownSide === 'team_a' ? $match->team_a_realm : $match->team_b_realm;
    $rivalRealm = $rivalSide === 'team_a' ? $match->team_a_realm : $match->team_b_realm;
    // Use pre-loaded teamQueues from controller (no additional DB query)
    $viewerQueue = $viewerPlayer
        ? ($teamQueues[$viewerPlayer['player_id']] ?? null)
        : null;
    $report = $match->report;
    $canReport = $match->status === 'in_progress' && !$report && $viewerPlayer;
    $canConfirmReport = $report && $report->status === 'pending_confirmation' && $viewerSide !== $report->reporting_team;
    $canRejectReport = $canConfirmReport;
    $reportPendingConfirmation = $report && $report->status === 'pending_confirmation';
    $showRivalNames = in_array($match->status, ['completed', 'disputed', 'void'], true);

    // Aspecto de cada combatiente para las figuras 3D. El propio equipo va con
    // su raza y su sexo reales; al rival se le dibuja con el maniqui humano del
    // reino mientras siga siendo anonimo, porque raza y sexo sumados al reino y
    // la subclase ayudarian a ponerle nombre.
    $lookIds = collect($ownTeam)->pluck('player_id');
    if ($showRivalNames) {
        $lookIds = $lookIds->merge(collect($rivalTeam)->pluck('player_id'));
    }
    $looks = \App\Models\Player::query()
        ->whereIn('id', $lookIds->filter()->all())
        ->get(['id', 'race', 'gender'])
        ->keyBy('id');
    $lookOf = function (array $entry, string $realm) use ($looks) {
        $found = $looks->get($entry['player_id'] ?? 0);

        return [
            'race' => $found->race ?? \App\Models\Player::defaultRace($realm),
            'gender' => $found->gender ?? 'male',
        ];
    };
    $claimedWinnerRealm = $report
        ? ($report->claimed_winner_team === 'draw' ? null : ($report->claimed_winner_team === 'team_a' ? $match->team_a_realm : $match->team_b_realm))
        : null;

    // Los mismos cuatro pasos que el lobby, con los mismos nombres. Esta
    // pagina contaba cinco y con otras palabras, asi que llegar aqui desde el
    // lobby parecia cambiar de aplicacion en el ultimo paso.
    $stepperCurrent = match(true) {
        $match->status === 'pending_acceptance' => 3,
        default => 4,
    };

    $statusClass = match($match->status) {
        'pending_acceptance' => 'arena-status-pending',
        'in_progress' => 'arena-status-active',
        'completed' => 'arena-status-completed',
        'disputed' => 'arena-status-disputed',
        'void', 'cancelled' => 'arena-status-void',
        default => 'arena-status-pending',
    };
@endphp

<div class="mx-auto max-w-6xl px-4 py-8">
    {{-- Breadcrumbs --}}
    <x-arena-breadcrumbs :items="[
        ['label' => 'Matches', 'url' => route('matches.index')],
        ['label' => $match->match_code],
    ]" class="mb-6" />

    {{-- ── HERO ── --}}
    <section class="arena-panel-strong mb-6 p-6 md:p-8 arena-animate-in relative overflow-hidden">
        {{-- Realm glow decorations --}}
        <div class="absolute -top-16 -left-16 w-48 h-48 rounded-full pointer-events-none opacity-20"
             style="background: radial-gradient(circle, {{ $ownRealm === 'ignis' ? 'rgba(211,100,47,0.5)' : ($ownRealm === 'alsius' ? 'rgba(121,181,214,0.5)' : 'rgba(142,179,74,0.5)') }}, transparent 70%)">
        </div>
        <div class="absolute -top-16 -right-16 w-48 h-48 rounded-full pointer-events-none opacity-20"
             style="background: radial-gradient(circle, {{ $rivalRealm === 'ignis' ? 'rgba(211,100,47,0.5)' : ($rivalRealm === 'alsius' ? 'rgba(121,181,214,0.5)' : 'rgba(142,179,74,0.5)') }}, transparent 70%)">
        </div>

        <div class="relative flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <div class="flex flex-wrap items-center gap-3">
                    <p class="arena-kicker">{{ $match->queue_mode_name }}</p>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusClass }}">{{ $match->status_name }}</span>
                </div>
                <h1 class="mt-3 text-4xl font-bold text-white md:text-5xl">{{ $match->match_code }}</h1>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 text-[color:var(--arena-sand)] arena-body-text">
                        <x-arena-realm-icon :realm="$ownRealm" size="sm" />
                        {{ \App\Models\ArenaMatch::REALMS[$ownRealm] ?? strtoupper($ownRealm) }}
                    </span>
                    <span class="text-[color:var(--arena-muted)]">vs</span>
                    <span class="inline-flex items-center gap-1.5 text-[color:var(--arena-sand)] arena-body-text">
                        <x-arena-realm-icon :realm="$rivalRealm" size="sm" />
                        {{ \App\Models\ArenaMatch::REALMS[$rivalRealm] ?? strtoupper($rivalRealm) }}
                    </span>
                    <span class="arena-chip border-[color:var(--arena-line)]">
                        <svg class="h-3.5 w-3.5 mr-1 text-[color:var(--arena-gold)] opacity-50" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
                        {{ $match->zone_name }}
                    </span>
                    <span class="arena-chip font-mono">{{ $match->report_token }}</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('matches.index') }}" class="arena-btn-ghost">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
                    Mis matches
                </a>
                <button type="button" class="flex items-center gap-2 rounded-lg border border-[color:var(--arena-gold-soft)]/40 bg-[color:var(--arena-gold-soft)]/15 px-4 py-2 text-sm font-bold tracking-wide text-[#f4deb1] transition-all hover:bg-[color:var(--arena-gold-soft)]/25 shadow-[0_0_15px_rgba(216,177,92,0.15)] ring-1 ring-inset ring-white/10" data-modal-open="modal-zone-map">
                    <svg class="h-4 w-4 drop-shadow-md" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
                    Ver Mapa
                </button>
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.matches.show', $match) }}" class="arena-btn px-4 py-2">Admin</a>
                @endif
            </div>
        </div>

        {{-- Stepper --}}
        <div class="relative mt-6">
            <x-arena-stepper
                :steps="['Registra', 'Elige modo', 'Espera cruce', 'Pelea y reporta']"
                :current="$stepperCurrent"
            />
        </div>
    </section>

    {{-- ── ACTION PANEL (the one action the user needs right now) ── --}}
    @if($match->status === 'pending_acceptance' && !$match->isExpired() && $viewerQueue && $viewerQueue->status === 'matched')
        <section class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-1 border-l-4 border-l-amber-500/60">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3 mb-1">
                        <p class="arena-kicker m-0">Acción requerida</p>
                        <span id="statePollingActive" class="arena-chip text-xs bg-black/40 border border-emerald-500/30 text-emerald-300 px-2 py-1">
                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-400 mr-1 animate-pulse"></span>
                            Auto-sync
                        </span>
                    </div>
                    <h2 class="mt-1 text-2xl font-semibold text-[color:var(--arena-gold-soft)]">Confirma tu disponibilidad</h2>
                    <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">Los {{ \App\Support\ArenaMode::teamSize($match->arena_mode) * 2 }} jugadores deben aceptar para que comience la cacería.</p>
                    <div class="mt-2 text-xs text-[color:var(--arena-gold)] font-mono bg-black/30 inline-block px-3 py-1 rounded-md border border-[color:var(--arena-gold)]/20">
                        Expira en: <span id="matchAcceptanceCountdown" data-expires="{{ $match->expires_at?->timestamp }}">--:--</span>
                    </div>

                    <script>
                        function updateMatchAcceptanceTimer() {
                            const span = document.getElementById('matchAcceptanceCountdown');
                            if (!span || !span.dataset.expires) return;
                            const expiresAt = parseInt(span.dataset.expires, 10);
                            const now = Math.floor(Date.now() / 1000);
                            const diff = Math.max(0, expiresAt - now);
                            
                            if (diff <= 0) {
                                span.innerText = "00:00";
                                span.classList.replace('text-[color:var(--arena-gold)]', 'text-red-500');
                            } else {
                                const m = Math.floor(diff / 60).toString().padStart(2, '0');
                                const s = (diff % 60).toString().padStart(2, '0');
                                span.innerText = `${m}:${s}`;
                            }
                        }
                        setInterval(updateMatchAcceptanceTimer, 1000);
                        updateMatchAcceptanceTimer();
                    </script>
                </div>
                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('matches.accept') }}">
                        @csrf
                        <input type="hidden" name="match_id" value="{{ $match->id }}">
                        <input type="hidden" name="player_id" value="{{ $viewerPlayer['player_id'] }}">
                        <button type="submit" class="arena-btn-safe">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Aceptar match
                        </button>
                    </form>
                    <button type="button" class="arena-btn-danger-ghost" data-modal-open="modal-reject-match">Rechazar</button>
                </div>
            </div>
        </section>

        <x-arena-modal id="modal-reject-match" title="¿Rechazar este match?" variant="danger">
            <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">
                Si rechazas, el match se cancela y los demás jugadores vuelven a la cola. Rechazos frecuentes pueden generar sanciones.
            </p>
            <div class="mt-5 flex gap-3">
                <form method="POST" action="{{ route('matches.reject') }}">
                    @csrf
                    <input type="hidden" name="match_id" value="{{ $match->id }}">
                    <input type="hidden" name="player_id" value="{{ $viewerPlayer['player_id'] }}">
                    <button type="submit" class="arena-btn-danger">Confirmar rechazo</button>
                </form>
                <button type="button" class="arena-btn-ghost" data-modal-close="modal-reject-match">Cancelar</button>
            </div>
        </x-arena-modal>
    @elseif($match->status === 'pending_acceptance' && !$match->isExpired() && $viewerQueue && $viewerQueue->status === 'accepted')
        <section class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-1">
            <div class="flex items-start gap-3 rounded-2xl border border-emerald-500/25 bg-emerald-950/20 px-5 py-4 text-emerald-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                <div>
                    <p class="font-semibold">Ya aceptaste este match</p>
                    <p class="mt-1 text-sm text-emerald-200/70 arena-body-text">Esperando que el resto del grupo confirme su disponibilidad…</p>
                </div>
            </div>
        </section>
    @elseif($canReport)
        <section class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-1 border-l-4 border-l-emerald-500/60">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="arena-kicker">Acción requerida</p>
                    <h2 class="mt-1 text-2xl font-semibold text-emerald-300">Sube el reporte del combate</h2>
                    <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                        Adjunta entre 1 y 3 capturas del combate terminado y elige al ganador.
                    </p>
                </div>
                <a href="#report-panel" class="arena-btn-safe">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    Ir al formulario
                </a>
            </div>
        </section>
    @elseif($canConfirmReport)
        <section class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-1 border-l-4 border-l-sky-500/60">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="arena-kicker">Acción requerida</p>
                    <h2 class="mt-1 text-2xl font-semibold text-[color:var(--arena-ice)]">Confirma o disputa el reporte rival</h2>
                    <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">Revisa las capturas y decide si el resultado es correcto.</p>
                </div>
                <a href="#report-panel" class="arena-btn-secondary">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    Ir al reporte
                </a>
            </div>
        </section>
    @elseif($match->status === 'completed')
        <section class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-1">
            <div class="flex items-start gap-3 rounded-2xl border border-emerald-500/25 bg-emerald-950/20 px-5 py-4 text-emerald-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                <div>
                    <p class="font-semibold">Match resuelto — {{ $match->winner_realm ? 'Ganador: ' . (\App\Models\ArenaMatch::REALMS[$match->winner_realm] ?? strtoupper((string) $match->winner_realm)) : 'Resultado: Empate' }}</p>
                    <p class="mt-1 text-sm text-emerald-200/70 arena-body-text">El ladder ya fue actualizado con los resultados de este encuentro.</p>
                </div>
            </div>
        </section>
    @elseif($match->status === 'disputed')
        <section class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-1">
            <div class="flex items-start gap-3 rounded-2xl border border-amber-500/25 bg-amber-950/20 px-5 py-4 text-amber-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                <div>
                    <p class="font-semibold">Match en disputa — esperando moderación</p>
                    <p class="mt-1 text-sm text-amber-200/70 arena-body-text">El flujo automático se detuvo. Un administrador revisará la evidencia.</p>
                </div>
            </div>
        </section>
    @elseif(in_array($match->status, ['cancelled', 'void']))
        <section class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-1">
            <div class="flex items-start gap-4 rounded-2xl border border-rose-500/25 bg-rose-950/20 px-5 py-5 text-rose-100 flex-col sm:flex-row sm:items-center">
                <svg class="h-8 w-8 shrink-0 text-rose-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                <div class="flex-1">
                    <h2 class="text-xl font-bold text-rose-300">Encuentro Cancelado</h2>
                    <p class="mt-1 text-sm text-rose-200/80 arena-body-text">El combate se deshizo porque alguien se ausentó o rechazó. Serás redirigido al lobby...</p>
                </div>
                <a href="{{ route('lobby') }}" class="arena-btn-danger mt-3 sm:mt-0 whitespace-nowrap">Volver al Lobby</a>
            </div>
            <script>
                setTimeout(() => {
                    window.location.href = '{{ route('lobby') }}';
                }, 3500);
            </script>
        </section>
    @elseif($reportPendingConfirmation && $viewerSide === $report->reporting_team)
        <section class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-1">
            <div class="flex items-start gap-3 rounded-2xl border border-sky-500/25 bg-sky-950/20 px-5 py-4 text-sky-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-sky-400 animate-pulse" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                <div>
                    <p class="font-semibold">Reporte enviado — esperando confirmación rival</p>
                    <p class="mt-1 text-sm text-sky-200/70 arena-body-text">
                        El equipo rival debe confirmar o disputar tu reporte.
                        @if($match->expires_at)
                            Tiempo restante: {{ $match->expires_at->locale('es')->diffForHumans() }}
                        @endif
                    </p>
                </div>
            </div>
        </section>
    @endif

    {{-- ── TEAMS ── --}}
    <div class="mb-6 grid gap-6 lg:grid-cols-2 arena-animate-in arena-stagger-2">
        {{-- Own team --}}
        <section class="arena-panel arena-card-{{ $ownRealm }} p-6">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <x-arena-realm-icon :realm="$ownRealm" size="md" />
                    <div>
                        <p class="arena-kicker">Tu equipo</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">{{ \App\Models\ArenaMatch::REALMS[$ownRealm] ?? strtoupper($ownRealm) }}</h2>
                    </div>
                </div>
            </div>
            <div class="mt-4 space-y-3">
                @foreach($ownTeam as $player)
                    @php
                        // Use pre-loaded teamQueues — no additional DB query per player
                        $teamQueue = $teamQueues[$player['player_id']] ?? null;
                        $isViewer = $viewerPlayer && (int)$viewerPlayer['player_id'] === (int)$player['player_id'];
                    @endphp
                    <article class="arena-card p-4 {{ $isViewer ? 'border-[color:var(--arena-gold)]/30' : '' }}">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                @if($isViewer)
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-[color:var(--arena-gold)]/20 text-[color:var(--arena-gold)]">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
                                    </span>
                                @endif
                                @php
                                    $look = $lookOf($player, $ownRealm);
                                @endphp
                                <x-arena-champion
                                    :id="'match-own-' . $loop->index"
                                    :realm="$ownRealm"
                                    :subclass="$player['subclass']"
                                    :race="$look['race']"
                                    :gender="$look['gender']"
                                    :parallax="false"
                                    height="72px"
                                    class="arena-duel-portrait" />
                                <div>
                                    <h3 class="font-semibold text-white arena-body-text">{{ $player['character_name'] }} {{ $isViewer ? '(tú)' : '' }}</h3>
                                    <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">
                                        {{ \App\Models\Player::SUBCLASSES[$player['subclass']] ?? ucfirst($player['subclass']) }}
                                        @if(!empty($player['conjurer_role']))
                                            · {{ ucfirst($player['conjurer_role']) }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                            @if($match->status === 'pending_acceptance')
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $teamQueue && $teamQueue->status === 'accepted' ? 'bg-emerald-900/30 text-emerald-300' : 'bg-amber-900/30 text-amber-200' }}">
                                    @if($teamQueue && $teamQueue->status === 'accepted')
                                        <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                        Listo
                                    @else
                                        Pendiente
                                    @endif
                                </span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        {{-- Rival team --}}
        <section class="arena-panel arena-card-{{ $rivalRealm }} p-6">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <x-arena-realm-icon :realm="$rivalRealm" size="md" />
                    <div>
                        <p class="arena-kicker">Rival</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">{{ \App\Models\ArenaMatch::REALMS[$rivalRealm] ?? strtoupper($rivalRealm) }}</h2>
                    </div>
                </div>
                <span class="arena-chip text-xs">{{ $showRivalNames ? 'Revelado' : 'Anónimo' }}</span>
            </div>
            <div class="mt-4 space-y-3">
                @if($showRivalNames)
                    @foreach($rivalTeam as $player)
                        @php
                            $look = $lookOf($player, $rivalRealm);
                        @endphp
                        <article class="arena-card p-4 flex items-center gap-3">
                            <x-arena-champion
                                :id="'match-rival-' . $loop->index"
                                :realm="$rivalRealm"
                                :subclass="$player['subclass']"
                                :race="$look['race']"
                                :gender="$look['gender']"
                                :parallax="false"
                                height="72px"
                                class="arena-duel-portrait" />
                            <div class="min-w-0">
                                <h3 class="font-semibold text-white arena-body-text">{{ $player['character_name'] }}</h3>
                                <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">{{ \App\Models\Player::SUBCLASSES[$player['subclass']] ?? ucfirst($player['subclass']) }}</p>
                            </div>
                        </article>
                    @endforeach
                @else
                    @foreach($rivalTeam as $player)
                        <article class="arena-card p-4 flex items-center gap-3">
                            <x-arena-champion
                                :id="'match-anon-' . $loop->index"
                                :realm="$rivalRealm"
                                :subclass="$player['subclass']"
                                :race="\App\Models\Player::defaultRace($rivalRealm)"
                                gender="male"
                                :parallax="false"
                                height="72px"
                                class="arena-duel-portrait" />
                            <div class="min-w-0 flex-1">
                                <h3 class="font-semibold text-[color:var(--arena-text)] arena-body-text italic">Guerrero Anónimo</h3>
                                <p class="text-xs text-[color:var(--arena-gold-soft)] arena-body-text">{{ \App\Models\Player::SUBCLASSES[$player['subclass']] ?? ucfirst($player['subclass']) }}</p>
                            </div>
                            <svg class="h-5 w-5 opacity-40 text-[color:var(--arena-muted)]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"/><path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z"/></svg>
                        </article>
                    @endforeach
                @endif
            </div>
        </section>
    </div>

    {{-- ── REPORT & EVIDENCE PANEL ── --}}
    <section id="report-panel" class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-3">
        <p class="arena-kicker">{{ $canReport ? 'Reporte' : 'Evidencia' }}</p>
        <h2 class="mt-1 text-2xl font-semibold text-white">
            {{ $canReport ? 'Formulario de reporte' : ($report ? 'Reporte y capturas' : 'Sin reporte') }}
        </h2>

        @if($canReport)
            <form id="report-form" method="POST" action="{{ route('matches.report') }}" enctype="multipart/form-data" class="mt-5 space-y-5 relative">
                @csrf
                <input type="hidden" name="match_id" value="{{ $match->id }}">
                <input type="hidden" name="player_id" value="{{ $viewerPlayer['player_id'] }}">

                {{-- Upload Progress Overlay --}}
                <div id="upload-progress-overlay" class="absolute inset-0 z-10 hidden flex-col items-center justify-center rounded-2xl bg-black/80 backdrop-blur-md">
                    <p class="text-lg font-semibold text-[color:var(--arena-text)] font-['Cinzel']">Subiendo evidencia...</p>
                    <div class="mt-4 w-64 h-2 rounded-full bg-black/50 border border-[color:var(--arena-line)] overflow-hidden">
                        <div id="upload-progress-bar" class="h-full bg-[color:var(--arena-forest)] transition-all ease-out duration-150" style="width: 0%"></div>
                    </div>
                    <p id="upload-progress-text" class="mt-2 text-sm text-[color:var(--arena-gold-soft)] font-mono">0%</p>
                </div>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Equipo ganador</span>
                    <select name="claimed_winner_team" class="arena-select">
                        <option value="{{ $ownSide }}">Tu equipo ({{ \App\Models\ArenaMatch::REALMS[$ownRealm] ?? strtoupper($ownRealm) }})</option>
                        <option value="{{ $rivalSide }}">Rival ({{ \App\Models\ArenaMatch::REALMS[$rivalRealm] ?? strtoupper($rivalRealm) }})</option>
                        <option value="draw">Empate (Interrumpido / Inconcluso)</option>
                    </select>
                </label>

                <div class="arena-card p-4">
                    <label class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Capturas del combate terminado</label>
                    <input type="file" name="evidence_files[]" accept="image/*" class="arena-field text-sm" required multiple>
                    <p class="mt-2 text-xs text-[color:var(--arena-muted)] arena-body-text">Debes subir al menos 1 captura y puedes adjuntar hasta 3. La primera puede ser la principal y las demas sirven como apoyo.</p>
                    <p class="mt-1 text-xs text-[color:var(--arena-muted)] arena-body-text">Formatos permitidos: JPG, PNG, WEBP, GIF, BMP, AVIF o HEIC. Max 10 MB por imagen.</p>
                </div>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Nota opcional</span>
                    <textarea name="reporter_note" rows="3" class="arena-textarea" placeholder="Contexto extra para el rival o el admin"></textarea>
                </label>

                <button type="submit" id="btn-submit-report" class="arena-btn w-full">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm9.707 5.707a1 1 0 00-1.414-1.414L9 12.586l-1.293-1.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    Enviar reporte
                </button>
            </form>

            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const form = document.getElementById('report-form');
                    const overlay = document.getElementById('upload-progress-overlay');
                    const bar = document.getElementById('upload-progress-bar');
                    const text = document.getElementById('upload-progress-text');
                    const btn = document.getElementById('btn-submit-report');

                    if (!form) return;

                    form.addEventListener('submit', (e) => {
                        e.preventDefault();
                        if (btn.disabled) return;
                        
                        btn.disabled = true;
                        btn.classList.add('arena-btn-loading');
                        overlay.style.display = 'flex';
                        overlay.classList.remove('hidden');

                        const xhr = new XMLHttpRequest();
                        const formData = new FormData(form);

                        xhr.upload.addEventListener('progress', (e) => {
                            if (e.lengthComputable) {
                                const percent = Math.round((e.loaded / e.total) * 100);
                                bar.style.width = percent + '%';
                                text.textContent = percent + '%';
                            }
                        });

                        xhr.addEventListener('load', () => {
                            if (xhr.status >= 200 && xhr.status < 300) {
                                window.location.reload();
                            } else {
                                // En caso de error de validación o del server, dejamos que el browser pinte la vista resultante
                                document.open();
                                document.write(xhr.responseText);
                                document.close();
                            }
                        });

                        xhr.addEventListener('error', () => {
                            arenaToast('Error de red al subir la evidencia. Intenta de nuevo.', 'error');
                            overlay.style.display = 'none';
                            btn.disabled = false;
                            btn.classList.remove('arena-btn-loading');
                        });

                        xhr.open('POST', form.action, true);
                        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); 
                        xhr.send(formData);
                    });
                });
            </script>
        @elseif($report)
            <div class="mt-5 space-y-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="arena-card p-4">
                        <p class="text-[0.6rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">Estado</p>
                        @php
                            $reportStatusClass = match($report->status) {
                                'pending_confirmation' => 'text-sky-300',
                                'confirmed' => 'text-emerald-300',
                                'rejected', 'disputed' => 'text-amber-300',
                                default => 'text-white',
                            };
                        @endphp
                        <p class="mt-1 font-semibold {{ $reportStatusClass }} arena-body-text">{{ $report->status_name }}</p>
                    </div>
                    <div class="arena-card p-4">
                        <p class="text-[0.6rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">Ganador reportado</p>
                        <div class="mt-1 flex items-center gap-1.5">
                            @if($claimedWinnerRealm)
                                <x-arena-realm-icon :realm="$claimedWinnerRealm" size="xs" />
                                <span class="font-semibold text-white arena-body-text">{{ \App\Models\ArenaMatch::REALMS[$claimedWinnerRealm] ?? strtoupper((string) $claimedWinnerRealm) }}</span>
                            @else
                                <span class="font-semibold text-amber-300 arena-body-text">Empate</span>
                            @endif
                        </div>
                    </div>
                    <div class="arena-card p-4">
                        <p class="text-[0.6rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">Reportado por</p>
                        {{-- El sistema promete anonimato rival hasta el reveal: mostrar aqui el
                             nombre del reportero lo delataba en cuanto reportaba, mientras el
                             resto de la pagina seguia diciendo "Guerrero Anonimo". --}}
                        <p class="mt-1 font-semibold text-white arena-body-text">
                            @if($showRivalNames || $report->reporting_team === $ownSide)
                                {{ $report->reporter?->character_name ?? 'Sin dato' }}
                            @else
                                Guerrero Anónimo
                            @endif
                        </p>
                    </div>
                </div>

                @if($report->reporter_note)
                    <div class="arena-card p-4">
                        <p class="text-xs font-semibold text-[color:var(--arena-gold-soft)]">Nota del reporter</p>
                        <p class="mt-2 text-sm text-[color:var(--arena-text)] arena-body-text">{{ $report->reporter_note }}</p>
                    </div>
                @endif

                <div class="grid gap-3 sm:grid-cols-{{ count($report->evidenceItems()) > 1 ? '2' : '1' }}">
                    @foreach($report->evidenceItems() as $evidence)
                        <a href="{{ $evidence['url'] }}" target="_blank" class="arena-btn-ghost justify-center">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg>
                            {{ $evidence['label'] }}
                        </a>
                    @endforeach
                </div>

                @if($canConfirmReport)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <form method="POST" action="{{ route('matches.report.confirm') }}">
                            @csrf
                            <input type="hidden" name="report_id" value="{{ $report->id }}">
                            <input type="hidden" name="player_id" value="{{ $viewerPlayer['player_id'] }}">
                            <button type="submit" class="arena-btn-safe w-full">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                Confirmar reporte
                            </button>
                        </form>
                        <div>
                            <button type="button" class="arena-btn-warning w-full" data-modal-open="modal-dispute">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                Rechazar y disputar
                            </button>
                        </div>
                    </div>

                    <x-arena-modal id="modal-dispute" title="¿Enviar a disputa?" variant="warning">
                        <p class="text-sm text-[color:var(--arena-muted)] arena-body-text mb-4">
                            Si rechazas el reporte, el match pasará a moderación. Un administrador revisará la evidencia y decidirá el resultado.
                        </p>
                        <form method="POST" action="{{ route('matches.report.reject') }}" class="space-y-4">
                            @csrf
                            <input type="hidden" name="report_id" value="{{ $report->id }}">
                            <input type="hidden" name="player_id" value="{{ $viewerPlayer['player_id'] }}">
                            <textarea name="rejection_note" rows="3" class="arena-textarea" placeholder="Explica por qué rechazas el reporte"></textarea>
                            <div class="flex gap-3">
                                <button type="submit" class="arena-btn-warning">Enviar a disputa</button>
                                <button type="button" class="arena-btn-ghost" data-modal-close="modal-dispute">Cancelar</button>
                            </div>
                        </form>
                    </x-arena-modal>
                @endif
            </div>
        @else
            <div class="arena-card mt-5 p-5 text-center text-[color:var(--arena-muted)] arena-body-text">
                <p>Aún no se ha cargado ningún reporte para este match.</p>
            </div>
        @endif
    </section>

    {{-- ── LADDER IMPACT (collapsible) ── --}}
    @if($match->results->isNotEmpty())
        <details class="arena-panel arena-animate-in arena-stagger-4 group" open>
            <summary class="cursor-pointer p-6 flex items-center justify-between">
                <div>
                    <p class="arena-kicker">Resultados</p>
                    <h2 class="mt-1 text-2xl font-semibold text-white">Impacto en el ladder</h2>
                </div>
                <svg class="h-5 w-5 text-[color:var(--arena-muted)] transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </summary>
            <div class="px-6 pb-6">
                {{-- Desktop table --}}
                <div class="arena-scroll overflow-x-auto hidden md:block">
                    <table class="arena-table min-w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th class="pb-3 pr-4">Jugador</th>
                                <th class="pb-3 pr-4">Resultado</th>
                                <th class="pb-3 pr-4">PL</th>
                                <th class="pb-3 pr-4">MMR</th>
                                <th class="pb-3 pr-4">Categoría</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($match->results as $result)
                                <tr class="arena-animate-in" style="animation-delay: {{ $loop->index * 60 }}ms">
                                    <td class="py-3 pr-4">
                                        <a href="{{ route('ladder.show', $result->player) }}" class="font-medium text-white hover:text-[color:var(--arena-gold-soft)] transition-colors arena-body-text">
                                            {{ $result->player->character_name }}
                                        </a>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $result->result === 'win' ? 'bg-emerald-900/30 text-emerald-300' : 'bg-rose-900/30 text-rose-300' }}">
                                            {{ strtoupper($result->result) }}
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 arena-body-text">
                                        <span class="text-white">{{ number_format((float) $result->pl_before, 1) }} → {{ number_format((float) $result->pl_after, 1) }}</span>
                                        <span class="ml-1 font-semibold {{ $result->pl_change >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                                            ({{ $result->pl_change >= 0 ? '+' : '' }}{{ number_format((float) $result->pl_change, 1) }})
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 arena-body-text">
                                        <span class="text-white">{{ $result->mmr_before }} → {{ $result->mmr_after }}</span>
                                        <span class="ml-1 font-semibold {{ $result->mmr_change >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                                            ({{ $result->mmr_change >= 0 ? '+' : '' }}{{ $result->mmr_change }})
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 text-[color:var(--arena-muted)] arena-body-text text-xs">
                                        {{ ucfirst((string) data_get($result->scoring_context, 'match_category', 'n/a')) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile cards --}}
                <div class="space-y-3 md:hidden">
                    @foreach($match->results as $result)
                        <article class="arena-card p-4">
                            <div class="flex items-center justify-between gap-3">
                                <a href="{{ route('ladder.show', $result->player) }}" class="font-medium text-white arena-body-text">{{ $result->player->character_name }}</a>
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $result->result === 'win' ? 'bg-emerald-900/30 text-emerald-300' : 'bg-rose-900/30 text-rose-300' }}">
                                    {{ strtoupper($result->result) }}
                                </span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2 text-sm arena-body-text">
                                <span class="{{ $result->pl_change >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                                    PL {{ $result->pl_change >= 0 ? '+' : '' }}{{ number_format((float) $result->pl_change, 1) }}
                                </span>
                                <span class="{{ $result->mmr_change >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                                    MMR {{ $result->mmr_change >= 0 ? '+' : '' }}{{ $result->mmr_change }}
                                </span>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </details>
    @endif

    @if($match->notes)
        <details class="arena-panel mt-6 group">
            <summary class="cursor-pointer p-6 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-white">Notas del sistema</h2>
                <svg class="h-5 w-5 text-[color:var(--arena-muted)] transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </summary>
            <div class="px-6 pb-6">
                <pre class="whitespace-pre-wrap text-sm text-[color:var(--arena-muted)] arena-body-text">{{ $match->notes }}</pre>
            </div>
        </details>
    @endif

    {{-- ── ZONE MAP MODAL ── --}}
    <div id="modal-zone-map" class="fixed inset-0 z-50 items-center justify-center" style="display:none" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" data-modal-close="modal-zone-map"></div>
        <div class="relative mx-4 w-full max-w-3xl rounded-2xl border border-[color:var(--arena-line-strong)] bg-[linear-gradient(180deg,rgba(40,28,20,0.98),rgba(14,10,8,0.99))] p-6 shadow-[0_25px_60px_rgba(0,0,0,0.5)]" style="animation: arenaModalIn 0.2s ease-out">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-[color:var(--arena-gold)] font-['Cinzel']">Zona de combate</p>
                    <h3 class="mt-1 text-xl font-semibold text-white">{{ $match->zone_name }}</h3>
                </div>
                <button type="button" class="shrink-0 rounded-full p-1.5 text-[color:var(--arena-muted)] transition-colors hover:bg-white/10 hover:text-white" data-modal-close="modal-zone-map" aria-label="Cerrar">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>
            </div>
            <x-arena-zone-map :zone-key="$match->zone_key" height="450px" />
        </div>
    </div>
</div>

@if(in_array($match->status, ['pending_acceptance', 'in_progress', 'disputed'], true))
    {{-- Shared state-polling component (replaces inline initializeStatePolling) --}}
    <x-arena-state-poller active="true" />
@endif
@endsection
