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

<span {{ $attributes->class(['inline-flex items-center', $wrapClass]) }}>
    <span class="relative inline-flex items-center justify-center rounded-[1.25rem] bg-[radial-gradient(circle_at_30%_30%,rgba(255,239,185,0.18),rgba(20,15,10,0.88)_62%)] p-1.5 shadow-[0_0_30px_rgba(0,0,0,0.45)]">
        <img
            src="/images/logo-arena-ladder.png"
            alt="Regnum Arena Ladder"
            class="{{ $logoClass }} rounded-[1rem] object-contain"
        >
    </span>

    <span class="leading-none">
        <span class="block font-['Cinzel'] uppercase text-[color:var(--arena-gold-soft)] {{ $titleClass }}">
            Regnum Arena Ladder
        </span>
        <span class="mt-1 block font-['Spectral'] uppercase text-[color:var(--arena-ember)] {{ $subtitleClass }}">
            Conquest PvP
        </span>
    </span>
</span>
