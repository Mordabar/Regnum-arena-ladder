@extends('layouts.arena')

@section('title', 'Admin Matches - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <x-arena-breadcrumbs :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Matches'],
    ]" class="mb-6" />

    <section class="arena-panel-strong mb-8 p-6 md:p-8 arena-animate-in">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="arena-kicker">Admin</p>
                <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)]">Matches</h1>
            </div>
            <form method="GET" class="flex flex-wrap gap-3">
                <input type="text" name="q" value="{{ request('q') }}" class="arena-field min-w-[200px]" placeholder="Buscar código, token, jugador…">
                <select name="status" class="arena-select">
                    <option value="">Todos</option>
                    @foreach(\App\Models\ArenaMatch::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="arena-btn-secondary">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                    Filtrar
                </button>
                <a href="{{ route('admin.inbox') }}" class="arena-btn-warning">Inbox</a>
            </form>
        </div>
    </section>

    <div class="space-y-4">
        @foreach($matches as $match)
            @php
                $matchStatusClass = match($match->status) {
                    'pending_acceptance' => 'arena-status-pending',
                    'in_progress' => 'arena-status-active',
                    'completed' => 'arena-status-completed',
                    'disputed' => 'arena-status-disputed',
                    'void', 'cancelled' => 'arena-status-void',
                    default => 'arena-status-pending',
                };
                $urgency = $match->status === 'disputed'
                    ? 'border-l-4 border-l-amber-500/60'
                    : ($match->report?->status === 'pending_confirmation' ? 'border-l-4 border-l-sky-500/40' : '');
            @endphp
            <article class="arena-panel arena-card-interactive p-5 {{ $urgency }} arena-animate-in" style="animation-delay: {{ $loop->index * 50 }}ms">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-1">
                                <x-arena-realm-icon :realm="$match->team_a_realm" size="sm" />
                                <span class="text-xs text-[color:var(--arena-muted)]">vs</span>
                                <x-arena-realm-icon :realm="$match->team_b_realm" size="sm" />
                            </div>
                            <h2 class="text-xl font-semibold text-white arena-body-text">{{ $match->match_code }}</h2>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $matchStatusClass }}">{{ $match->status_name }}</span>
                        </div>
                        <p class="mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                            {{ $match->zone_name }} · Creado {{ $match->created_at?->diffForHumans() }}
                        </p>
                        <p class="mt-1 text-xs text-[color:var(--arena-muted)] arena-body-text">
                            Reporte: {{ $match->report?->status_name ?? 'Sin reporte' }}
                        </p>
                    </div>
                    <a href="{{ route('admin.matches.show', $match) }}" class="arena-btn px-4 py-2 text-sm">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        Moderar
                    </a>
                </div>
            </article>
        @endforeach
    </div>

    <div class="mt-6">
        {{ $matches->links() }}
    </div>
</div>
@endsection
