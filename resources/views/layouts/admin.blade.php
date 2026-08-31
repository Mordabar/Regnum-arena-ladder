<!DOCTYPE html>
<html lang="es" class="ap-root">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Panel') · Arena Ladder</title>

    {{-- Hoja compilada y versionada. El panel no depende del CDN de Tailwind:
         es una herramienta de trabajo y tiene que verse igual sin red. --}}
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ @filemtime(public_path('css/admin.css')) ?: '1' }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    @stack('styles')
    @stack('arena-map-styles')
</head>
<body>
@php
    $navSections = \App\Support\AdminNavigation::sections();
    $pending = \App\Support\AdminNavigation::pendingCounts();
    $adminName = session('arena_admin.display_name', 'Admin');
@endphp

<a href="#ap-main" class="ap-skip">Saltar al contenido</a>

<div class="ap-shell">

    {{-- ── Barra lateral ─────────────────────────────────────────── --}}
    <aside class="ap-sidebar" id="ap-sidebar" data-open="false">
        <div class="ap-sidebar-head">
            <a href="{{ route('admin.dashboard') }}" class="ap-brand">
                <span class="ap-brand-mark" aria-hidden="true">RA</span>
                <span class="ap-brand-text">
                    <strong>Arena Ladder</strong>
                    <span>Panel de control</span>
                </span>
            </a>
            <button type="button" class="ap-icon-btn ap-sidebar-close" data-ap-close-nav aria-label="Cerrar menu">
                <x-admin.icon name="close" class="h-4 w-4" />
            </button>
        </div>

        <nav class="ap-nav" aria-label="Secciones del panel">
            @foreach($navSections as $section)
                <div class="ap-nav-group">
                    <p class="ap-nav-section">{{ $section['label'] }}</p>
                    @foreach($section['items'] as $item)
                        <a href="{{ $item['url'] }}"
                           class="ap-nav-link"
                           @if($item['active']) aria-current="page" @endif>
                            <x-admin.icon :name="$item['icon']" class="h-4 w-4 shrink-0" />
                            <span>{{ $item['label'] }}</span>
                            @if($item['count'] > 0)
                                <span class="ap-nav-count" title="{{ $item['count'] }} sin resolver">{{ $item['count'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <div class="ap-sidebar-foot">
            <a href="{{ route('home') }}" class="ap-nav-link" target="_blank" rel="noopener">
                <x-admin.icon name="external" class="h-4 w-4 shrink-0" />
                <span>Ver sitio publico</span>
            </a>
            <div class="ap-account">
                <span class="ap-account-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($adminName, 0, 1)) }}</span>
                <span class="ap-account-name">{{ $adminName }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="ap-icon-btn" title="Cerrar sesion" aria-label="Cerrar sesion">
                        <x-admin.icon name="logout" class="h-4 w-4" />
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="ap-backdrop" data-ap-close-nav hidden></div>

    {{-- ── Columna principal ─────────────────────────────────────── --}}
    <div class="ap-main-col">
        <header class="ap-topbar">
            <button type="button" class="ap-icon-btn ap-nav-toggle" data-ap-open-nav aria-label="Abrir menu" aria-controls="ap-sidebar">
                <x-admin.icon name="menu" class="h-5 w-5" />
            </button>

            <div class="ap-topbar-title">
                <h1>@yield('page-title', 'Panel')</h1>
                @hasSection('page-subtitle')
                    <p>@yield('page-subtitle')</p>
                @endif
            </div>

            <div class="ap-topbar-actions">
                @yield('page-actions')
            </div>
        </header>

        <main class="ap-main" id="ap-main" tabindex="-1">
            @if(session('success'))
                <div class="ap-flash ap-flash-ok ap-rise" role="status">
                    <x-admin.icon name="check" class="h-4 w-4 shrink-0" />
                    <span>{{ session('success') }}</span>
                </div>
            @endif

            @if(session('error') || $errors->has('error'))
                <div class="ap-flash ap-flash-danger ap-rise" role="alert">
                    <x-admin.icon name="alert" class="h-4 w-4 shrink-0" />
                    <span>{{ session('error') ?: $errors->first('error') }}</span>
                </div>
            @endif

            @if($errors->any() && !$errors->has('error'))
                <div class="ap-flash ap-flash-danger ap-rise" role="alert">
                    <x-admin.icon name="alert" class="h-4 w-4 shrink-0" />
                    <div>
                        <p>Revisa los datos enviados:</p>
                        <ul>
                            @foreach($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<script>
    // Cajon lateral en pantallas pequenas. Sin dependencias: el panel tiene que
    // seguir siendo usable aunque falle cualquier recurso externo.
    (function () {
        const sidebar = document.getElementById('ap-sidebar');
        const backdrop = document.querySelector('.ap-backdrop');
        if (!sidebar || !backdrop) return;

        const setOpen = (open) => {
            sidebar.dataset.open = open ? 'true' : 'false';
            backdrop.hidden = !open;
            document.body.classList.toggle('ap-locked', open);
        };

        document.querySelectorAll('[data-ap-open-nav]').forEach((el) => el.addEventListener('click', () => setOpen(true)));
        document.querySelectorAll('[data-ap-close-nav]').forEach((el) => el.addEventListener('click', () => setOpen(false)));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') setOpen(false);
        });
    })();

    // Confirmacion para acciones destructivas. Se declara en el marcado con
    // data-ap-confirm para que ninguna pantalla se olvide de ponerla.
    document.addEventListener('submit', (event) => {
        const message = event.target.getAttribute('data-ap-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
</script>
@stack('scripts')
@stack('arena-map-scripts')
<script>
    // Modal minimo del panel: lo usa la auditoria de zona del detalle de match.
    (function () {
        const close = (id) => {
            const el = document.getElementById(id);
            if (el) { el.style.display = 'none'; document.body.classList.remove('ap-locked'); }
        };

        document.addEventListener('click', (event) => {
            const opener = event.target.closest('[data-modal-open]');
            if (opener) {
                const el = document.getElementById(opener.dataset.modalOpen);
                if (el) { el.style.display = 'flex'; document.body.classList.add('ap-locked'); }
                window.dispatchEvent(new Event('resize'));
                return;
            }
            const closer = event.target.closest('[data-modal-close]');
            if (closer) close(closer.dataset.modalClose);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('[role="dialog"]').forEach((el) => close(el.id));
        });
    })();
</script>
</body>
</html>
