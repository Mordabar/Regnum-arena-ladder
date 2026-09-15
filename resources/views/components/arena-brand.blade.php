@props([
    'compact' => false,
])

@php
    $isCompact = filter_var($compact, FILTER_VALIDATE_BOOL);
    $wrapClass = $isCompact
        ? 'gap-3'
        : 'gap-4';
    $logoClass = $isCompact
        ? 'h-14 w-auto md:h-16'
        : 'h-24 w-auto md:h-28';
    $titleClass = $isCompact
        ? 'text-base md:text-lg'
        : 'text-3xl md:text-4xl';
    $subtitleClass = $isCompact
        ? 'text-[0.62rem] tracking-[0.42em]'
        : 'text-xs tracking-[0.48em]';
@endphp

{{--
    `min-w-0` y `shrink-0` no son adorno: sin ellos, un elemento flex no baja de
    lo que mide su contenido, asi que en una pantalla de 400 px la marca entera
    mas el boton de menu no cabian y empujaban la pagina 55 px a la derecha. El
    body tiene overflow-x oculto, pero el documento seguia desplazandose, asi
    que el sitio se movia de lado al arrastrar.
--}}
<span {{ $attributes->class(['inline-flex min-w-0 items-center', $wrapClass]) }}>
    <span class="relative inline-flex shrink-0 items-center justify-center rounded-[1.25rem] bg-[radial-gradient(circle_at_30%_30%,rgba(255,239,185,0.18),rgba(20,15,10,0.88)_62%)] p-1.5 shadow-[0_0_30px_rgba(0,0,0,0.45)]">
        <img
            src="/images/logo-arena-ladder.png"
            alt="Regnum Arena Ladder"
            class="{{ $logoClass }} rounded-[1rem] object-contain"
        >
    </span>

    <span class="min-w-0 leading-none">
        <span class="block font-['Cinzel'] uppercase text-[color:var(--arena-gold-soft)] {{ $titleClass }}">
            Regnum Arena Ladder
        </span>
        <span class="mt-1 block font-['Spectral'] uppercase text-[color:var(--arena-ember)] {{ $subtitleClass }}">
            Conquest PvP
        </span>
    </span>
</span>
