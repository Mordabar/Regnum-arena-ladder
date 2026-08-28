@props([
    'realm' => 'ignis',
    'size' => 'md',
])

@php
    $sizeClasses = match($size) {
        'xs' => 'w-4 h-4',
        'sm' => 'w-5 h-5',
        'md' => 'w-6 h-6',
        'lg' => 'w-8 h-8',
        'xl' => 'w-10 h-10',
        default => 'w-6 h-6',
    };

    $realmColors = match($realm) {
        'ignis' => ['fill' => '#d3642f', 'glow' => 'drop-shadow(0 0 6px rgba(211,100,47,0.5))'],
        'alsius' => ['fill' => '#79b5d6', 'glow' => 'drop-shadow(0 0 6px rgba(121,181,214,0.5))'],
        'syrtis' => ['fill' => '#8eb34a', 'glow' => 'drop-shadow(0 0 6px rgba(142,179,74,0.5))'],
        default => ['fill' => '#d8b15c', 'glow' => 'none'],
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center justify-center shrink-0', $sizeClasses]) }} style="filter: {{ $realmColors['glow'] }}" title="{{ \App\Models\Player::REALMS[$realm] ?? ucfirst($realm) }}">
    @if($realm === 'ignis')
        {{-- Flame icon --}}
        <svg viewBox="0 0 24 24" fill="none" class="w-full h-full">
            <path d="M12 2C12 2 6 8.5 6 13.5C6 17.09 8.69 20 12 20C15.31 20 18 17.09 18 13.5C18 8.5 12 2 12 2Z" fill="{{ $realmColors['fill'] }}" fill-opacity="0.9"/>
            <path d="M12 6C12 6 9 10.5 9 13C9 14.66 10.34 16 12 16C13.66 16 15 14.66 15 13C15 10.5 12 6 12 6Z" fill="#f4a261" fill-opacity="0.8"/>
            <path d="M12 10C12 10 10.5 12.25 10.5 13.5C10.5 14.33 11.17 15 12 15C12.83 15 13.5 14.33 13.5 13.5C13.5 12.25 12 10 12 10Z" fill="#ffcb69" fill-opacity="0.9"/>
        </svg>
    @elseif($realm === 'alsius')
        {{-- Frost crystal icon --}}
        <svg viewBox="0 0 24 24" fill="none" class="w-full h-full">
            <path d="M12 2L12 22M2 12L22 12" stroke="{{ $realmColors['fill'] }}" stroke-width="2" stroke-linecap="round"/>
            <path d="M5.64 5.64L18.36 18.36M18.36 5.64L5.64 18.36" stroke="{{ $realmColors['fill'] }}" stroke-width="1.5" stroke-linecap="round" stroke-opacity="0.7"/>
            <circle cx="12" cy="12" r="3" fill="{{ $realmColors['fill'] }}" fill-opacity="0.5"/>
            <circle cx="12" cy="12" r="1.5" fill="#c5e3f0" fill-opacity="0.9"/>
            <path d="M12 4L10.5 6.5H13.5L12 4Z" fill="{{ $realmColors['fill'] }}" fill-opacity="0.8"/>
            <path d="M12 20L13.5 17.5H10.5L12 20Z" fill="{{ $realmColors['fill'] }}" fill-opacity="0.8"/>
            <path d="M4 12L6.5 10.5V13.5L4 12Z" fill="{{ $realmColors['fill'] }}" fill-opacity="0.8"/>
            <path d="M20 12L17.5 13.5V10.5L20 12Z" fill="{{ $realmColors['fill'] }}" fill-opacity="0.8"/>
        </svg>
    @elseif($realm === 'syrtis')
        {{-- Nature/leaf icon --}}
        <svg viewBox="0 0 24 24" fill="none" class="w-full h-full">
            <path d="M17 8C17 8 20 3 12 3C4 3 4 10 4 10C4 10 4 18 12 21C12 21 8 14 8 10C8 6 12 5 12 5C12 5 10 8 10 11C10 14 12 16 12 16C12 16 14 14 15 11C16 8 17 8 17 8Z" fill="{{ $realmColors['fill'] }}" fill-opacity="0.85"/>
            <path d="M12 21C12 21 20 18 20 10C20 10 20 6 17 4" stroke="#a4cc5e" stroke-width="1.5" stroke-linecap="round" fill="none" stroke-opacity="0.6"/>
            <path d="M12 8V18" stroke="#c9e67c" stroke-width="1" stroke-linecap="round" stroke-opacity="0.5"/>
        </svg>
    @endif
</span>
