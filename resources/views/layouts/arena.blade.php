<!DOCTYPE html>
<html lang="{{ \App\Support\I18n\Idioma::htmlLang() }}">
<head>
    @auth
    @if(app(\App\Services\WebPushService::class)->configurado())
    <script>
    /* El push (y las notificaciones del sistema) solo en el movil: ahí el
       navegador se duerme en cuanto se cambia de app y es la unica forma de
       avisar. En el escritorio basta el sonido con la pestaña en reposo. */
    (function () {
        /* Escritorio de verdad: puntero fino Y ninguna pantalla tactil Y no es
           una app instalada. Todo lo demas (movil, tablet, iPad con teclado,
           Android con raton, la app instalada) va con push. Equivocarse hacia
           este lado solo cuesta un permiso; hacia el otro, dejar a alguien
           sin avisos. */
        var instalada = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
            || window.navigator.standalone === true;
        var escritorio = !instalada
            && (navigator.maxTouchPoints || 0) === 0
            && !!(window.matchMedia && window.matchMedia('(pointer: fine)').matches);
        if (!escritorio) { document.documentElement.setAttribute('data-arena-push', ''); }
        else { document.documentElement.setAttribute('data-arena-escritorio', ''); }
    })();
    </script>
    @endif
    @endauth
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Regnum Arena Ladder')</title>
    @include('partials.seo-head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- El sitio como app instalable. No es adorno: en iPhone, los avisos con
         la pagina cerrada SOLO existen para un sitio añadido a la pantalla de
         inicio con manifiesto; sin el, "añadir a inicio" crea un marcador y el
         push no llega nunca. En Android deja ademas instalarlo como app. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#16100a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Arena Ladder">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700;800&family=Spectral:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    {{-- Los estilos propios del sitio, en su propio fichero para que el navegador
         los guarde en cache. Van antes de site.css: ver public/css/arena.css. --}}
    <link rel="stylesheet" href="{{ asset('css/arena.css') }}?v={{ @filemtime(public_path('css/arena.css')) ?: '1' }}">
    {{-- Utilidades de Tailwind, compiladas y servidas desde este dominio.

         Antes esto era <script src="cdn.tailwindcss.com">, que compila Tailwind
         en el navegador de cada visitante: si ese dominio fallaba, la pagina se
         quedaba sin una sola clase.

         Va DESPUES de css/arena.css (arriba) a proposito. El CDN inyectaba sus
         reglas al final de la cabecera, asi que las utilidades ganaban a las
         clases arena-* cuando compartian propiedad. El marcado depende de ello:
         "arena-field px-4 py-2" o "arena-nav-link block w-full" solo tienen
         sentido si el px-4 y el block ganan. Moverlo antes de arena.css cambia
         el relleno de campos y botones y rompe el menu movil. Comprobado
         comparando los estilos calculados de las 1.438 etiquetas de las dos
         paginas con cada orden. --}}
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ @filemtime(public_path('css/site.css')) ?: '1' }}">
    @stack('arena-map-styles')
</head>
@php
    $arenaAdminSessionActive = session('arena_admin.authenticated') === true;
    $arenaAdminDisplayName = session('arena_admin.display_name', 'admin');
    // En el movil la navegacion del jugador va en la barra de abajo; la
    // hamburguesa solo queda para lo de admin, arriba junto a la campana.
    $arenaContextoAdmin = request()->routeIs('admin.*') && $arenaAdminSessionActive;
    $arenaConMenu = $arenaAdminSessionActive;
