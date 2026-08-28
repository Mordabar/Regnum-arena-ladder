@extends('layouts.arena')

@section('title', 'Matches - Regnum Arena Ladder')

@section('content')
@php
    $userPlayerIds = auth()->user()->players()->pluck('id')->all();
@endphp

<div class="mx-auto max-w-6xl px-4 py-8">
    {{-- Breadcrumbs --}}
    <x-arena-breadcrumbs :items="[['label' => 'Matches']]" class="mb-6" />

    {{-- ── HERO ── --}}
    <section class="arena-panel-strong mb-8 p-6 md:p-8 arena-animate-in">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="arena-kicker">Centro de combates</p>
                <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)]">Tus enfrentamientos</h1>
                <p class="mt-2 text-[color:var(--arena-sand)] arena-body-text">Revisa aceptaciones, reportes, disputas y resultados del ladder.</p>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('queue.index') }}" class="arena-btn px-4 py-2">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                    Buscar combate
                </a>
                <a href="{{ route('ladder.index') }}" class="arena-btn-ghost px-4 py-2">Ladder</a>
            </div>
        </div>
    </section>

    {{-- ── ACTIVOS ── --}}
    <section class="mb-10 arena-animate-in arena-stagger-1">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-2xl font-semibold text-white">Activos</h2>
            <span class="arena-chip">{{ $activeMatches->count() }} abiertos</span>
        </div>

        @if($activeMatches->isEmpty())
            <div class="arena-panel p-6 text-center text-[color:var(--arena-muted)] arena-body-text">
                <svg class="mx-auto h-10 w-10 opacity-30 text-[color:var(--arena-gold)]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                <p class="mt-3">No tienes matches activos. <a href="{{ route('queue.index') }}" class="text-[color:var(--arena-gold-soft)] hover:underline">Buscar combate →</a></p>
            </div>
        @else
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach($activeMatches as $match)
                    @php
                        $viewerPlayer = $match->getAllPlayers()->first(function ($player) use ($userPlayerIds) {
                            return in_array((int) ($player['player_id'] ?? 0), $userPlayerIds, true);
                        });
                        $rivalRealm = $viewerPlayer
                            ? $match->getOpponentRealmForPlayer((int) $viewerPlayer['player_id'], $viewerPlayer['discord_id'] ?? null)
                            : null;

                        $statusClass = match($match->status) {
                            'pending_acceptance' => 'arena-status-pending',
                            'in_progress' => 'arena-status-active',
                            'disputed' => 'arena-status-disputed',
                            default => 'arena-status-pending',
                        };
                    @endphp

                    <article class="arena-panel arena-card-interactive p-5 arena-animate-in" style="animation-delay: {{ $loop->index * 80 }}ms">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs uppercase tracking-[0.2em] text-[color:var(--arena-muted)] arena-body-text">{{ $match->queue_mode_name }}</p>
                                <h3 class="mt-1 text-2xl font-semibold text-white">{{ $match->match_code }}</h3>
                                <p class="mt-1 text-sm text-[color:var(--arena-text)] arena-body-text inline-flex items-center gap-1">
                                    <svg class="h-3.5 w-3.5 text-[color:var(--arena-gold)] opacity-50" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
                                    {{ $match->zone_name }}
                                </p>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $statusClass }}">
                                {{ $match->status_name }}
                            </span>
                        </div>

                        <div class="mt-4 grid gap-3 grid-cols-3">
                            <div class="arena-card p-3">
                                <p class="text-[0.6rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">Rival</p>
                                <div class="mt-1 flex items-center gap-1.5">
                                    @if($rivalRealm)
                                        <x-arena-realm-icon :realm="$rivalRealm" size="sm" />
                                    @endif
                                    <span class="font-semibold text-white arena-body-text">{{ \App\Models\ArenaMatch::REALMS[$rivalRealm] ?? strtoupper((string) $rivalRealm) }}</span>
                                </div>
                            </div>
                            <div class="arena-card p-3">
                                <p class="text-[0.6rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">Token</p>
                                <p class="mt-1 font-mono text-sm text-white">{{ $match->report_token }}</p>
                            </div>
                            <div class="arena-card p-3">
                                <p class="text-[0.6rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">Reporte</p>
                                <p class="mt-1 font-semibold text-sm text-white arena-body-text">{{ $match->report?->status_name ?? 'Pendiente' }}</p>
                            </div>
                        </div>

                        <div class="mt-5">
                            <a href="{{ route('matches.show', $match) }}" class="arena-btn-secondary w-full">
                                @if($match->status === 'pending_acceptance')
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                    Aceptar match
                                @elseif($match->status === 'in_progress')
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm9.707 5.707a1 1 0 00-1.414-1.414L9 12.586l-1.293-1.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                    Reportar resultado
                                @else
                                    Abrir match
                                @endif
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ── RECIENTES ── --}}
    <section class="arena-animate-in arena-stagger-2">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-2xl font-semibold text-white">Recientes</h2>
            <span class="arena-chip">{{ $completedMatches->count() }} cerrados</span>
        </div>

        @if($completedMatches->isEmpty())
            <div class="arena-panel p-6 text-center text-[color:var(--arena-muted)] arena-body-text">
                <p>Todavía no hay historial reciente.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach($completedMatches as $match)
                    @php
                        $completedStatusClass = match($match->status) {
                            'completed' => 'arena-status-completed',
                            'disputed' => 'arena-status-disputed',
                            'void' => 'arena-status-void',
                            default => 'arena-status-void',
                        };
                    @endphp
                    <a href="{{ route('matches.show', $match) }}" class="arena-card arena-card-interactive block p-5 arena-animate-in" style="animation-delay: {{ $loop->index * 60 }}ms">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div class="flex items-center gap-3">
                                @if($match->status === 'completed')
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-900/40 text-emerald-300">
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                    </div>
                                @elseif($match->status === 'disputed')
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-900/40 text-amber-300">
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                    </div>
                                @else
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/5 text-[color:var(--arena-muted)]">
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                    </div>
                                @endif
                                <div>
                                    <h3 class="text-lg font-semibold text-white arena-body-text">{{ $match->match_code }}</h3>
                                    <p class="text-sm text-[color:var(--arena-muted)] arena-body-text inline-flex items-center gap-1">
                                        <svg class="h-3.5 w-3.5 text-[color:var(--arena-gold)] opacity-40" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
                                        {{ $match->zone_name }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                @if($match->winner_realm)
                                    <span class="inline-flex items-center gap-1.5 text-sm text-emerald-300 arena-body-text">
                                        <x-arena-realm-icon :realm="$match->winner_realm" size="xs" />
                                        {{ \App\Models\ArenaMatch::REALMS[$match->winner_realm] ?? strtoupper((string) $match->winner_realm) }}
                                    </span>
                                @endif
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $completedStatusClass }}">
                                    {{ $match->status_name }}
                                </span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection
