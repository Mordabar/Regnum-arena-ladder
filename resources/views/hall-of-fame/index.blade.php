@extends('layouts.arena')

@section('title', 'Salon de la Fama — Regnum Arena Ladder')

@section('content')
@php
    use App\Models\Player as PlayerModel;
    use App\Services\SeasonPrizeService;

    $medallas = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
@endphp

<div class="mx-auto max-w-6xl px-4 py-8">
    <x-arena-breadcrumbs :items="[['label' => 'Salon de la Fama']]" class="mb-6" />

    <section class="arena-panel-strong mb-8 p-6 md:p-8 arena-animate-in">
        <p class="arena-kicker">Legado competitivo</p>
        <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)]">Salon de la Fama</h1>
        <p class="mt-3 max-w-3xl text-[color:var(--arena-sand)] arena-body-text">
            Cada temporada que termina deja aqui su podio y lo que repartio. Las cifras son las del
            dia que se cerro: lo que se gano entonces no cambia porque se siga jugando ahora.
        </p>
    </section>

    {{-- La temporada en marcha. No es historia todavia, pero quien entra a ver
         la vitrina quiere saber que hay en juego ahora mismo. --}}
    @if($actual && $premios->activos())
        <section class="arena-panel mb-8 p-6 arena-animate-in">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="arena-kicker"><span class="arena-party-dot is-live"></span> En juego ahora</p>
                    <h2 class="mt-2 text-2xl font-semibold text-white">{{ $actual->name }}</h2>
                </div>
                <a href="{{ route('ladder.index') }}" class="arena-btn-ghost">Ver clasificacion</a>
            </div>

            <p class="mt-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
                <b class="text-[color:var(--arena-gold-soft)]">{{ $premios->total() }} {{ $premios->moneda() }}</b>
                en juego. {{ $premios->bases() }}
            </p>
        </section>
    @endif

    <div class="space-y-8">
        @forelse($seasons as $season)
            @php
                $reparto = $season->reparto();
                $moneda = $season->prize_currency ?: $premios->moneda();
            @endphp

            <section class="arena-panel p-6 arena-animate-in">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="arena-kicker">Temporada cerrada</p>
                        <h2 class="mt-2 text-2xl font-semibold text-white">{{ $season->name }}</h2>
                        <p class="mt-1 text-sm text-[color:var(--arena-muted)]">
                            {{ implode(' · ', $season->enabledModes()) }}
                            @if($season->ends_at) · cerrada el {{ $season->ends_at->format('d/m/Y') }} @endif
                        </p>
                    </div>

                    @if($reparto !== [])
                        <span class="arena-chip">{{ $season->totalRepartido() }} {{ $moneda }} repartidos</span>
                    @endif
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-3">
                    @forelse($season->leaders as $index => $stat)
                        @php $puesto = $index + 1; @endphp
                        <article class="arena-card arena-card-{{ $stat->realm }} p-5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="flex items-center gap-2 text-2xl font-bold text-[color:var(--arena-gold-soft)]">
                                    <span aria-hidden="true">{{ $medallas[$puesto] ?? '🏅' }}</span>
                                    {{ $puesto }}.º
                                </span>
                                <x-arena-realm-icon :realm="$stat->realm" size="sm" />
                            </div>

                            <h3 class="mt-4 text-xl font-semibold text-white">{{ $stat->character_name }}</h3>
                            <p class="mt-1 text-sm text-[color:var(--arena-muted)]">
                                {{ PlayerModel::SUBCLASSES[$stat->subclass] ?? ucfirst($stat->subclass) }}
                            </p>

                            @if(isset($reparto[$puesto]))
                                <p class="mt-3 inline-flex items-center gap-2 rounded-full border border-[rgba(216,177,92,0.35)] bg-[rgba(216,177,92,0.1)] px-3 py-1 text-sm">
                                    <span aria-hidden="true">💰</span>
                                    <b class="text-[color:var(--arena-gold-soft)]">{{ $reparto[$puesto] }}</b>
                                    <span class="text-[color:var(--arena-muted)]">{{ $moneda }}</span>
                                </p>
                            @endif

                            <div class="mt-4 flex items-center justify-between text-sm">
                                <span class="font-semibold text-amber-300">{{ number_format((float) $stat->pl_points, 1) }} PL</span>
                                <span class="text-sky-300">{{ $stat->mmr }} MMR</span>
                            </div>
                            <p class="mt-2 text-xs text-[color:var(--arena-muted)]">
                                {{ $stat->wins }} victorias · {{ $stat->matches_played }} partidas
                            </p>
                        </article>
                    @empty
                        <p class="text-[color:var(--arena-muted)]">Esta temporada no tuvo jugadores clasificados.</p>
                    @endforelse
                </div>
            </section>
        @empty
            <section class="arena-panel p-8 text-center">
                <p class="text-5xl" aria-hidden="true">🏆</p>
                <p class="mt-4 text-lg text-[color:var(--arena-sand)]">La vitrina esta vacia, de momento.</p>
                <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    El primer podio aparecera aqui en cuanto termine la temporada en curso.
                    Los tres primeros se quedan para siempre.
                </p>
                <a href="{{ route('ladder.index') }}" class="arena-btn mt-6">Ver quien va ganando</a>
            </section>
        @endforelse
    </div>
</div>
@endsection
