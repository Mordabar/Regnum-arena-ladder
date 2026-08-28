@extends('layouts.arena')

@php
    $_queuePageTitle = $currentMatch
        ? ($currentMatch->status === 'pending_acceptance' ? 'Match Pendiente — Acepta Ahora' : 'Match Activo')
        : ($currentQueue ? 'Buscando Match…' : 'Arena 2v2');
@endphp
@section('title', $_queuePageTitle . ' — Regnum Arena Ladder')

@section('content')
@php
    $hasRoster = $players->isNotEmpty();
    $hasActiveState = (bool) ($currentQueue || $currentMatch);
    $canJoinQueue = $hasRoster && !$hasActiveState;
    $queueTypeLabel = $currentQueue ? (\App\Models\Queue::QUEUE_TYPES[$currentQueue->queue_type] ?? ucfirst($currentQueue->queue_type)) : null;
    $queueReportPendingConfirmation = $currentMatch?->report && $currentMatch->report->status === 'pending_confirmation';
    
    // Always poll if the user has characters, so they can receive party invites in real-time.
    $shouldPoll = $hasRoster;

    // $shouldAutoRefresh: show the "Auto-sync" badge only when there's an active flow
    $shouldAutoRefresh = $hasActiveState
        || (isset($activeParty) && $activeParty)
        || (isset($pendingInvites) && $pendingInvites->isNotEmpty());

    $stepperCurrent = match(true) {
        !$hasRoster => 1,
        $canJoinQueue => 2,
        (bool)($currentMatch && $currentMatch->status === 'pending_acceptance') => 3,
        (bool)$currentQueue && !$currentMatch => 3,
        (bool)$currentMatch && $currentMatch->status === 'in_progress' && !$currentMatch->report => 4,
        $queueReportPendingConfirmation => 4,
        default => 2,
    };
@endphp

