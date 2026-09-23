@props(['name' => 'dot', 'class' => 'h-4 w-4'])
@php
    // Trazos, no emojis: los emojis se dibujan distinto en cada sistema y no
    // heredan el color del texto, asi que rompen la coherencia y no sirven como
    // senal. Aqui viven los del sitio publico; el panel tiene los suyos.
    $paths = [
        // Subclases: cada una por su arma, que es como se reconocen en el juego.
        'knight'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'barbarian' => '<path d="m4 20 8-8"/><path d="M13 3 21 11l-4 3-7-7Z"/>',
        'hunter'    => '<path d="M5 19 19 5"/><path d="M17 3a10 10 0 0 1 4 4"/><path d="M3 17a10 10 0 0 0 4 4"/>',
        'marksman'  => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2v3"/><path d="M12 19v3"/><path d="M2 12h3"/><path d="M19 12h3"/>',
        'conjurer'  => '<path d="M12 3v4"/><path d="M12 17v4"/><path d="M3 12h4"/><path d="M17 12h4"/><path d="m6 6 2.5 2.5"/><path d="m15.5 15.5 2.5 2.5"/><path d="m18 6-2.5 2.5"/><path d="m8.5 15.5-2.5 2.5"/>',
        'warlock'   => '<path d="m6 18 9-9"/><path d="M15 4v3"/><path d="M18.5 5.5 17 7"/><path d="M20 10h-3"/><circle cx="16" cy="8" r="2.5"/>',

        // Razas: el rasgo que las distingue, que es lo que el jugador elige.
        'human'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'ears'    => '<circle cx="12" cy="10" r="4"/><path d="m8 8-3-5 4 2"/><path d="m16 8 3-5-4 2"/><path d="M5 21a7 7 0 0 1 14 0"/>',
        'ears-short' => '<circle cx="12" cy="10" r="4"/><path d="m8.5 7.5-2-2.5 2.5 1"/><path d="m15.5 7.5 2-2.5-2.5 1"/><path d="M5 21a7 7 0 0 1 14 0"/>',
        'ears-big' => '<circle cx="12" cy="12" r="3.5"/><path d="M8.5 10C6 8 4 4 4 4s4 1 5.5 3.5"/><path d="M15.5 10C18 8 20 4 20 4s-4 1-5.5 3.5"/><path d="M6.5 21a5.5 5.5 0 0 1 11 0"/>',
        'horns'   => '<circle cx="12" cy="11" r="4"/><path d="M8 8C7 5 4 3 4 3s0 4 2 6"/><path d="M16 8c1-3 4-5 4-5s0 4-2 6"/><path d="M5 21a7 7 0 0 1 14 0"/>',
        'beard'   => '<circle cx="12" cy="8" r="3.5"/><path d="M8 10c0 5 1.6 8 4 8s4-3 4-8"/><path d="M4 21a8 8 0 0 1 4-6.9"/><path d="M20 21a8 8 0 0 0-4-6.9"/>',
        'hulk'    => '<circle cx="12" cy="6" r="2.5"/><path d="M6 21v-6a6 6 0 0 1 12 0v6"/><path d="M3 13a3 3 0 0 1 3-3"/><path d="M21 13a3 3 0 0 0-3-3"/>',

        // Sexo.
        'male'   => '<circle cx="10" cy="14" r="6"/><path d="M15 9 21 3"/><path d="M15 3h6v6"/>',
        'female' => '<circle cx="12" cy="9" r="6"/><path d="M12 15v6"/><path d="M9 18h6"/>',

        // Acciones de los botones: cada boton lleva el suyo.
        'send'    => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/>',
        'check'   => '<path d="M20 6 9 17l-5-5"/>',
        'x'       => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'eye'     => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'bolt'    => '<path d="M13 2 4 14h7l-1 8 9-12h-7Z"/>',
        'logout'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'login'   => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
        'ladder'  => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
        'plus'    => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'swap'    => '<path d="m17 3 4 4-4 4"/><path d="M3 7h18"/><path d="m7 21-4-4 4-4"/><path d="M21 17H3"/>',
        'book'    => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5Z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
        'trash'   => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/>',
        'shield'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'back'    => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'flag'    => '<path d="M4 22V4"/><path d="M4 4h13l-2 4 2 4H4"/>',
        'save'    => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/>',
        'eraser'  => '<path d="m7 21-4-4L14 6l7 7-8 8Z"/><path d="M22 21H7"/>',
        'scale'   => '<path d="M12 3v18"/><path d="M5 7h14"/><path d="m5 7-3 7a3 3 0 0 0 6 0Z"/><path d="m19 7-3 7a3 3 0 0 0 6 0Z"/>',
        'trophy'  => '<path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0Z"/><path d="M17 5h3a3 3 0 0 1-3 4"/><path d="M7 5H4a3 3 0 0 0 3 4"/>',

        'dot' => '<circle cx="12" cy="12" r="4"/>',
    ];
@endphp
<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    {!! $paths[$name] ?? $paths['dot'] !!}
</svg>
