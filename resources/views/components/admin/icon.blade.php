@props(['name' => 'dot', 'class' => 'h-4 w-4'])
@php
    // Trazos, no emojis: los emojis se dibujan distinto en cada sistema
    // operativo y no heredan el color del texto, asi que rompen la coherencia
    // del panel y no sirven como senal de estado.
    $paths = [
        'gauge' => '<path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="m13.4 10.6 3.6-3.6"/><path d="M3.3 17a9 9 0 1 1 17.4 0"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13a2 2 0 0 1 1.9 1.4L22 12v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6l2.6-5.6A2 2 0 0 1 5.5 5Z"/>',
        'swords' => '<path d="m14.5 17.5 6-6V4h-7.5l-6 6"/><path d="m9.5 6.5-6 6V20h7.5l6-6"/><path d="m5 19 3-3"/><path d="m16 8 3-3"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
        'sliders' => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M1 14h6"/><path d="M9 8h6"/><path d="M17 16h6"/>',
        'map' => '<path d="m9 4 6 2 5-2v14l-5 2-6-2-5 2V6l5-2Z"/><path d="M9 4v14"/><path d="M15 6v14"/>',
        'flask' => '<path d="M10 2v6.6L4.6 18A2 2 0 0 0 6.3 21h11.4a2 2 0 0 0 1.7-3L14 8.6V2"/><path d="M8.5 2h7"/><path d="M7 15h10"/>',
        'alert' => '<path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check' => '<path d="m5 13 4 4L19 7"/>',
        'play' => '<path d="M6 4.5v15l13-7.5-13-7.5Z"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'menu' => '<path d="M3 6h18"/><path d="M3 12h18"/><path d="M3 18h18"/>',
        'close' => '<path d="M6 6 18 18"/><path d="M18 6 6 18"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'trash' => '<path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M5 7l1 13a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-13"/><path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'bow' => '<path d="M5 19 19 5"/><path d="M17 3a10 10 0 0 1 4 4"/><path d="M3 17a10 10 0 0 0 4 4"/><path d="M18 6 6 18"/>',
        'wand' => '<path d="m6 18 9-9"/><path d="M15 4v3"/><path d="M18.5 5.5 17 7"/><path d="M20 10h-3"/><circle cx="16" cy="8" r="2.5"/>',
        'axe' => '<path d="m4 20 8-8"/><path d="M13 3 21 11l-4 3-7-7Z"/>',
        'sparkle' => '<path d="M12 3v4"/><path d="M12 17v4"/><path d="M3 12h4"/><path d="M17 12h4"/><path d="m6 6 2.5 2.5"/><path d="m15.5 15.5 2.5 2.5"/><path d="m18 6-2.5 2.5"/><path d="m8.5 15.5-2.5 2.5"/>',
        'dot' => '<circle cx="12" cy="12" r="4"/>',
    ];
@endphp
<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    {!! $paths[$name] ?? $paths['dot'] !!}
</svg>
