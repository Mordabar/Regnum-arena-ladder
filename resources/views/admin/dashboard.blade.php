@extends('layouts.arena')

@section('title', 'Admin Dashboard - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <x-arena-breadcrumbs :items="[['label' => 'Admin']]" class="mb-6" />

    <section class="arena-panel-strong mb-8 p-6 md:p-8 arena-animate-in relative overflow-hidden">
        <div class="absolute -top-20 -right-20 w-60 h-60 rounded-full bg-[radial-gradient(circle,rgba(216,177,92,0.08),transparent_70%)] pointer-events-none"></div>
        <div class="relative flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="arena-kicker">Admin Panel</p>
                <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)] md:text-5xl">Control operativo</h1>
                <p class="mt-3 max-w-3xl text-[color:var(--arena-sand)] arena-body-text">Monitorea cola, matches, disputas, sanciones y configuración del runtime.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('admin.inbox') }}" class="arena-btn-warning">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
                    Inbox
                </a>
                <a href="{{ route('admin.matches.index') }}" class="arena-btn-secondary">Matches</a>
                <a href="{{ route('admin.players.index') }}" class="arena-btn-ghost">Jugadores</a>
            </div>
        </div>
    </section>

    {{-- Stats --}}
    <div class="mb-8 grid gap-4 grid-cols-2 xl:grid-cols-4">
        @php
            $statIcons = [
                'total_matches' => '⚔️',
                'in_queue' => '⏳',
                'pending_acceptance' => '🤝',
                'in_progress' => '🔥',
                'pending_report_confirmation' => '📋',
                'completed' => '✅',
                'disputed' => '⚠️',
                'active_players' => '👤',
            ];
            $statColors = [
                'disputed' => 'text-amber-300',
                'pending_report_confirmation' => 'text-sky-300',
                'completed' => 'text-emerald-300',
                'in_progress' => 'text-orange-300',
            ];
        @endphp
        @foreach($stats as $label => $value)
            <article class="arena-card arena-card-interactive p-5 arena-animate-in arena-stagger-{{ $loop->index % 6 + 1 }}">
                <div class="flex items-center justify-between">
                    <p class="text-[0.65rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">{{ ucwords(str_replace('_', ' ', $label)) }}</p>
                    <span class="text-lg">{{ $statIcons[$label] ?? '📊' }}</span>
                </div>
                <p class="mt-2 text-3xl font-semibold {{ $statColors[$label] ?? 'text-white' }}">{{ $value }}</p>
            </article>
        @endforeach
    </div>

    {{-- Urgent moderation --}}
    @if(($stats['pending_report_confirmation'] ?? 0) > 0 || ($stats['disputed'] ?? 0) > 0)
        <section class="arena-panel mb-8 p-6 border-l-4 border-l-amber-500/60 arena-animate-in arena-stagger-2">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    <div>
                        <h2 class="text-xl font-semibold text-white">Items esperando decisión humana</h2>
                        <p class="mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">Pending confirmation y disputas centralizados en el inbox.</p>
                    </div>
                </div>
                <a href="{{ route('admin.inbox') }}" class="arena-btn-warning">Ir al inbox</a>
            </div>
        </section>
    @endif

    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        {{-- Recent matches --}}
        <section class="arena-panel p-6 arena-animate-in arena-stagger-3">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-2xl font-semibold text-white">Matches recientes</h2>
                <a href="{{ route('admin.matches.index') }}" class="arena-btn-ghost text-sm">Ver todos</a>
            </div>
            <div class="space-y-3">
                @forelse($recentMatches as $match)
                    @php
                        $matchStatusClass = match($match->status) {
                            'pending_acceptance' => 'arena-status-pending',
                            'in_progress' => 'arena-status-active',
                            'completed' => 'arena-status-completed',
                            'disputed' => 'arena-status-disputed',
                            'void' => 'arena-status-void',
                            default => 'arena-status-pending',
                        };
                    @endphp
                    <a href="{{ route('admin.matches.show', $match) }}" class="arena-card arena-card-interactive block p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <div class="flex items-center gap-1">
                                    <x-arena-realm-icon :realm="$match->team_a_realm" size="xs" />
                                    <span class="text-xs text-[color:var(--arena-muted)]">vs</span>
                                    <x-arena-realm-icon :realm="$match->team_b_realm" size="xs" />
                                </div>
                                <div>
                                    <h3 class="font-semibold text-white arena-body-text">{{ $match->match_code }}</h3>
                                    <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">{{ $match->zone_name }}</p>
                                </div>
                            </div>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $matchStatusClass }}">{{ $match->status_name }}</span>
                        </div>
                    </a>
                @empty
                    <p class="text-[color:var(--arena-muted)] arena-body-text">Sin matches recientes.</p>
                @endforelse
            </div>
        </section>

        {{-- Recent reports --}}
        <section class="arena-panel p-6 arena-animate-in arena-stagger-4">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-2xl font-semibold text-white">Reportes recientes</h2>
                <a href="{{ route('admin.matches.index', ['status' => 'disputed']) }}" class="arena-btn-ghost text-sm">Disputas</a>
            </div>
            <div class="space-y-3">
                @forelse($recentReports as $report)
                    <a href="{{ route('admin.matches.show', $report->match) }}" class="arena-card arena-card-interactive block p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-white arena-body-text">{{ $report->match?->match_code ?? 'Sin match' }}</h3>
                                <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">{{ $report->status_name }} · {{ $report->reporter?->character_name ?? 'Sin reporter' }}</p>
                            </div>
                            <span class="arena-chip text-xs">{{ $report->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @empty
                    <p class="text-[color:var(--arena-muted)] arena-body-text">Sin reportes recientes.</p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="grid gap-6 lg:grid-cols-[1.1fr,0.9fr]">
        {{-- Recent users --}}
        <section class="arena-panel p-6 arena-animate-in arena-stagger-5">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-2xl font-semibold text-white">Usuarios recientes</h2>
                <a href="{{ route('admin.players.index') }}" class="arena-btn-ghost text-sm">Ir a jugadores</a>
            </div>
            <div class="space-y-3">
                @forelse($recentUsers as $user)
                    <article class="arena-card p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-white arena-body-text">{{ $user->discord_username }}</h3>
                                <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">{{ $user->discord_id }} · {{ $user->players()->count() }} personajes</p>
                            </div>
                            @if($user->isAdmin())
                                <span class="rounded-full bg-[color:var(--arena-gold)]/15 px-2.5 py-0.5 text-xs font-semibold text-[color:var(--arena-gold-soft)]">Admin</span>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="text-[color:var(--arena-muted)] arena-body-text">Sin usuarios recientes.</p>
                @endforelse
            </div>
        </section>

        {{-- Quick actions --}}
        <section class="arena-panel p-6 arena-animate-in arena-stagger-6">
            <h2 class="text-2xl font-semibold text-white">Accesos rápidos</h2>
            <div class="mt-5 space-y-3">
                <a href="{{ route('admin.inbox') }}" class="arena-btn-warning w-full justify-center">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
                    Inbox de moderación
                </a>
                <a href="{{ route('admin.matches.index') }}" class="arena-btn-secondary w-full justify-center">Matches y disputas</a>
                <a href="{{ route('admin.players.index') }}" class="arena-btn-secondary w-full justify-center">Gestionar jugadores</a>
                <a href="{{ route('admin.settings') }}" class="arena-btn-ghost w-full justify-center">Configurar runtime</a>
                <a href="{{ route('admin.testing') }}" class="arena-btn-ghost w-full justify-center">Testing aislado</a>
                <div class="grid gap-3 grid-cols-2">
                    <form method="POST" action="{{ route('admin.operations.process-queue') }}">
                        @csrf
                        <button type="submit" class="arena-btn-ghost w-full">Procesar matchmaking</button>
                    </form>
                    <form method="POST" action="{{ route('admin.operations.expire-pending') }}">
                        @csrf
                        <button type="submit" class="arena-btn-ghost w-full">Expirar pendientes</button>
                    </form>
                </div>
            </div>
        </section>
    </div>
</div>
@endsection
