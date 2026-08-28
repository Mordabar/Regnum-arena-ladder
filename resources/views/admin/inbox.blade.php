@extends('layouts.arena')

@section('title', 'Moderation Inbox - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <x-arena-breadcrumbs :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Inbox'],
    ]" class="mb-6" />

    <section class="arena-panel-strong mb-8 p-6 md:p-8 arena-animate-in">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="arena-kicker">Inbox</p>
                <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)]">Bandeja de moderación</h1>
                <p class="mt-3 max-w-3xl text-[color:var(--arena-sand)] arena-body-text">
                    Reportes pendientes de confirmación y matches en disputa que necesitan atención humana.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('admin.dashboard') }}" class="arena-btn-ghost">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
                    Dashboard
                </a>
                <a href="{{ route('admin.matches.index') }}" class="arena-btn-secondary">Todos los matches</a>
            </div>
        </div>
    </section>

    <div class="mb-8 grid gap-4 md:grid-cols-2">
        <article class="arena-card p-5 arena-animate-in arena-stagger-1">
            <div class="flex items-center justify-between">
                <p class="arena-kicker">Pendientes</p>
                <span class="text-lg">📋</span>
            </div>
            <p class="mt-2 text-3xl font-semibold text-sky-300">{{ $pendingConfirmations->count() }}</p>
            <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">Reportes esperando respuesta rival.</p>
        </article>
        <article class="arena-card p-5 arena-animate-in arena-stagger-2">
            <div class="flex items-center justify-between">
                <p class="arena-kicker">Disputas</p>
                <span class="text-lg">⚠️</span>
            </div>
            <p class="mt-2 text-3xl font-semibold text-amber-300">{{ $disputedMatches->count() }}</p>
            <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">Matches retenidos para revisión.</p>
        </article>
    </div>

    <div class="grid gap-8 xl:grid-cols-2">
        {{-- Pending confirmations --}}
        <section class="arena-panel p-6 arena-animate-in arena-stagger-3">
            <div class="mb-4 flex items-center justify-between gap-4">
                <div>
                    <p class="arena-kicker text-sky-300">Prioridad 1</p>
                    <h2 class="mt-1 text-2xl font-semibold text-white">Pendientes de confirmación</h2>
                </div>
                <span class="arena-chip">{{ $pendingConfirmations->count() }}</span>
            </div>
            <div class="space-y-4">
                @forelse($pendingConfirmations as $report)
                    <article class="arena-card arena-card-interactive p-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    @if($report->match?->team_a_realm)
                                        <x-arena-realm-icon :realm="$report->match->team_a_realm" size="xs" />
                                        <span class="text-xs text-[color:var(--arena-muted)]">vs</span>
                                        <x-arena-realm-icon :realm="$report->match->team_b_realm" size="xs" />
                                    @endif
                                    <h3 class="text-lg font-semibold text-white arena-body-text">{{ $report->match?->match_code ?? 'Sin match' }}</h3>
                                </div>
                                <p class="mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                                    {{ $report->match?->zone_name ?? 'Sin zona' }} · {{ $report->status_name }}
                                </p>
                                <p class="mt-1 text-xs text-[color:var(--arena-muted)] arena-body-text">
                                    Reporter: {{ $report->reporter?->character_name ?? 'Sin reporter' }}
                                    @if($report->match?->expires_at)
                                        · vence {{ $report->match->expires_at->diffForHumans() }}
                                    @endif
                                </p>
                            </div>
                            <a href="{{ route('admin.matches.show', $report->match) }}" class="arena-btn-secondary px-4 py-2 text-sm">Revisar</a>
                        </div>
                    </article>
                @empty
                    <div class="arena-card p-5 text-center text-[color:var(--arena-muted)] arena-body-text">
                        <svg class="mx-auto h-8 w-8 opacity-30" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        <p class="mt-2">No hay reportes pendientes.</p>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- Disputes --}}
        <section class="arena-panel p-6 arena-animate-in arena-stagger-4">
            <div class="mb-4 flex items-center justify-between gap-4">
                <div>
                    <p class="arena-kicker text-amber-300">Prioridad 2</p>
                    <h2 class="mt-1 text-2xl font-semibold text-white">Disputas</h2>
                </div>
                <span class="arena-chip">{{ $disputedMatches->count() }}</span>
            </div>
            <div class="space-y-4">
                @forelse($disputedMatches as $match)
                    <article class="arena-card arena-card-interactive p-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <x-arena-realm-icon :realm="$match->team_a_realm" size="xs" />
                                    <span class="text-xs text-[color:var(--arena-muted)]">vs</span>
                                    <x-arena-realm-icon :realm="$match->team_b_realm" size="xs" />
                                    <h3 class="text-lg font-semibold text-white arena-body-text">{{ $match->match_code }}</h3>
                                </div>
                                <p class="mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">{{ $match->zone_name }}</p>
                                <p class="mt-1 text-xs text-[color:var(--arena-muted)] arena-body-text">
                                    Reporte: {{ $match->report?->status_name ?? 'Sin reporte' }}
                                </p>
                            </div>
                            <a href="{{ route('admin.matches.show', $match) }}" class="arena-btn px-4 py-2 text-sm">Moderar</a>
                        </div>
                    </article>
                @empty
                    <div class="arena-card p-5 text-center text-[color:var(--arena-muted)] arena-body-text">
                        <svg class="mx-auto h-8 w-8 opacity-30" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        <p class="mt-2">No hay disputas activas.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
