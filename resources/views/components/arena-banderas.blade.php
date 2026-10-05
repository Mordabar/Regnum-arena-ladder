{{-- Las banderas del selector de idioma, como simbolos SVG.
     Los emoji de bandera no existen en Windows (salen como "ES", "DE"): dibujadas
     aqui se ven igual en todos los sistemas. Cada una en un lienzo de 36x24 y
     recortada en redondo por el CSS. Se incluye una sola vez por pagina. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <symbol id="flag-es" viewBox="0 0 36 24">
            <rect width="36" height="24" fill="#AA151B"/>
            <rect y="6" width="36" height="12" fill="#F1BF00"/>
            <rect x="9" y="9.5" width="4.4" height="5.2" rx="1.1" fill="#C8102E" opacity=".9"/>
        </symbol>
        <symbol id="flag-en" viewBox="0 0 36 24">
            <rect width="36" height="24" fill="#012169"/>
            <path d="M0 0 36 24M36 0 0 24" stroke="#fff" stroke-width="4.8"/>
            <path d="M0 0 36 24M36 0 0 24" stroke="#C8102E" stroke-width="2"/>
            <path d="M18 0v24M0 12h36" stroke="#fff" stroke-width="8"/>
            <path d="M18 0v24M0 12h36" stroke="#C8102E" stroke-width="4.8"/>
        </symbol>
        <symbol id="flag-pt" viewBox="0 0 36 24">
            <rect width="36" height="24" fill="#009C3B"/>
            <path d="M18 2.2 33.6 12 18 21.8 2.4 12Z" fill="#FFDF00"/>
            <circle cx="18" cy="12" r="5.6" fill="#002776"/>
            <path d="M12.6 10.8c3.6-1 7.6-.6 10.8 1.4" fill="none" stroke="#fff" stroke-width="1.1"/>
        </symbol>
        <symbol id="flag-de" viewBox="0 0 36 24">
            <rect width="36" height="8" fill="#111"/>
            <rect y="8" width="36" height="8" fill="#DD0000"/>
            <rect y="16" width="36" height="8" fill="#FFCE00"/>
        </symbol>
        <symbol id="flag-fr" viewBox="0 0 36 24">
            <rect width="12" height="24" fill="#0055A4"/>
            <rect x="12" width="12" height="24" fill="#fff"/>
            <rect x="24" width="12" height="24" fill="#EF4135"/>
        </symbol>
        <symbol id="flag-nl" viewBox="0 0 36 24">
            <rect width="36" height="8" fill="#AE1C28"/>
            <rect y="8" width="36" height="8" fill="#fff"/>
            <rect y="16" width="36" height="8" fill="#21468B"/>
        </symbol>
    </defs>
</svg>