@endphp
<body class="arena-shell min-h-screen {{ $arenaConMenu ? 'arena-con-menu' : '' }} {{ $arenaContextoAdmin ? '' : 'arena-con-tabbar' }}">
    <script>
        /* Registro de arranques.
           El panel del lobby ya no obliga a recargar la pagina: el sondeo trae
           su HTML y lo cambia en su sitio. Eso deja sin efecto a los scripts
           que buscaban sus nodos una sola vez, asi que en vez de correr sueltos
           se apuntan aqui y se vuelven a pasar sobre el trozo nuevo. Cada uno
           tiene que poder ejecutarse dos veces sin duplicar nada. */
        window.ArenaBoot = (function () {
            var inits = [];

            function runOne(fn, root) {
                try { fn(root || document); } catch (error) { console.error(error); }
            }

            return {
                register: function (fn) {
                    inits.push(fn);
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', function () { runOne(fn, document); });
                    } else {
                        runOne(fn, document);
                    }
                },
                run: function (root) {
                    inits.forEach(function (fn) { runOne(fn, root); });
                }
            };
        })();
    </script>
    <a href="#contenido" class="arena-skip">{{ __('Saltar al contenido') }}</a>
    {{-- Donde se anuncia a un lector de pantalla que el estado cambio en su sitio. --}}
    <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-estado-anuncio></div>
    {{-- ── NAVBAR ── --}}
    <nav class="arena-navbar sticky top-0 z-40" data-arena-navbar>
        <div class="mx-auto max-w-7xl px-4 py-3">
            <div class="flex items-center justify-between gap-4">
                {{-- `shrink-0` aqui le prohibia encoger, y la marca mide 381 px:
                     en una pantalla de 400 el boton de menu se quedaba sin
                     sitio y empujaba el documento 55 px a la derecha, asi que
                     la pagina se movia de lado al arrastrarla en el movil. --}}
                <a href="{{ route('home') }}" class="min-w-0">
                    <x-arena-brand compact />
                </a>

                {{-- Desktop nav --}}
                <div class="hidden items-center gap-2 lg:flex">
                    @if(request()->routeIs('admin.*') && $arenaAdminSessionActive)
                        {{-- Contexto Administrativo --}}
                        <a href="{{ route('admin.dashboard') }}" class="arena-nav-link {{ request()->routeIs('admin.dashboard') ? 'arena-nav-link-active' : '' }}">Dashboard</a>
                        <a href="{{ route('admin.inbox') }}" class="arena-nav-link {{ request()->routeIs('admin.inbox') ? 'arena-nav-link-active' : '' }}">Inbox</a>
                        <a href="{{ route('admin.matches.index') }}" class="arena-nav-link {{ request()->routeIs('admin.matches.*') ? 'arena-nav-link-active' : '' }}">Matches</a>
                        <a href="{{ route('admin.players.index') }}" class="arena-nav-link {{ request()->routeIs('admin.players.*') ? 'arena-nav-link-active' : '' }}">Jugadores</a>
                        <a href="{{ route('admin.zones') }}" class="arena-nav-link {{ request()->routeIs('admin.zones') ? 'arena-nav-link-active' : '' }}">Zonas</a>
                        <a href="{{ route('admin.settings') }}" class="arena-nav-link {{ request()->routeIs('admin.settings') ? 'arena-nav-link-active' : '' }}">Config</a>
                        
                        <div class="mx-1 h-6 w-px bg-[color:var(--arena-line-strong)]"></div>
                        <a href="{{ route('home') }}" class="arena-nav-link text-xs text-[color:var(--arena-muted)] hover:text-white">Cambiar al Juego</a>
                        <button type="button" class="arena-btn-ghost px-3 py-1.5 text-xs" data-arena-alert-toggle>
                            <span class="inline-block h-2 w-2 rounded-full bg-amber-300" data-arena-alert-indicator></span>
                            <span data-arena-alert-label>Avisos</span>
                        </button>
                        <span class="arena-chip hidden border-amber-500/30 bg-amber-950/30 text-amber-100 lg:inline-flex">🛡️ {{ $arenaAdminDisplayName }}</span>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit" class="arena-btn-ghost px-3 py-1.5 text-xs"><x-arena-icon name="logout" class="h-4 w-4 shrink-0" />Cerrar Admin</button>
                        </form>
                    @else
                        {{-- Contexto de Jugador (Juego) --}}
                        {{-- Mismo orden que la barra del movil: Lobby, Ladder,
                             Matches, Fama y Guia. --}}
                        @auth
                            <a href="{{ route('lobby') }}" class="arena-nav-link relative {{ request()->routeIs('lobby') ? 'arena-nav-link-active' : '' }}">
                                <x-arena-icon name="swords" class="h-4 w-4" />
                                Lobby
                            </a>
                        @endauth
                        <a href="{{ route('ladder.index') }}" class="arena-nav-link {{ request()->routeIs('ladder.*') ? 'arena-nav-link-active' : '' }}">
                            <x-arena-icon name="ladder" class="h-4 w-4" />
                            Ladder
                        </a>
                        @auth
                            <a href="{{ route('matches.index') }}" class="arena-nav-link {{ request()->routeIs('matches.*') ? 'arena-nav-link-active' : '' }}">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/></svg>
                                Matches
                            </a>
                        @endauth
                        <a href="{{ route('hall-of-fame') }}" class="arena-nav-link {{ request()->routeIs('hall-of-fame') ? 'arena-nav-link-active' : '' }}">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 1l2.39 4.84 5.34.78-3.86 3.77.91 5.32L10 13.2l-4.78 2.51.91-5.32L2.27 6.62l5.34-.78L10 1z"/></svg>
                            Fama
                        </a>
                        {{-- Las dos guias en un desplegable: sueltas, la barra no
                             cabia en un portatil y partia los rotulos en dos
                             lineas. --}}
                        <details class="arena-nav-drop" data-nav-drop>
                            <summary class="arena-nav-link {{ request()->routeIs('como-jugar', 'guia') ? 'arena-nav-link-active' : '' }}">
                                <x-arena-icon name="book" class="h-4 w-4" />
                                Guía
                                <svg class="arena-nav-drop-flecha" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg>
                            </summary>
                            <div class="arena-nav-drop-panel">
                                <a href="{{ route('como-jugar') }}" class="{{ request()->routeIs('como-jugar') ? 'is-activa' : '' }}">
                                    <x-arena-icon name="book" class="h-4 w-4" />
                                    <span><b>Cómo jugar</b><small>Paso a paso, con capturas</small></span>
                                </a>
                                <a href="{{ route('guia') }}" class="{{ request()->routeIs('guia') ? 'is-activa' : '' }}">
                                    <x-arena-icon name="scale" class="h-4 w-4" />
                                    <span><b>Cómo funciona</b><small>Reglas y puntuación</small></span>
                                </a>
                            </div>
                        </details>
                        @auth
                            <button type="button" class="arena-btn-ghost px-3 py-1.5 text-xs" data-arena-alert-toggle>
                                <span class="inline-block h-2 w-2 rounded-full bg-amber-300" data-arena-alert-indicator></span>
                                <span data-arena-alert-label>Avisos</span>
                            </button>
                            <span class="arena-chip hidden lg:inline-flex">{{ auth()->user()->discord_username }}</span>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="arena-btn-ghost px-3 py-1.5 text-xs"><x-arena-icon name="logout" class="h-4 w-4 shrink-0" />Salir</button>
                            </form>
                        @else
                            <a href="{{ route('auth.discord') }}" class="arena-btn-secondary">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
                                Entrar con Discord
                            </a>
                        @endauth
                        
                        @if($arenaAdminSessionActive)
                            <div class="mx-1 h-5 w-px bg-[color:var(--arena-line-strong)]"></div>
                            <a href="{{ route('admin.dashboard') }}" class="inline-flex items-center gap-1.5 rounded-full border border-[color:var(--arena-gold-soft)]/20 bg-black/40 px-3 py-1.5 text-[0.75rem] font-semibold text-[color:var(--arena-gold-soft)] transition hover:border-[color:var(--arena-gold-soft)]/40 hover:bg-white/10">
                                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
                                Admin Panel
                            </a>
                        @endif
                    @endif
                </div>

                {{-- Hamburguesa del movil: solo para lo de admin. --}}
                @if($arenaConMenu)
                <button type="button" class="lg:hidden rounded-xl border border-[color:var(--arena-line)] bg-[rgba(15,10,8,0.7)] p-2.5 text-[color:var(--arena-sand)] transition hover:bg-white/10" id="arenaMenuOpen" aria-label="Abrir menú">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
                </button>
                @endif
            </div>
        </div>
    </nav>

    {{-- ── MOBILE MENU DRAWER ── --}}
    <div class="arena-mobile-menu" id="arenaMobileMenu">
        <div class="arena-mobile-menu-backdrop" id="arenaMenuBackdrop"></div>
        <div class="arena-mobile-menu-panel">
            <div class="flex items-center justify-between border-b border-[color:var(--arena-line)] px-5 py-4">
                <span class="font-['Cinzel'] text-sm font-semibold text-[color:var(--arena-gold-soft)]">Menú</span>
                <button type="button" class="rounded-full p-2 text-[color:var(--arena-muted)] hover:text-white" id="arenaMenuClose" aria-label="Cerrar menú">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>
            </div>
            <div class="space-y-1 px-4 py-4">
                @if(request()->routeIs('admin.*') && $arenaAdminSessionActive)
                    {{-- Mobile Admin Context --}}
                    <div class="mb-3 px-3">
                        <span class="text-[0.65rem] font-semibold uppercase tracking-[0.2em] text-[color:var(--arena-gold-soft)]">Modo Moderación</span>
                    </div>
                    <a href="{{ route('admin.dashboard') }}" class="arena-nav-link block w-full {{ request()->routeIs('admin.dashboard') ? 'arena-nav-link-active' : '' }}">Dashboard</a>
                    <a href="{{ route('admin.inbox') }}" class="arena-nav-link block w-full {{ request()->routeIs('admin.inbox') ? 'arena-nav-link-active' : '' }}">Inbox</a>
                    <a href="{{ route('admin.matches.index') }}" class="arena-nav-link block w-full {{ request()->routeIs('admin.matches.*') ? 'arena-nav-link-active' : '' }}">Matches</a>
                    <a href="{{ route('admin.players.index') }}" class="arena-nav-link block w-full {{ request()->routeIs('admin.players.*') ? 'arena-nav-link-active' : '' }}">Jugadores</a>
                    <a href="{{ route('admin.zones') }}" class="arena-nav-link block w-full {{ request()->routeIs('admin.zones') ? 'arena-nav-link-active' : '' }}">Zonas de Mapa</a>
                    <a href="{{ route('admin.settings') }}" class="arena-nav-link block w-full {{ request()->routeIs('admin.settings') ? 'arena-nav-link-active' : '' }}">Configuración</a>
                    <a href="{{ route('admin.testing') }}" class="arena-nav-link block w-full {{ request()->routeIs('admin.testing') ? 'arena-nav-link-active' : '' }}">Testing</a>
                    <button type="button" class="arena-btn-ghost mt-3 w-full justify-center" data-arena-alert-toggle>
                        <span class="inline-block h-2 w-2 rounded-full bg-amber-300" data-arena-alert-indicator></span>
                        <span data-arena-alert-label>Avisos</span>
                    </button>
                    
                    <div class="my-4 border-t border-[color:var(--arena-line)]"></div>
                    <a href="{{ route('home') }}" class="block text-center text-sm font-semibold text-[color:var(--arena-sand)] hover:text-white">Cambiar al juego</a>
                    <form method="POST" action="{{ route('admin.logout') }}" class="mt-3">
                        @csrf
                        <button type="submit" class="arena-btn-danger-ghost w-full"><x-arena-icon name="logout" class="h-4 w-4 shrink-0" />Cerrar Sesión Admin</button>
                    </form>
                @else
                    {{-- La navegacion del jugador esta en la barra de abajo;
                         aqui solo queda el acceso de admin. --}}
                    @if($arenaAdminSessionActive)
                        <div class="mb-2 px-3">
                            <span class="text-[0.65rem] font-semibold uppercase tracking-[0.2em] text-[color:var(--arena-gold-soft)]">Staff Access</span>
                        </div>
                        <a href="{{ route('admin.dashboard') }}" class="arena-btn w-full justify-center">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
                            Panel Admin
                        </a>
                    @endif
                @endif
            </div>
        </div>
    </div>

    {{-- ── MAIN CONTENT ── --}}
    <main id="contenido" class="flex-1 pb-16 pt-4" tabindex="-1">
        @if(session('success') || session('warning') || session('error') || $errors->any())
            <div class="mx-auto max-w-7xl px-4 pt-6">
                @if(session('success'))
                    <div class="arena-animate-in mb-4 flex items-start gap-3 rounded-2xl border border-emerald-500/30 bg-emerald-950/35 px-5 py-4 text-emerald-100 shadow-[0_10px_28px_rgba(12,55,38,0.18)]">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <span>{{ session('success') }}</span>
                    </div>
                @endif
                @if(session('warning'))
                    <div class="arena-animate-in mb-4 flex items-start gap-3 rounded-2xl border border-amber-500/30 bg-amber-950/35 px-5 py-4 text-amber-100 shadow-[0_10px_28px_rgba(77,44,8,0.18)]">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        <span>{{ session('warning') }}</span>
                    </div>
                @endif
                @if(session('error'))
                    <div class="arena-animate-in mb-4 flex items-start gap-3 rounded-2xl border border-rose-500/30 bg-rose-950/35 px-5 py-4 text-rose-100 shadow-[0_10px_28px_rgba(74,22,22,0.18)]">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-rose-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                        <span>{{ session('error') }}</span>
                    </div>
                @endif
                @if($errors->any())
                    <div class="arena-animate-in mb-4 flex items-start gap-3 rounded-2xl border border-rose-500/30 bg-rose-950/35 px-5 py-4 text-rose-100 shadow-[0_10px_28px_rgba(74,22,22,0.18)]">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-rose-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                        <div>
                            @foreach($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif

        @yield('content')
    </main>

    {{-- ── FOOTER ── --}}
    <x-arena-footer />

    {{-- ── TOAST CONTAINER ── --}}
    <x-arena-toast />

    {{-- ── BARRA DE ABAJO (movil) ── --}}
    @unless($arenaContextoAdmin)
        @include('partials.arena-tabbar')
    @endunless

    {{-- ── IDIOMA ── El panel de administracion se queda en español. --}}
    @unless($arenaContextoAdmin)
        <x-arena-idiomas />
    @endunless

    {{-- ── GLOBAL SCRIPTS ── --}}
    @include('partials.arena-scripts')

    {{-- Las ventanas, al final del documento y fuera de todo panel. Dentro de
         la consola del lobby quedaban recortadas por su overflow. --}}
    <script>
        /* La altura real de la barra de navegacion, para que lo que se queda
           pegado debajo no acabe tapado por ella. Cambia con el ancho: en movil
           es mas alta cuando el nombre del sitio salta de linea. */
        (function () {
            var navbar = document.querySelector('[data-arena-navbar]');
            if (!navbar) { return; }

            var publish = function () {
                document.documentElement.style.setProperty(
                    '--arena-navbar-height',
                    Math.round(navbar.getBoundingClientRect().height) + 'px'
                );
            };

            publish();

            if (window.ResizeObserver) {
                new ResizeObserver(publish).observe(navbar);
            } else {
                window.addEventListener('resize', publish);
            }
        })();
    </script>

    @stack('arena-modals')

    {{-- Los modales que llegan con el panel repintado. Van fuera de la consola
         porque esta recorta lo que sobresale, y en su propio hueco para poder
         cambiarlos sin tocar los del resto de la pagina. --}}
    <div data-console-modals></div>

    @stack('champion-boot')

    @stack('arena-map-scripts')
    @include('partials.arena-map-runtime')
    @include('partials.arena-pings-runtime')
    @include('partials.arena-zona-runtime')
    @include('partials.arena-push-runtime')
    @auth
    <script>
    /* En el escritorio no hay push. Quien los activo en la version anterior
       sigue teniendo el worker y la suscripcion: se retiran (tambien en el
       servidor), para que no vuelva a salir ninguna tarjeta del sistema. */
    (function () {
        /* SOLO en un escritorio de verdad (lo decide el <head>). Antes bastaba
           con que faltara la marca del push, y la marca falta tambien cuando
           el servidor tiene el push apagado o su configuracion cacheada: en
           ese caso esto borraba la suscripcion de los iPhone y Android. Nunca
           en una app instalada. */
        if (!document.documentElement.hasAttribute('data-arena-escritorio')) { return; }
        if (window.navigator.standalone === true) { return; }
        if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) { return; }
        if (!('serviceWorker' in navigator)) { return; }
        navigator.serviceWorker.getRegistrations().then(function (regs) {
            regs.forEach(function (reg) {
                var baja = reg.pushManager ? reg.pushManager.getSubscription().then(function (s) {
                    if (!s) { return; }
                    try {
                        navigator.sendBeacon(@json(route('avisos.fallo')), new Blob([JSON.stringify({
                            causa: 'limpieza-escritorio', detalle: 'se retira el push de este navegador de escritorio',
                        })], { type: 'application/json' }));
                    } catch (e) {}
                    var meta = document.querySelector('meta[name="csrf-token"]');
                    fetch(@json(route('avisos.desuscribir')), {
                        method: 'POST', credentials: 'same-origin', keepalive: true,
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': meta ? meta.content : '' },
                        body: JSON.stringify({ endpoint: s.endpoint }),
                    }).catch(function () {});
                    return s.unsubscribe();
                }) : Promise.resolve();
                baja.catch(function () {}).then(function () { return reg.unregister(); }).catch(function () {});
            });
        }).catch(function () {});
    })();
    </script>
    @endauth
    {{-- Despues del runtime: necesita `window.ArenaPush` para saber si los
         avisos estan activos de verdad, no solo segun el ajuste guardado. --}}
    @include('partials.arena-avisos-boton')
    <script>
        /* Relojes de la arena.
           Un solo motor para los tres: el plazo para aceptar el cruce, el plazo
           para pelear y reportar, y el tiempo que llevas en cola. El servidor ya
           pinta el valor correcto; esto solo lo mantiene vivo, asi que sin
           JavaScript la pagina sigue diciendo algo cierto. */
        (function () {
            var reloaded = false;
            // Solo se recarga si el reloj llega a cero MIENTRAS la pagina esta
            // abierta. Si ya llego a cero antes de cargar, recargar solo
            // encadenaria recargas infinitas sobre un estado que el servidor
            // todavia no ha limpiado.
            var startedRunning = false;

            function format(total) {
                var m = Math.floor(total / 60);
                var s = total % 60;
                return m + ':' + (s < 10 ? '0' : '') + s;
            }

            function tick() {
                var now = Math.floor(Date.now() / 1000);

                // Se consultan en cada vuelta, no una sola vez al cargar: el
                // panel del lobby se repinta entero cuando cambia el estado y
                // los relojes de dentro son nodos nuevos.
                document.querySelectorAll('[data-arena-clock]').forEach(function (clock) {
                    var value = clock.querySelector('[data-clock-value]');
                    if (!value) { return; }

                    var since = parseInt(clock.dataset.clockSince || '0', 10);
                    if (since) {
                        value.textContent = format(Math.max(0, now - since));
                        return;
                    }

                    var expires = parseInt(clock.dataset.clockExpires || '0', 10);
                    if (!expires) { return; }

                    var left = Math.max(0, expires - now);
                    var total = Math.max(1, parseInt(clock.dataset.clockTotal || '300', 10));
                    var urgentAt = parseInt(clock.dataset.clockUrgent || '20', 10);

                    value.textContent = format(left);
                    clock.classList.toggle('is-urgent', left <= urgentAt);

                    var arc = clock.querySelector('[data-clock-arc]');
                    if (arc) {
                        var circumference = parseFloat(arc.style.strokeDasharray) || 0;
                        arc.style.strokeDashoffset = (circumference * (1 - Math.min(1, left / total))).toFixed(2);
                    }

                    // Al agotarse, el servidor ya ha decidido: se recarga una vez
                    // para ensenar lo que paso en vez de un reloj clavado en cero.
                    if (left > 0) { startedRunning = true; }

                    if (left === 0 && startedRunning && clock.dataset.clockReload === '1' && !reloaded) {
                        reloaded = true;
                        window.setTimeout(function () { (window.arenaRecargar || function () { window.location.reload(); })(); }, 1500);
                    }
                });
            }

            tick();
            window.setInterval(tick, 1000);
        })();
    </script>
    <script>
        /* Comportamientos del panel del lobby.
           Viven aqui, y no en cada componente, porque el panel se repinta solo:
           un script que llegara dentro del trozo nuevo no se ejecutaria, y uno
           atado a los nodos viejos se iria con ellos. */
        (function () {
            /* Subir 3 imágenes tarda. Sin senal el jugador vuelve a pulsar y
               manda el reporte dos veces. */
            document.addEventListener('submit', function (event) {
                var form = event.target.closest('[data-report-form]');
                if (!form) { return; }

                var button = form.querySelector('[data-report-submit]');
                if (!button) { return; }

                button.disabled = true;
                button.textContent = 'Subiendo el reporte…';
            });

            /* Rechazar pide un motivo, y ese motivo no puede estar en otra
               pagina. */

            // Si el formulario vuelve abierto es porque el envio anterior
            // fallo: se lleva la vista hasta el, que si no el jugador se queda
            // mirando el panel sin entender por que no paso nada.
            window.ArenaBoot.register(function (root) {
                var abierto = (root || document).querySelector('[data-reject-form]:not([hidden])');
                if (!abierto || abierto.dataset.rejectFocused === '1') { return; }

                abierto.dataset.rejectFocused = '1';
                abierto.scrollIntoView({ block: 'center', behavior: 'smooth' });

                var nota = abierto.querySelector('textarea');
                if (nota) { nota.focus(); }
            });

            document.addEventListener('click', function (event) {
                if (!event.target.closest('[data-reject-toggle]')) { return; }

                var rejectForm = document.querySelector('[data-reject-form]');
                if (!rejectForm) { return; }

                rejectForm.hidden = !rejectForm.hidden;
                if (!rejectForm.hidden) {
                    var note = rejectForm.querySelector('textarea');
                    if (note) { note.focus(); }
                }
            });

            /* Invitaciones a party.
               Plegar no contesta: la invitacion sigue viva y se puede volver a
               abrir. Antes la aspa borraba la tarjeta, y como la invitacion
               seguia pendiente en el servidor el jugador se quedaba sin poder
               contestarla y sin poder recibir otra party. */
            var plegadas = new Set();

            var pintarPlegado = function (card, plegada) {
                var detalle = card.querySelector('[data-invite-detail]');
                var resumen = card.querySelector('[data-invite-unfold]');
                var boton = card.querySelector('[data-invite-fold]');

                card.classList.toggle('is-folded', plegada);
                if (detalle) { detalle.hidden = plegada; }
                if (resumen) { resumen.hidden = !plegada; }
                if (boton) {
                    boton.hidden = plegada;
                    boton.setAttribute('aria-expanded', plegada ? 'false' : 'true');
                }
            };

            document.addEventListener('click', function (event) {
                var card = event.target.closest('[data-arena-invite]');
                if (!card) { return; }

                if (event.target.closest('[data-invite-fold]')) {
                    plegadas.add(card.dataset.inviteId);
                    pintarPlegado(card, true);
                    return;
                }

                if (event.target.closest('[data-invite-unfold]')) {
                    plegadas.delete(card.dataset.inviteId);
                    pintarPlegado(card, false);
                }
            });

            // Tras un repintado las tarjetas son nodos nuevos: las que estaban
            // plegadas tienen que seguir plegadas, o volverian a abrirse solas
            // cada pocos segundos.
            window.ArenaBoot.register(function (root) {
                (root || document).querySelectorAll('[data-arena-invite]').forEach(function (card) {
                    pintarPlegado(card, plegadas.has(card.dataset.inviteId));
                });
            });

            /* El cruce tiene un reloj corriendo y puede aparecer con la pagina
               ya desplazada. Se lleva la vista hasta el y se deja el foco en
               aceptar, sin arrastrar el scroll por el enfoque. */
            window.ArenaBoot.register(function (root) {
                var panel = (root || document).querySelector('section.arena-duel-panel');
                if (!panel || panel.dataset.duelAnnounced === '1') { return; }

                panel.dataset.duelAnnounced = '1';
                // Solo el cruce encontrado, que corre contra el reloj, lleva la
                // vista hasta el, y solo si no se ve ya. Estar en cola no
                // mueve la pagina: quien acaba de pulsar el boton esta mirando
                // justo ahi, y arrastrarlo era perderle el sitio.
                var r = panel.getBoundingClientRect();
                var visible = r.top >= 0 && r.bottom <= window.innerHeight;
                if (!panel.classList.contains('is-waiting') && !visible) {
                    panel.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }

                var accept = panel.querySelector('button[data-duel-accept]');
                if (accept) {
                    try { accept.focus({ preventScroll: true }); } catch (error) { accept.focus(); }
                }
            });
        })();
    </script>


    {{-- El selector de archivos del navegador dice "Choose Files" en su idioma, no
         en el de la pagina: se esconde y se pone un boton propio, traducido. --}}
    <script>
        (function () {
            var T = { boton: @json(__('Elegir archivos')), ninguno: @json(__('Ningún archivo')), varios: @json(__(':n archivos')) };
            document.querySelectorAll('input[type=file]').forEach(function (inp) {
                var caja = document.createElement('span');
                caja.className = 'arena-file';
                caja.setAttribute('translate', 'no');
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'arena-file-btn'; b.textContent = T.boton;
                var t = document.createElement('span');
                t.className = 'arena-file-txt'; t.textContent = T.ninguno;
                caja.appendChild(b); caja.appendChild(t);
                inp.classList.add('arena-file-nativo');
                inp.parentNode.insertBefore(caja, inp);
                b.addEventListener('click', function () { inp.click(); });
                inp.addEventListener('change', function () {
                    var n = inp.files ? inp.files.length : 0;
                    t.textContent = n === 0 ? T.ninguno : (n === 1 ? inp.files[0].name : T.varios.replace(':n', n));
                });
            });
        })();
    </script>
    @include('partials.arena-sin-recarga')
    @stack('scripts')
</body>
</html>
