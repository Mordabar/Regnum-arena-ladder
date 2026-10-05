@props(['season' => null])
@php
    $premiosCal = app(\App\Services\SeasonPrizeService::class);
@endphp
{{-- El premio y el calendario de la temporada, sin el podio: para el lobby y el
     Salon de la Fama, donde se viene a jugar o a mirar, no a ver las figuras.
     Si los premios estan apagados queda solo la barra. --}}
@if($season?->ends_at)
    <section class="arena-podium is-compacto is-solo arena-animate-in" aria-label="{{ __('Premios de la temporada') }}">
        <header class="arena-podium-head">
            @if($premiosCal->activos())
                <x-arena-premio-cabecera :premios="$premiosCal" />
            @endif
            <x-arena-season-bar :season="$season" />
        </header>
    </section>
@endif
