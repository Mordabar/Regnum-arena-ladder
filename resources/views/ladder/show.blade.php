@extends('layouts.arena')

@section('title', $player->character_name . ' - Ladder')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-8">
    {{-- Breadcrumbs --}}
    <x-arena-breadcrumbs :items="[
        ['label' => 'Ladder', 'url' => route('ladder.index')],
        ['label' => $player->character_name],
    ]" class="mb-6" />

    {{-- ── PROFILE HEADER ── --}}
    <section class="arena-panel-strong arena-card-{{ $player->realm }} mb-8 p-6 md:p-8 arena-animate-in relative overflow-hidden">
        {{-- Realm glow --}}
        <div class="absolute -top-16 -right-16 w-48 h-48 rounded-full pointer-events-none opacity-30"
             style="background: radial-gradient(circle, {{ $player->realm === 'ignis' ? 'rgba(211,100,47,0.4)' : ($player->realm === 'alsius' ? 'rgba(121,181,214,0.4)' : 'rgba(142,179,74,0.4)') }}, transparent 70%)">
        </div>

        <div class="relative flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-4">
                {{-- La ficha del ladder ensena al guerrero, no solo sus numeros:
                     es la misma figura que su dueno ve en el lobby. --}}
                <x-arena-champion
                    id="profile-stage"
                    :realm="$player->realm"
                    :subclass="$player->subclass"
                    :race="$player->race"
                    :gender="$player->gender"
                    :parallax="false"
                    height="150px"
                    class="arena-profile-portrait" />
                <div>
                    <p class="arena-kicker flex items-center gap-2">
                        <x-arena-realm-icon :realm="$player->realm" size="sm" />
                        {{ \App\Models\Player::REALMS[$player->realm] ?? ucfirst($player->realm) }}
                    </p>
                    <h1 class="mt-2 text-4xl font-bold text-[color:var(--arena-gold-soft)]">{{ $player->cleanName() }}</h1>
                    <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[color:var(--arena-sand)] arena-body-text">
                        <span>{{ \App\Models\Player::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass) }}</span>
                        <span class="text-[color:var(--arena-muted)]">{{ $player->raceName() }}</span>
                        <span class="text-[color:var(--arena-muted)]">{{ $player->genderName() }}</span>
                    </p>
                </div>
            </div>
            <a href="{{ route('ladder.index') }}" class="arena-btn-ghost px-4 py-2">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
                Volver al ladder
            </a>
        </div>

        <div class="relative mt-6 grid gap-4 grid-cols-2 md:grid-cols-4">
            <div class="arena-card p-4 arena-animate-in arena-stagger-1">
                <p class="arena-kicker">PL</p>
                <p class="mt-1 text-2xl font-semibold text-amber-300">{{ number_format((float) $player->pl_points, 1) }}</p>
            </div>
            <div class="arena-card p-4 arena-animate-in arena-stagger-2">
                <p class="arena-kicker">MMR</p>
                <p class="mt-1 text-2xl font-semibold text-sky-300">{{ $player->mmr }}</p>
            </div>
            <div class="arena-card p-4 arena-animate-in arena-stagger-3">
                <p class="arena-kicker">W / L</p>
                <div class="mt-1 flex items-baseline gap-1">
                    <span class="text-2xl font-semibold text-emerald-300">{{ $player->wins }}</span>
                    <span class="text-lg text-[color:var(--arena-muted)]">/</span>
                    <span class="text-2xl font-semibold text-rose-300">{{ $player->losses }}</span>
                </div>
                @if($player->matches_played > 0)
                    <div class="mt-2 h-1.5 w-full rounded-full bg-rose-900/30 overflow-hidden">
                        <div class="h-full rounded-full bg-emerald-400/80 transition-all duration-500" style="width: {{ $player->win_rate }}%"></div>
                    </div>
                    <p class="mt-1 text-xs text-[color:var(--arena-muted)] arena-body-text">{{ $player->win_rate }}% win rate</p>
                @endif
            </div>
            <div class="arena-card p-4 arena-animate-in arena-stagger-4">
                <p class="arena-kicker">Posición</p>
                <p class="mt-1 text-2xl font-semibold {{ $player->ranking_position <= 3 ? 'arena-medal-' . $player->ranking_position : 'text-white' }}">
                    #{{ $player->ranking_position }}
                </p>
            </div>
        </div>
    </section>

    {{-- ── HISTORIAL ── --}}
    <section class="arena-panel p-6 arena-animate-in arena-stagger-4">
        <h2 class="text-2xl font-semibold text-white">Historial de combates</h2>
        <div class="mt-5 space-y-4">
            @forelse($results as $result)
                <article class="arena-card p-4 arena-animate-in" style="animation-delay: {{ $loop->index * 50 }}ms">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <div class="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $result->result === 'win' ? 'bg-emerald-900/40 text-emerald-300' : 'bg-rose-900/40 text-rose-300' }}">
                                @if($result->result === 'win')
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                @else
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                @endif
                            </div>
                            <div>
                                <h3 class="font-semibold text-white arena-body-text">{{ $result->match?->match_code ?? 'Match eliminado' }}</h3>
                                <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">{{ $result->match?->zone_name ?? 'Sin zona' }}</p>
                            </div>
                        </div>
                        <div class="text-right text-sm arena-body-text">
                            <p class="text-[color:var(--arena-text)]">PL: {{ number_format((float) $result->pl_before, 1) }} → {{ number_format((float) $result->pl_after, 1) }}</p>
                            <p class="text-[color:var(--arena-text)]">MMR: {{ $result->mmr_before }} → {{ $result->mmr_after }}</p>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-3 text-sm">
                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 {{ $result->pl_change >= 0 ? 'bg-emerald-900/30 text-emerald-300' : 'bg-rose-900/30 text-rose-300' }}">
                            PL {{ $result->pl_change >= 0 ? '+' : '' }}{{ number_format((float) $result->pl_change, 1) }}
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 {{ $result->mmr_change >= 0 ? 'bg-emerald-900/30 text-emerald-300' : 'bg-rose-900/30 text-rose-300' }}">
                            MMR {{ $result->mmr_change >= 0 ? '+' : '' }}{{ $result->mmr_change }}
                        </span>
                        <span class="rounded-full bg-white/5 px-2.5 py-0.5 text-[color:var(--arena-muted)]">
                            {{ ucfirst((string) data_get($result->scoring_context, 'match_category', 'n/a')) }}
                        </span>
                    </div>
                </article>
            @empty
                <div class="arena-card p-5 text-center text-[color:var(--arena-muted)] arena-body-text">
                    <p>Este guerrero aún no tiene combates registrados.</p>
                </div>
            @endforelse
        </div>

        <div class="mt-6">
            {{ $results->links() }}
        </div>
    </section>
</div>
@endsection
