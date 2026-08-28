@extends('layouts.arena')

@section('title', 'Salon de la Fama - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8">
    <x-arena-breadcrumbs :items="[['label' => 'Salon de la Fama']]" class="mb-6" />

    <section class="arena-panel-strong mb-8 p-6 md:p-8 arena-animate-in">
        <p class="arena-kicker">Legado competitivo</p>
        <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)]">Salon de la Fama</h1>
        <p class="mt-3 max-w-3xl text-[color:var(--arena-sand)] arena-body-text">Ganadores y podios definitivos de cada temporada cerrada.</p>
    </section>

    <div class="space-y-8">
        @forelse($seasons as $season)
            <section class="arena-panel p-6 arena-animate-in">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="arena-kicker">Temporada cerrada</p>
                        <h2 class="mt-2 text-2xl font-semibold text-white">{{ $season->name }}</h2>
                        <p class="mt-1 text-sm text-[color:var(--arena-muted)]">{{ implode(' / ', $season->enabledModes()) }} · {{ $season->ends_at?->format('d/m/Y') }}</p>
                    </div>
                    <span class="arena-chip">Podio final</span>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-3">
                    @forelse($season->leaders as $index => $stat)
                        <article class="arena-card arena-card-{{ $stat->realm }} p-5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-2xl font-bold text-[color:var(--arena-gold-soft)]">#{{ $index + 1 }}</span>
                                <x-arena-realm-icon :realm="$stat->realm" size="sm" />
                            </div>
                            <h3 class="mt-4 text-xl font-semibold text-white">{{ $stat->character_name }}</h3>
                            <p class="mt-1 text-sm text-[color:var(--arena-muted)]">{{ \App\Models\Player::SUBCLASSES[$stat->subclass] ?? ucfirst($stat->subclass) }}</p>
                            <div class="mt-4 flex items-center justify-between text-sm">
                                <span class="font-semibold text-amber-300">{{ number_format((float) $stat->pl_points, 1) }} PL</span>
                                <span class="text-sky-300">{{ $stat->mmr }} MMR</span>
                            </div>
                            <p class="mt-2 text-xs text-[color:var(--arena-muted)]">{{ $stat->wins }} victorias · {{ $stat->matches_played }} partidas</p>
                        </article>
                    @empty
                        <p class="text-[color:var(--arena-muted)]">Esta temporada no tuvo jugadores clasificados.</p>
                    @endforelse
                </div>
            </section>
        @empty
            <section class="arena-panel p-8 text-center text-[color:var(--arena-muted)]">
                El primer podio aparecera cuando termine la temporada actual.
            </section>
        @endforelse
    </div>
</div>
@endsection