<div class="mx-auto max-w-7xl px-4 py-8">
    {{-- Breadcrumbs --}}
    <x-arena-breadcrumbs :items="[['label' => 'Arena']]" class="mb-6" />

    {{-- ── HERO ── --}}
    <section class="arena-panel-strong mb-6 p-6 md:p-8 arena-animate-in">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <p class="arena-kicker">Buscar combate</p>
                    @if($shouldAutoRefresh)
                        <span id="statePollingActive" class="arena-chip text-xs bg-black/40 border border-emerald-500/30 text-emerald-300 px-2 py-1">
                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-400 mr-1 animate-pulse"></span>
                            Auto-sync
                        </span>
                    @endif
                </div>
                <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)] md:text-5xl">Arena 2v2</h1>
                <p class="mt-3 max-w-3xl text-[color:var(--arena-sand)] arena-body-text">
                    Elige cómo entrar: solo a random o arma tu party premade.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('matches.index') }}" class="arena-btn-secondary px-4 py-2">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/></svg>
                    Mis matches
                </a>
                <a href="{{ route('ladder.index') }}" class="arena-btn-ghost px-4 py-2">Ladder</a>
            </div>
        </div>
        {{-- Stepper --}}
        <div class="mt-6">
            <x-arena-stepper
                :steps="['Registra', 'Elige modo', 'Espera cruce', 'Pelea y reporta']"
                :current="$stepperCurrent"
            />
        </div>
    </section>

    {{-- ── ACTION PANEL: show only the ONE thing the user needs to do ── --}}
    @if($currentMatch)
        <section class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-1 border-l-4 {{ $currentMatch->status === 'pending_acceptance' ? 'border-l-amber-500/60' : ($currentMatch->status === 'in_progress' ? 'border-l-emerald-500/60' : 'border-l-sky-500/60') }}">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="arena-kicker">Match activo</p>
                    @if($currentMatch->status === 'pending_acceptance')
                        <div class="flex items-center gap-3">
                            <h2 class="mt-1 text-2xl font-semibold text-[color:var(--arena-gold-soft)]">Acepta tu match</h2>
                            <span id="queueMatchCountdown" class="mt-1 arena-chip text-xs bg-black/40 border border-red-500/30 text-red-300 px-2 py-1" data-expires="{{ $currentMatch->expires_at?->timestamp }}">--:--</span>
                        </div>
                        <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">Los 4 jugadores deben confirmar. Entra al detalle y acepta.</p>
                        
                        <script>
                            function updateQueueMatchTimer() {
                                const span = document.getElementById('queueMatchCountdown');
                                if (!span || !span.dataset.expires) return;
                                const expiresAt = parseInt(span.dataset.expires, 10);
                                const now = Math.floor(Date.now() / 1000);
                                const diff = Math.max(0, expiresAt - now);
                                
                                if (diff <= 0) {
                                    span.innerText = "00:00";
                                } else {
                                    const m = Math.floor(diff / 60).toString().padStart(2, '0');
                                    const s = (diff % 60).toString().padStart(2, '0');
                                    span.innerText = `${m}:${s}`;
                                }
                            }
                            setInterval(updateQueueMatchTimer, 1000);
                            updateQueueMatchTimer();
                        </script>
                    @elseif($currentMatch->status === 'in_progress' && !$queueReportPendingConfirmation)
                        <h2 class="mt-1 text-2xl font-semibold text-emerald-300">¡A pelear! Sube el reporte</h2>
                        <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">Tu match ya está corriendo. Juega y carga las 2 capturas.</p>
                    @elseif($queueReportPendingConfirmation)
                        <h2 class="mt-1 text-2xl font-semibold text-[color:var(--arena-ice)]">Esperando confirmación rival</h2>
                        <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">El resultado ya fue subido. El rival debe confirmarlo.</p>
                    @else
                        <h2 class="mt-1 text-2xl font-semibold text-white">Match en curso</h2>
                        <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">Revisa el estado y avanza al siguiente paso.</p>
                    @endif
                </div>
                <div class="flex items-center gap-4">
                    <div class="hidden sm:flex items-center gap-3 arena-card p-3 text-sm">
                        <div>
                            <p class="text-[0.6rem] uppercase tracking-[0.16em] text-[color:var(--arena-muted)]">Código</p>
                            <p class="font-mono text-white">{{ $currentMatch->match_code }}</p>
                        </div>
                        <div class="h-8 w-px bg-[color:var(--arena-line)]"></div>
                        <div>
                            <p class="text-[0.6rem] uppercase tracking-[0.16em] text-[color:var(--arena-muted)] mb-1">Zona</p>
                            <button type="button" class="flex items-center gap-2 rounded-lg border border-[color:var(--arena-gold-soft)]/30 bg-[color:var(--arena-gold-soft)]/10 px-3 py-1.5 text-sm font-semibold text-[color:var(--arena-gold-soft)] transition-colors hover:bg-[color:var(--arena-gold-soft)]/20 shadow-inner" data-modal-open="modal-queue-zone-map" title="Mostrar campo de batalla">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
                                <span>Ver mapa de {{ $currentMatch->zone_name }}</span>
                            </button>
                        </div>
                    </div>
                    <a href="{{ route('matches.show', $currentMatch) }}" class="{{ $currentMatch->status === 'pending_acceptance' ? 'arena-btn' : 'arena-btn-safe' }}">
                        @if($currentMatch->status === 'pending_acceptance')
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Aceptar match
                        @else
                            Abrir match
                        @endif
                    </a>
                </div>
            </div>
        </section>
    @elseif($currentQueue)
        <section class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-1">
            <div class="flex items-start gap-3 rounded-2xl border border-sky-500/25 bg-sky-950/20 px-5 py-4 text-sky-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-sky-400 animate-pulse" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                <div class="flex-1">
                    <p class="font-semibold">
                        {{ $currentQueue->queue_type === 'premade' ? 'Tu premade está buscando rival…' : 'Buscando match…' }}
                    </p>
                    <p class="mt-1 text-sm text-sky-200/70 arena-body-text">
                        Modo: {{ $queueTypeLabel }} &middot; Buscando hace <span id="queueWaitTime" class="font-mono text-[color:var(--arena-emerald)]" data-joined="{{ $currentQueue->joined_at?->timestamp }}"></span> &middot; Expira <span id="queueExpires" data-expires="{{ $currentQueue->expires_at?->timestamp }}">{{ $currentQueue->expires_at?->diffForHumans() ?? 'sin límite' }}</span>

                        <script>
                            function updateQueueTimers() {
                                const waitEl = document.getElementById('queueWaitTime');
                                if (waitEl && waitEl.dataset.joined) {
                                    const joinedAt = parseInt(waitEl.dataset.joined, 10);
                                    if (joinedAt) {
                                        const now = Math.floor(Date.now() / 1000);
                                        const diff = Math.max(0, now - joinedAt);
                                        const m = Math.floor(diff / 60).toString().padStart(2, '0');
                                        const s = (diff % 60).toString().padStart(2, '0');
                                        waitEl.innerText = `${m}:${s} min`;
                                    }
                                }
                            }
                            setInterval(updateQueueTimers, 1000);
                            updateQueueTimers();
                        </script>
                    </p>
                </div>
                <form method="POST" action="{{ route('queue.leave') }}">
                    @csrf
                    <input type="hidden" name="player_id" value="{{ $currentQueue->player_id }}">
                    <button type="submit" class="arena-btn-danger-ghost px-4 py-2 text-sm">Salir</button>
                </form>
            </div>
        </section>
    @elseif(!$hasRoster)
        <section class="arena-panel mb-6 p-6 arena-animate-in arena-stagger-1">
            <div class="flex items-start gap-3 rounded-2xl border border-amber-500/25 bg-amber-950/20 px-5 py-4 text-amber-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                <div>
                    <p class="font-semibold">Necesitas un guerrero para entrar a la arena</p>
                    <p class="mt-1 text-sm text-amber-200/70 arena-body-text">Crea tu primer personaje en el lobby para poder buscar combate.</p>
                </div>
                <a href="{{ route('lobby') }}" class="arena-btn-secondary px-4 py-2 text-sm shrink-0">Ir al lobby</a>
            </div>
        </section>
    @endif

    {{-- ── QUEUE MODES (only if user can join) ── --}}
    @if($canJoinQueue)
        <div id="queue-modes" class="grid gap-6 xl:grid-cols-[1.2fr,0.8fr] arena-animate-in arena-stagger-2">
            <section class="arena-panel p-6">
                <p class="arena-kicker">Elige tu modo</p>

                @if(isset($pendingInvites) && $pendingInvites->isNotEmpty())
                    <div class="mb-6 space-y-3">
                        @foreach($pendingInvites as $invite)
                            <div class="arena-card p-4 border border-[color:var(--arena-gold-soft)]/50 bg-[color:var(--arena-gold-soft)]/5">
                                <div class="flex items-start justify-between flex-wrap gap-4">
                                    <div>
                                        <p class="text-sm font-semibold text-white">🎟️ Invitación a Party</p>
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

                {{-- Tab bar --}}
                <div class="mt-4 flex rounded-2xl border border-[color:var(--arena-line)] bg-[rgba(12,8,6,0.7)] p-1" role="tablist">
                    <button type="button" role="tab" aria-selected="true" aria-controls="tab-random" id="tabBtnRandom" class="flex-1 rounded-xl px-4 py-2.5 text-sm font-semibold transition-all bg-[linear-gradient(180deg,rgba(63,45,31,0.85),rgba(22,15,11,0.95))] text-[color:var(--arena-gold-soft)] shadow-[0_4px_16px_rgba(0,0,0,0.2),inset_0_1px_0_rgba(255,215,134,0.12)]">
                        ⚡ Random 2v2
                    </button>
                    <button type="button" role="tab" aria-selected="false" aria-controls="tab-premade" id="tabBtnPremade" class="flex-1 rounded-xl px-4 py-2.5 text-sm font-semibold transition-all text-[color:var(--arena-muted)] hover:text-[color:var(--arena-sand)] hover:bg-white/[0.04]">
                        👥 Premade 2v2
                    </button>
                </div>

                {{-- Random tab --}}
                <div id="tab-random" role="tabpanel" class="mt-6" style="animation: arenaFadeIn 0.25s ease-out">
                    <div class="flex items-start justify-between gap-4 mb-4">
                        <div>
                            <h2 class="text-xl font-semibold text-white">Random 2v2</h2>
                            <p class="mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                                Entras con un solo personaje. El sistema busca 1 aliado de tu reino.
                            </p>
                        </div>
                        <span class="arena-chip text-[color:var(--arena-gold-soft)]">1 slot</span>
                    </div>

                    <form method="POST" action="{{ route('queue.join') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="queue_type" value="random">

                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Personaje</span>
                            <select id="playerSelect" name="player_id" class="arena-select" required>
                                <option value="">Selecciona un personaje</option>
                                @foreach($players as $player)
                                    <option value="{{ $player->id }}" data-subclass="{{ $player->subclass }}" @disabled($player->isQueueLocked())>
                                        {{ $player->character_name }} · {{ \App\Models\Player::REALMS[$player->realm] ?? ucfirst($player->realm) }} · {{ number_format((float) $player->pl_points, 1) }} PL{{ $player->isQueueLocked() ? ' · BLOQUEADO' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <div id="conjurerRoleDiv" class="hidden arena-card p-4">
                            <label for="randomConjurerRole" class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Rol del conjurador</label>
                            <select id="randomConjurerRole" name="conjurer_role" class="arena-select" disabled>
                                <option value="offensive">Ofensivo</option>
                                <option value="support">Soporte</option>
                            </select>
                            <p class="mt-2 text-xs text-[color:var(--arena-muted)] arena-body-text">Solo un conjurador soporte por equipo.</p>
                        </div>

                        <button type="submit" class="arena-btn-safe w-full">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                            Entrar a Random 2v2
                        </button>
                    </form>
                </div>

                {{-- Premade tab --}}
                <div id="tab-premade" role="tabpanel" class="mt-6 hidden">
                    <div class="flex items-start justify-between gap-4 mb-4">
                        <div>
                            <h2 class="text-xl font-semibold text-white">Premade / Party 2v2</h2>
                            <p class="mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                                Forma tu escuadra con 1 aliado y lánzate a la arena.
                            </p>
                        </div>
                        <span class="arena-chip text-[color:var(--arena-ice)]">{{ $premadeDailyLimit }}/día</span>
                    </div>

                    @if(isset($activeParty) && $activeParty)
                        <div class="arena-card p-6 border border-[color:var(--arena-gold-soft)]/30">
                            <h3 class="text-xl font-bold text-white mb-2 flex items-center gap-2">
                                ⚔️ Party Activa ({{ \App\Models\Player::REALMS[$activeParty->realm] ?? strtoupper($activeParty->realm) }})
                            </h3>
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
                                        <form method="POST" action="{{ route('party.enqueue', $activeParty) }}" class="flex-1 w-full md:w-auto">
                                            @csrf
                                            <button class="arena-btn-safe w-full justify-center py-3">▶ Iniciar Búsqueda Matchmaking</button>
                                        </form>
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
                    @else
                        <form method="POST" action="{{ route('party.create') }}" class="space-y-4" id="premadeForm">
                        @csrf
                        <input type="hidden" name="queue_type" value="premade">

                        {{-- Leader --}}
                        <div class="arena-card p-4">
                            <label for="partyLeaderSelect" class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Slot 1 — Tu líder</label>
                            <select id="partyLeaderSelect" name="party_player_ids[]" class="arena-select" required>
                                <option value="">Selecciona tu personaje líder</option>
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

                        {{-- Slot 2 --}}
                        @foreach([2] as $slot)
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
                                <p class="mt-2 text-xs text-[color:var(--arena-muted)] arena-body-text">La dupla no puede tener 2 conjuradores soporte.</p>
                            </div>
                        @endforeach

                        {{-- Summary --}}
                        <div class="arena-card p-4">
                            <p class="text-sm font-semibold text-white arena-body-text">Dupla en construcción</p>
                            <div id="premadeSummary" class="mt-3 grid gap-3 md:grid-cols-2">
                                @for($slot = 1; $slot <= 2; $slot++)
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

            {{-- Sidebar: roster + rules --}}
            <div class="space-y-6">
                <section id="my-roster" class="arena-panel p-6">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="arena-kicker">Tu escuadra</p>
                            <h2 class="mt-1 text-xl font-semibold text-white">Tus guerreros</h2>
                        </div>
                        <span class="arena-chip">{{ $players->count() }}</span>
                    </div>
                    <div class="mt-4 space-y-3">
                        @foreach($players as $player)
                            <article class="arena-card arena-card-{{ $player->realm }} p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <x-arena-realm-icon :realm="$player->realm" size="sm" />
                                        <div>
                                            <h3 class="font-semibold text-white arena-body-text">{{ $player->character_name }}</h3>
                                            <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">
                                                {{ \App\Models\Player::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass) }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-right text-xs arena-body-text">
                                        <p class="font-semibold text-amber-300">{{ number_format((float) $player->pl_points, 1) }} PL</p>
                                        <p class="text-sky-300">{{ $player->mmr }} MMR</p>
                                    </div>
                                </div>
                                @if($player->isQueueLocked())
                                    <div class="mt-2 rounded-xl border border-rose-500/30 bg-rose-900/20 px-3 py-1.5 text-xs text-rose-200 arena-body-text">
                                        Bloqueado{{ $player->queue_lock_reason_name ? ' · ' . $player->queue_lock_reason_name : '' }}
                                        hasta {{ $player->queue_locked_until?->format('d/m H:i') }}
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>

                <details class="arena-panel group">
                    <summary class="cursor-pointer p-5 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-white arena-body-text">Reglas de la cola</h2>
                        <svg class="h-4 w-4 text-[color:var(--arena-muted)] transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </summary>
                    <div class="px-5 pb-5">
                        <ul class="space-y-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                            <li>• <strong class="text-white">Random:</strong> 1 personaje, el sistema completa tu equipo.</li>
                            <li>• <strong class="text-white">Premade:</strong> dupla exacta, mismo reino, 2 usuarios, {{ $premadeDailyLimit }}/día.</li>
                            <li>• Si un random cruza vs premade, el random gana más o pierde menos.</li>
                            <li>• Solo un conjurador soporte por equipo.</li>
                        </ul>
                    </div>
                </details>
            </div>
        </div>
    @endif
</div>

@if($currentMatch)
    {{-- ── QUEUE ZONE MAP MODAL ── --}}
    <div id="modal-queue-zone-map" class="fixed inset-0 z-50 items-center justify-center" style="display:none" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" data-modal-close="modal-queue-zone-map"></div>
        <div class="relative mx-4 w-full max-w-3xl rounded-2xl border border-[color:var(--arena-line-strong)] bg-[linear-gradient(180deg,rgba(40,28,20,0.98),rgba(14,10,8,0.99))] p-6 shadow-[0_25px_60px_rgba(0,0,0,0.5)]" style="animation: arenaModalIn 0.2s ease-out">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-[color:var(--arena-gold)] font-['Cinzel']">Zona Asignada al Match</p>
                    <h3 class="mt-1 text-xl font-semibold text-white">{{ $currentMatch->zone_name }}</h3>
                </div>
                <button type="button" class="shrink-0 rounded-full p-1.5 text-[color:var(--arena-muted)] transition-colors hover:bg-white/10 hover:text-white" data-modal-close="modal-queue-zone-map" aria-label="Cerrar">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>
            </div>
            <x-arena-zone-map :zone-key="$currentMatch->zone_key" height="450px" />
        </div>
    </div>
@endif

<script>
    /* ── Tab switching ── */
    (function() {
        const tabs = {
            random: { btn: document.getElementById('tabBtnRandom'), panel: document.getElementById('tab-random') },
            premade: { btn: document.getElementById('tabBtnPremade'), panel: document.getElementById('tab-premade') },
        };

        if (!tabs.random.btn || !tabs.premade.btn) return;

        const activate = (key) => {
            Object.entries(tabs).forEach(([k, { btn, panel }]) => {
                const isActive = k === key;
                panel.classList.toggle('hidden', !isActive);
                if (isActive) panel.style.animation = 'arenaFadeIn 0.25s ease-out';
                btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
                btn.className = btn.className
                    .replace(/bg-\[linear-gradient[^\]]*\]/g, '')
                    .replace(/text-\[color:var\(--arena-gold-soft\)\]/g, '')
                    .replace(/shadow-\[[^\]]*\]/g, '')
                    .replace(/text-\[color:var\(--arena-muted\)\]/g, '')
                    .replace(/hover:text-\[color:var\(--arena-sand\)\]/g, '')
                    .replace(/hover:bg-white\/\[0\.04\]/g, '')
                    .replace(/\s+/g, ' ').trim();
                if (isActive) {
                    btn.classList.add('bg-[linear-gradient(180deg,rgba(63,45,31,0.85),rgba(22,15,11,0.95))]', 'text-[color:var(--arena-gold-soft)]', 'shadow-[0_4px_16px_rgba(0,0,0,0.2),inset_0_1px_0_rgba(255,215,134,0.12)]');
                    localStorage.setItem('arena_queue_active_tab', key); /* Save to localStorage */
                } else {
                    btn.classList.add('text-[color:var(--arena-muted)]', 'hover:text-[color:var(--arena-sand)]', 'hover:bg-white/[0.04]');
                }
            });
        };

        tabs.random.btn.addEventListener('click', () => activate('random'));
        tabs.premade.btn.addEventListener('click', () => activate('premade'));

        /* Load from localStorage or defaults */
        const savedTab = localStorage.getItem('arena_queue_active_tab') || 'random';
        if (savedTab === 'premade') {
            activate('premade');
        }
    })();

    /* ── Conjurer role toggle ── */
    function toggleConjurerRole() {
        const select = document.getElementById('playerSelect');
        const roleDiv = document.getElementById('conjurerRoleDiv');
        const roleSelect = document.getElementById('randomConjurerRole');
        if (!select || !roleDiv || !roleSelect) return;
        const selectedOption = select.options[select.selectedIndex];
        const isConjurer = selectedOption && selectedOption.dataset.subclass === 'conjurer';
        roleDiv.classList.toggle('hidden', !isConjurer);
        roleSelect.disabled = !isConjurer;
        if (!isConjurer) roleSelect.value = 'offensive';
    }

    /* ── Premade builder ── */
    function initializePremadeBuilder() {
        const leaderSelect = document.getElementById('partyLeaderSelect');
        const hint = document.getElementById('premadeRealmHint');
        const summary = document.getElementById('premadeSummary');
        const submitButton = document.getElementById('premadeSubmitButton');
        const endpoint = @json(route('queue.premade.candidates'));
        if (!leaderSelect || !hint || !summary || !submitButton) return;

        const state = { members: { 2: null }, debounce: {} };

        const escapeHtml = (v) => String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');

        const getLeaderData = () => {
            if (!leaderSelect.value) return null;
            const o = leaderSelect.options[leaderSelect.selectedIndex];
            if (!o) return null;
            return { id: Number(o.value), character_name: o.dataset.characterName, realm: o.dataset.realm, realm_label: o.dataset.realmLabel, subclass: o.dataset.subclass, subclass_label: o.dataset.subclassLabel, user_id: Number(o.dataset.user), owner_label: o.dataset.ownerLabel, is_conjurer: o.dataset.subclass === 'conjurer' };
        };

        const getSelectedPlayerIds = (skip = null) => {
            const ids = [];
            if (leaderSelect.value) ids.push(Number(leaderSelect.value));
            [2].forEach(s => { if (s !== skip) { const i = document.getElementById('partyMemberInput'+s); if (i && i.value) ids.push(Number(i.value)); } });
            return ids;
        };

        const renderSummary = () => {
            const leader = getLeaderData();
            const slots = { 1: leader, 2: state.members[2] };
            summary.innerHTML = Object.keys(slots).map(k => {
                const p = slots[k];
                if (!p) return '<div class="rounded-2xl border border-[color:var(--arena-line)] bg-black/10 px-4 py-3 text-sm text-[color:var(--arena-muted)]">Slot '+k+' pendiente</div>';
                return '<div class="rounded-2xl border border-[color:var(--arena-line-strong)] bg-black/20 px-4 py-3 text-sm"><p class="font-semibold text-white">'+escapeHtml(p.character_name)+'</p><p class="mt-1 text-[color:var(--arena-muted)]">'+escapeHtml(p.subclass_label)+' - '+escapeHtml(p.realm_label)+'</p><p class="mt-1 text-xs text-[color:var(--arena-muted)]">'+escapeHtml(p.owner_label)+'</p></div>';
            }).join('');
        };

        const updateRoleVisibility = () => {
            const leader = getLeaderData();
            [{n:document.getElementById('premadeRoleDiv0'),s:document.getElementById('premadeLeaderRole'),p:leader},{n:document.getElementById('premadeRoleDiv1'),s:document.getElementById('premadeRole2'),p:state.members[2]}].forEach(c => {
                if (!c.n || !c.s) return;
                const is = !!(c.p && c.p.is_conjurer);
                c.n.classList.toggle('hidden', !is);
                if (!is) c.s.value = 'offensive';
            });
        };

        const updateSubmitState = () => {
            const ready = !!(leaderSelect.value && state.members[2]);
            submitButton.disabled = !ready;
            submitButton.textContent = ready ? 'Entrar a Premade 2v2' : 'Completa la dupla para entrar a Premade';
        };

        const renderSelected = (slot) => {
            const c = document.getElementById('premadeSelected'+slot);
            const p = state.members[slot];
            if (!c) return;
            if (!p) { c.classList.add('hidden'); c.innerHTML = ''; return; }
            c.classList.remove('hidden');
            c.innerHTML = '<div class="rounded-2xl border border-[color:var(--arena-line-strong)] bg-black/20 px-4 py-3"><div class="flex items-start justify-between gap-3"><div><p class="font-semibold text-white">'+escapeHtml(p.character_name)+'</p><p class="mt-1 text-sm text-[color:var(--arena-muted)]">'+escapeHtml(p.subclass_label)+' - '+escapeHtml(p.realm_label)+'</p><p class="mt-1 text-xs text-[color:var(--arena-muted)]">'+escapeHtml(p.owner_label)+' - '+p.mmr+' MMR - '+Number(p.pl_points).toFixed(1)+' PL</p></div><button type="button" class="arena-btn-ghost px-3 py-2 text-xs" data-premade-clear="'+slot+'">Quitar</button></div></div>';
        };

        const clearResults = (slot) => { const c = document.getElementById('premadeResults'+slot); if (c) { c.classList.add('hidden'); c.innerHTML = ''; } };

        const renderResults = (slot, players) => {
            const c = document.getElementById('premadeResults'+slot);
            if (!c) return;
            if (!players.length) { c.classList.remove('hidden'); c.innerHTML = '<div class="rounded-2xl border border-[color:var(--arena-line)] bg-black/10 px-4 py-3 text-sm text-[color:var(--arena-muted)]">No hay compañeros disponibles.</div>'; return; }
            c.classList.remove('hidden');
            c.innerHTML = players.map(p => '<button type="button" class="block w-full rounded-2xl border border-[color:var(--arena-line)] bg-black/10 px-4 py-3 text-left transition hover:border-[color:var(--arena-line-strong)] hover:bg-white/5" data-premade-pick="'+slot+'" data-player="'+encodeURIComponent(JSON.stringify(p))+'"><div class="flex items-start justify-between gap-4"><div><p class="font-semibold text-white">'+escapeHtml(p.character_name)+'</p><p class="mt-1 text-sm text-[color:var(--arena-muted)]">'+escapeHtml(p.subclass_label)+' - '+escapeHtml(p.realm_label)+'</p><p class="mt-1 text-xs text-[color:var(--arena-muted)]">'+escapeHtml(p.owner_label)+'</p></div><div class="text-right text-xs text-[color:var(--arena-muted)]"><p>'+p.mmr+' MMR</p><p>'+Number(p.pl_points).toFixed(1)+' PL</p></div></div></button>').join('');
        };

        const clearMember = (slot, keepText = false) => {
            state.members[slot] = null;
            const h = document.getElementById('partyMemberInput'+slot);
            const s = document.getElementById('premadeSearch'+slot);
            if (h) h.value = '';
            if (s && !keepText) s.value = '';
            renderSelected(slot); updateRoleVisibility(); renderSummary(); updateSubmitState();
        };

        const chooseMember = (slot, player) => {
            state.members[slot] = player;
            const h = document.getElementById('partyMemberInput'+slot);
            const s = document.getElementById('premadeSearch'+slot);
            if (h) h.value = player.id;
            if (s) s.value = player.character_name;
            renderSelected(slot); clearResults(slot); updateRoleVisibility(); renderSummary(); updateSubmitState();
        };

        const searchCandidates = async (slot, query = '') => {
            const leader = getLeaderData();
            if (!leader) { clearResults(slot); return; }
            const params = new URLSearchParams();
            params.set('leader_player_id', leader.id);
            params.set('query', query);
            getSelectedPlayerIds(slot).forEach(id => params.append('selected_player_ids[]', id));
            const c = document.getElementById('premadeResults'+slot);
            if (c) { c.classList.remove('hidden'); c.innerHTML = '<div class="rounded-2xl border border-[color:var(--arena-line)] bg-black/10 px-4 py-3 text-sm text-[color:var(--arena-muted)]">Buscando compañeros...</div>'; }
            try {
                const r = await fetch(endpoint + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!r.ok) throw new Error('fail');
                const d = await r.json();
                renderResults(slot, d.results || []);
            } catch { if (c) c.innerHTML = '<div class="rounded-2xl border border-rose-500/30 bg-rose-900/20 px-4 py-3 text-sm text-rose-200">Error en la búsqueda.</div>'; }
        };

        const syncLeader = () => {
            const leader = getLeaderData();
            [2].forEach(slot => {
                clearMember(slot); clearResults(slot);
                const i = document.getElementById('premadeSearch'+slot);
                if (i) { i.disabled = !leader; i.placeholder = leader ? 'Busca compañero de '+leader.realm_label : 'Primero elige tu líder'; }
            });
            hint.textContent = leader ? 'Premade en '+leader.realm_label+'. Solo verás compañeros del mismo reino y usuarios distintos.' : 'Selecciona primero tu líder.';
            updateRoleVisibility(); renderSummary(); updateSubmitState();
        };

        leaderSelect.addEventListener('change', syncLeader);

        [2].forEach(slot => {
            const input = document.getElementById('premadeSearch'+slot);
            if (!input) return;
            input.addEventListener('focus', () => { if (!input.disabled) searchCandidates(slot, input.value.trim()); });
            input.addEventListener('input', () => {
                if (state.members[slot]) clearMember(slot, true);
                clearTimeout(state.debounce[slot]);
                state.debounce[slot] = setTimeout(() => searchCandidates(slot, input.value.trim()), 240);
            });
        });

        document.addEventListener('click', (e) => {
            const cl = e.target.closest('[data-premade-clear]');
            if (cl) { clearMember(Number(cl.getAttribute('data-premade-clear'))); clearResults(Number(cl.getAttribute('data-premade-clear'))); return; }
            const pk = e.target.closest('[data-premade-pick]');
            if (pk) { chooseMember(Number(pk.getAttribute('data-premade-pick')), JSON.parse(decodeURIComponent(pk.getAttribute('data-player')))); return; }
            [2].forEach(slot => {
                const r = document.getElementById('premadeResults'+slot);
                const i = document.getElementById('premadeSearch'+slot);
                if (r && !r.classList.contains('hidden') && !r.contains(e.target) && !i.contains(e.target)) clearResults(slot);
            });
        });

        syncLeader();
    }

    document.addEventListener('DOMContentLoaded', () => {
        toggleConjurerRole();
        initializePremadeBuilder();
        const randomSelect = document.getElementById('playerSelect');
        if (randomSelect) randomSelect.addEventListener('change', toggleConjurerRole);
    });
</script>

{{-- Shared state-polling component (replaces inline initializeStatePolling) --}}
<x-arena-state-poller :active="$shouldPoll" />
@endsection
