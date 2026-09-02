<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Regnum Arena Ladder')</title>
    <meta name="description" content="Regnum Arena Ladder — Conquest PvP por reino y subclase, ranking automático PL/MMR, anonimato rival.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700;800&family=Spectral:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        [x-cloak] { display: none !important; }

        /* ── Design tokens ── */
        :root {
            --arena-night: #0f0b08;
            --arena-earth: #1b130f;
            --arena-panel: rgba(24, 17, 13, 0.86);
            --arena-panel-strong: rgba(18, 12, 9, 0.94);
            --arena-line: rgba(222, 185, 99, 0.18);
            --arena-line-strong: rgba(232, 200, 122, 0.34);
            --arena-gold: #d8b15c;
            --arena-gold-soft: #f4deb1;
            --arena-ember: #d6772e;
            --arena-sand: #dcc49b;
            --arena-ice: #79b5d6;
            --arena-forest: #8eb34a;
            --arena-fire: #d3642f;
            --arena-text: #f3ebda;
            --arena-muted: #b4a387;
            --arena-shadow: 0 18px 45px rgba(0, 0, 0, 0.34);
        }

        /* ── Base ── */
        * { box-sizing: border-box; }

        body {
            font-family: "Spectral", Georgia, serif;
            color: var(--arena-text);
            background:
                radial-gradient(circle at 20% 8%, rgba(121, 181, 214, 0.18), transparent 24%),
                radial-gradient(circle at 76% 10%, rgba(142, 179, 74, 0.18), transparent 24%),
                radial-gradient(circle at 50% 82%, rgba(211, 100, 47, 0.2), transparent 26%),
                linear-gradient(180deg, rgba(72, 48, 27, 0.28), rgba(9, 7, 6, 0.86)),
                linear-gradient(135deg, #17110d 0%, #221711 46%, #110d0a 100%);
            min-height: 100vh;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: 0.10;
            background-image:
                linear-gradient(rgba(255, 236, 195, 0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 236, 195, 0.08) 1px, transparent 1px);
            background-size: 24px 24px;
            mask-image: radial-gradient(circle at center, black, transparent 78%);
        }

        /* ── Typography ── */
        h1, h2, h3, h4, h5, h6,
        .arena-heading,
        .arena-kicker,
        .arena-brand-type {
            font-family: "Cinzel", Georgia, serif;
        }

        .arena-body-text {
            font-family: "Inter", sans-serif;
        }

        /* ── Shell ── */
        .arena-shell {
            position: relative;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .arena-shell::after {
            content: "";
            position: fixed;
            inset: auto 8% 4% 8%;
            height: 160px;
            pointer-events: none;
            opacity: 0.22;
            background:
                radial-gradient(circle at center, rgba(246, 199, 94, 0.14), transparent 56%),
                linear-gradient(90deg, transparent, rgba(246, 199, 94, 0.18), transparent);
            filter: blur(36px);
        }

        /* ── Navbar ── */
        .arena-navbar {
            background:
                linear-gradient(180deg, rgba(48, 35, 25, 0.96), rgba(20, 14, 11, 0.94)),
                linear-gradient(90deg, rgba(121, 181, 214, 0.12), transparent 24%, transparent 76%, rgba(211, 100, 47, 0.12));
            border-bottom: 1px solid var(--arena-line-strong);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.22);
            backdrop-filter: blur(14px);
        }

        /* ── Panels ── */
        .arena-panel {
            border: 1px solid var(--arena-line);
            background:
                linear-gradient(180deg, rgba(55, 39, 28, 0.56), rgba(18, 12, 9, 0.88)),
                radial-gradient(circle at top right, rgba(255, 210, 135, 0.07), transparent 26%);
            box-shadow: var(--arena-shadow);
            border-radius: 1.75rem;
        }

        .arena-panel-strong {
            border: 1px solid var(--arena-line-strong);
            background:
                linear-gradient(180deg, rgba(63, 45, 31, 0.76), rgba(17, 12, 9, 0.94)),
                radial-gradient(circle at top left, rgba(255, 215, 134, 0.11), transparent 28%);
            box-shadow: var(--arena-shadow);
            border-radius: 2rem;
        }

        .arena-card {
            border: 1px solid rgba(214, 177, 92, 0.14);
            background: linear-gradient(180deg, rgba(28, 20, 15, 0.94), rgba(17, 12, 9, 0.92));
            border-radius: 1.35rem;
            transition: border-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }

        .arena-card-interactive:hover {
            border-color: rgba(214, 177, 92, 0.28);
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
        }

        /* ── Realm-themed card borders ── */
        .arena-card-ignis { border-color: rgba(211, 100, 47, 0.25); }
        .arena-card-ignis:hover { border-color: rgba(211, 100, 47, 0.45); box-shadow: 0 8px 30px rgba(211, 100, 47, 0.12); }
        .arena-card-alsius { border-color: rgba(121, 181, 214, 0.25); }
        .arena-card-alsius:hover { border-color: rgba(121, 181, 214, 0.45); box-shadow: 0 8px 30px rgba(121, 181, 214, 0.12); }
        .arena-card-syrtis { border-color: rgba(142, 179, 74, 0.25); }
        .arena-card-syrtis:hover { border-color: rgba(142, 179, 74, 0.45); box-shadow: 0 8px 30px rgba(142, 179, 74, 0.12); }

        /* ── Labels ── */
        .arena-kicker {
            text-transform: uppercase;
            letter-spacing: 0.34em;
            color: var(--arena-gold);
            font-size: 0.72rem;
        }

        .arena-text-muted {
            color: var(--arena-muted);
        }

        .arena-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(218, 177, 91, 0.18);
            background: rgba(16, 12, 9, 0.72);
            padding: 0.45rem 0.8rem;
            font-size: 0.72rem;
            letter-spacing: 0.08em;
            color: var(--arena-sand);
        }

        /* ── Buttons ── */
        .arena-btn,
        .arena-btn-secondary,
        .arena-btn-ghost,
        .arena-btn-warning,
        .arena-btn-danger,
        .arena-btn-danger-ghost,
        .arena-btn-safe {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border-radius: 999px;
            padding: 0.85rem 1.2rem;
            font-weight: 700;
            font-family: "Inter", sans-serif;
            font-size: 0.875rem;
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease, background 0.18s ease, opacity 0.18s ease;
            cursor: pointer;
            border: none;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .arena-btn:hover,
        .arena-btn-secondary:hover,
        .arena-btn-ghost:hover,
        .arena-btn-warning:hover,
        .arena-btn-danger:hover,
        .arena-btn-danger-ghost:hover,
        .arena-btn-safe:hover {
            transform: translateY(-1px);
        }

        .arena-btn:active, .arena-btn-secondary:active, .arena-btn-ghost:active,
        .arena-btn-warning:active, .arena-btn-danger:active, .arena-btn-safe:active {
            transform: translateY(0);
        }

        .arena-btn:disabled, .arena-btn-secondary:disabled, .arena-btn-ghost:disabled,
        .arena-btn-warning:disabled, .arena-btn-danger:disabled, .arena-btn-safe:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .arena-btn {
            color: #28190a;
            background: linear-gradient(180deg, #f3d888, #c99534 62%, #8a5f17);
            box-shadow: 0 12px 30px rgba(138, 95, 23, 0.3);
        }

        .arena-btn-secondary {
            color: var(--arena-text);
            background:
                linear-gradient(180deg, rgba(63, 112, 133, 0.92), rgba(27, 59, 74, 0.96)),
                linear-gradient(180deg, rgba(255,255,255,0.06), transparent);
            box-shadow: 0 12px 28px rgba(15, 42, 56, 0.3);
        }

        .arena-btn-safe {
            color: #09150c;
            background: linear-gradient(180deg, #8fe0a8, #2f8b57 62%, #185638);
            box-shadow: 0 12px 30px rgba(18, 70, 41, 0.24);
        }

        .arena-btn-warning {
            color: #28190a;
            background: linear-gradient(180deg, #f1c97c, #cb8731 62%, #8b5115);
            box-shadow: 0 12px 30px rgba(117, 70, 18, 0.28);
        }

        .arena-btn-danger {
            color: #fff1ed;
            background: linear-gradient(180deg, #d56363, #9f2f2f 62%, #5c1717);
            box-shadow: 0 12px 30px rgba(92, 23, 23, 0.28);
        }

        .arena-btn-ghost {
            color: var(--arena-sand);
            background: rgba(17, 12, 9, 0.7);
            border: 1px solid rgba(217, 177, 92, 0.18);
        }

        .arena-btn-danger-ghost {
            color: #ffb4b4;
            background: rgba(48, 14, 14, 0.76);
            border: 1px solid rgba(200, 82, 82, 0.28);
        }

        /* ── Loading state for buttons ── */
        .arena-btn-loading {
            pointer-events: none;
            opacity: 0.7;
        }
        .arena-btn-loading::after {
            content: "";
            display: inline-block;
            width: 1em;
            height: 1em;
            border: 2px solid currentColor;
            border-top-color: transparent;
            border-radius: 50%;
            animation: arenaSpinner 0.6s linear infinite;
            margin-left: 0.5em;
        }

        /* ── Nav links ── */
        .arena-nav-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            padding: 0.6rem 0.95rem;
            font-family: "Inter", sans-serif;
            font-size: 0.85rem;
            font-weight: 600;
            color: #e8d8bd;
            transition: background 0.18s ease, color 0.18s ease, transform 0.18s ease;
        }

        .arena-nav-link:hover {
            background: rgba(221, 180, 87, 0.12);
            color: #fff4de;
            transform: translateY(-1px);
        }

        .arena-nav-link-active {
            background: rgba(221, 180, 87, 0.16);
            color: var(--arena-gold-soft);
            box-shadow: inset 0 0 0 1px rgba(221, 180, 87, 0.18);
        }

        /* ── Badge dot for notifications ── */
        .arena-badge-dot {
            position: absolute;
            top: 0.35rem;
            right: 0.35rem;
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: #d3642f;
            box-shadow: 0 0 8px rgba(211, 100, 47, 0.6);
            animation: arenaPulse 2s ease-in-out infinite;
        }

        /* ── Forms ── */
        .arena-field,
        .arena-select,
        .arena-textarea {
            width: 100%;
            border-radius: 1.1rem;
            border: 1px solid rgba(217, 177, 92, 0.16);
            background: rgba(15, 10, 8, 0.88);
            color: var(--arena-text);
            padding: 0.9rem 1rem;
            font-family: "Inter", sans-serif;
            font-size: 0.9rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .arena-field:focus,
        .arena-select:focus,
        .arena-textarea:focus {
            outline: none;
            border-color: rgba(216, 177, 92, 0.4);
            box-shadow: 0 0 0 3px rgba(216, 177, 92, 0.1);
        }

        .arena-field::placeholder,
        .arena-textarea::placeholder {
            color: #8f816d;
        }

        /* ── Tables ── */
        .arena-table thead {
            color: #c8b38a;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 0.72rem;
            font-family: "Inter", sans-serif;
        }

        .arena-table tbody tr {
            border-top: 1px solid rgba(217, 177, 92, 0.08);
            transition: background 0.15s ease;
        }

        .arena-table tbody tr:hover {
            background: rgba(255, 215, 134, 0.04);
        }

        /* ── Scrollbar ── */
        .arena-scroll::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .arena-scroll::-webkit-scrollbar-thumb {
            background: rgba(217, 177, 92, 0.24);
            border-radius: 999px;
        }

        /* ── Pagination ── */
        .pagination [aria-current="page"] span,
        .pagination .active span {
            background: linear-gradient(180deg, #e7c975, #b9832a);
            color: #211408;
            border-color: transparent;
        }

        /* ── Realm text ── */
        .realm-ignis { color: var(--arena-fire); }
        .realm-syrtis { color: var(--arena-forest); }
        .realm-alsius { color: var(--arena-ice); }

        /* ── Status badges ── */
        .arena-status-pending { background: rgba(216, 177, 92, 0.15); color: #f4deb1; border: 1px solid rgba(216, 177, 92, 0.25); }
        .arena-status-active { background: rgba(46, 160, 67, 0.15); color: #8fe0a8; border: 1px solid rgba(46, 160, 67, 0.25); }
        .arena-status-completed { background: rgba(121, 181, 214, 0.15); color: #a8d4ea; border: 1px solid rgba(121, 181, 214, 0.25); }
        .arena-status-disputed { background: rgba(211, 100, 47, 0.15); color: #f4a261; border: 1px solid rgba(211, 100, 47, 0.25); }
        .arena-status-void { background: rgba(180, 163, 135, 0.1); color: #b4a387; border: 1px solid rgba(180, 163, 135, 0.2); }

        /* ── Mobile menu ── */
        .arena-mobile-menu {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: none;
        }
        .arena-mobile-menu.is-open { display: flex; }
        .arena-mobile-menu-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(6px);
        }
        .arena-mobile-menu-panel {
            position: relative;
            margin-left: auto;
            width: 100%;
            max-width: 320px;
            background:
                linear-gradient(180deg, rgba(38, 27, 20, 0.99), rgba(12, 8, 6, 0.99));
            border-left: 1px solid var(--arena-line-strong);
            overflow-y: auto;
            animation: arenaSlideIn 0.25s ease-out;
        }

        /* ── Toast styles ── */
        .arena-toast-success { border-color: rgba(46, 160, 67, 0.3); background: rgba(10, 35, 18, 0.92); }
        .arena-toast-success .arena-toast-message { color: #8fe0a8; }
        .arena-toast-warning { border-color: rgba(211, 162, 47, 0.3); background: rgba(40, 28, 10, 0.92); }
        .arena-toast-warning .arena-toast-message { color: #f4deb1; }
        .arena-toast-error { border-color: rgba(200, 82, 82, 0.3); background: rgba(40, 12, 12, 0.92); }
        .arena-toast-error .arena-toast-message { color: #ffb4b4; }
        .arena-toast-info { border-color: rgba(121, 181, 214, 0.3); background: rgba(12, 28, 40, 0.92); }
        .arena-toast-info .arena-toast-message { color: #a8d4ea; }

        /* ── Medal top positions ── */
        .arena-medal-1 { color: #ffd700; text-shadow: 0 0 8px rgba(255, 215, 0, 0.5); }
        .arena-medal-2 { color: #c0c0c0; text-shadow: 0 0 6px rgba(192, 192, 192, 0.4); }
        .arena-medal-3 { color: #cd7f32; text-shadow: 0 0 6px rgba(205, 127, 50, 0.4); }

        /* ── Animations ── */
        @keyframes arenaFadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes arenaFadeInUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes arenaSlideIn {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }
        @keyframes arenaModalIn {
            from { opacity: 0; transform: scale(0.96) translateY(8px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        @keyframes arenaToastIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes arenaSpinner {
            to { transform: rotate(360deg); }
        }
        @keyframes arenaPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.3); }
        }

        .arena-animate-in {
            animation: arenaFadeInUp 0.4s ease-out both;
        }
        .arena-stagger-1 { animation-delay: 0.05s; }
        .arena-stagger-2 { animation-delay: 0.1s; }
        .arena-stagger-3 { animation-delay: 0.15s; }
        .arena-stagger-4 { animation-delay: 0.2s; }
        .arena-stagger-5 { animation-delay: 0.25s; }
        .arena-stagger-6 { animation-delay: 0.3s; }

        /* ── Visor 3D de guerreros ───────────────────────────────────────────
           El canvas se posiciona en absoluto sobre el contenedor y el emblema
           del reino ocupa el mismo sitio: mientras no haya nada que renderizar,
           la caja ya se ve completa en vez de dejar un agujero. */
        .arena-champion {
            /* El reino se nota antes de mirar el nombre: tine el fondo del
               escenario, no solo el modelo. */
            --champion-tint: var(--arena-fire);
            position: relative;
            overflow: hidden;
            border-radius: 18px;
            border: 1px solid var(--arena-line);
            background:
                radial-gradient(70% 55% at 50% 78%, color-mix(in srgb, var(--champion-tint) 16%, transparent), transparent 70%),
                radial-gradient(78% 62% at 50% 30%, rgba(216, 177, 92, 0.07), transparent 68%),
                linear-gradient(180deg, rgba(28, 20, 16, 0.92), rgba(14, 10, 7, 0.96));
            transition: --champion-tint 0.3s ease;
        }
        .arena-champion[data-champion-realm="alsius"] { --champion-tint: var(--arena-ice); }
        .arena-champion[data-champion-realm="syrtis"] { --champion-tint: var(--arena-forest); }
        .arena-champion-canvas {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            display: block;
        }
        .arena-champion::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                /* Franja oscura al pie: el nombre y las etiquetas van encima de
                   una escena viva, y con un guerrero de piel clara el texto se
                   quedaba en 1.8:1 de contraste. Con esto no baja de 7:1 sea
                   cual sea el modelo. */
                linear-gradient(to top, rgba(6, 4, 3, 0.92) 0%, rgba(6, 4, 3, 0.72) 12%, transparent 34%),
                radial-gradient(72% 62% at 50% 42%, transparent 42%, rgba(6, 4, 3, 0.62) 100%);
        }
        .arena-champion-fallback {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 24px;
            text-align: center;
            color: var(--arena-muted);
            font-size: 12.5px;
        }
        .arena-champion-fallback p { margin: 0; max-width: 24ch; }
        /* El aviso solo cuando el visor ha dado el 3D por imposible. Sin JS o
           mientras carga, se ve el emblema y nada mas. */
        .arena-champion-fallback-note { display: none; }
        .arena-champion-fallback[data-champion-state="unsupported"] .arena-champion-fallback-note { display: block; }
        .arena-champion-fallback[data-champion-state="loading"] .arena-champion-glyph {
            animation: arenaPulse 1.8s ease-in-out infinite;
        }
        .arena-champion-glyph {
            font-size: 76px;
            line-height: 1;
            color: var(--arena-fire);
            opacity: 0.42;
        }
        .arena-champion[data-champion-realm="alsius"] .arena-champion-glyph { color: var(--arena-ice); }
        .arena-champion[data-champion-realm="syrtis"] .arena-champion-glyph { color: var(--arena-forest); }

        /* Contenido encima del escenario: nombre, cifras, etiquetas. */
        .arena-champion-overlay {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 2;
        }
        .arena-champion-overlay > * { pointer-events: auto; }

        .arena-champion-name {
            margin: 0;
            font-size: clamp(24px, 4vw, 38px);
            font-weight: 700;
            color: var(--arena-gold-soft);
            text-shadow: 0 3px 24px rgba(0, 0, 0, 0.85);
            text-wrap: balance;
        }
        .arena-champion-realm {
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--arena-fire);
        }
        .arena-champion[data-champion-realm="alsius"] .arena-champion-realm { color: var(--arena-ice); }
        .arena-champion[data-champion-realm="syrtis"] .arena-champion-realm { color: var(--arena-forest); }
        .arena-champion-status {
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(120, 53, 15, 0.45);
            color: #fcd9a8;
        }

        /* En movil las cifras no caben dentro del escenario sin taparle la
           cabeza al guerrero o pisar su nombre. Salen fuera, debajo, donde se
           leen enteras. Probado: dentro se solapaban 20 px con el rotulo. */
        @media (max-width: 640px) {
            /* Ojo con el selector: apuntar a `.arena-champion-overlay
               .arena-stats-row` tocaba tambien la fila de dentro del escenario
               y le devolvia el display que la utilidad `hidden` acababa de
               quitarle. Solo la de fuera. */
            .arena-champion-stats-outside .arena-stats-row {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 6px;
            }
            /* Que se ve y que no lo deciden las utilidades (hidden / sm:flex)
               en el propio marcado: sus reglas van despues de esta hoja y
               ganarian igualmente. Aqui solo queda la maquetacion. */
            .arena-champion-stats-outside { padding: 0 2px; }
            .arena-stat-pill { min-width: 0; padding: 6px 9px; text-align: center; }
            .arena-stat-pill b { font-size: 15px; }
        }

        .arena-stat-pill {
            min-width: 72px;
            padding: 7px 12px;
            border-radius: 11px;
            border: 1px solid var(--arena-line);
            background: rgba(9, 6, 4, 0.72);
        }
        .arena-stat-pill span {
            display: block;
            /* 10px es el minimo que se lee de verdad en un movil: por debajo la
               etiqueta se convierte en decoracion. */
            font-size: 10px;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--arena-muted);
        }
        .arena-stat-pill b {
            display: block;
            margin-top: 2px;
            font-size: 17px;
            font-weight: 600;
            color: var(--arena-gold-soft);
            font-variant-numeric: tabular-nums;
        }

        /* ── Rail de personajes ── */
        .arena-roster-slot {
            display: flex;
            align-items: center;
            gap: 11px;
            width: 100%;
            text-align: left;
            cursor: pointer;
            padding: 9px 11px;
            border-radius: 12px;
            border: 1px solid transparent;
            background: rgba(12, 8, 6, 0.45);
            color: var(--arena-text);
            font: inherit;
            transition: border-color 0.18s ease, background 0.18s ease, transform 0.18s ease;
        }
        .arena-roster-slot:hover { background: rgba(36, 26, 20, 0.75); transform: translateX(2px); }
        .arena-roster-slot:focus-visible { outline: 2px solid var(--arena-gold); outline-offset: 2px; }
        .arena-roster-slot[aria-pressed="true"] {
            border-color: var(--arena-line-strong);
            background: linear-gradient(90deg, rgba(63, 45, 31, 0.85), rgba(30, 21, 16, 0.7));
        }
        .arena-roster-crest {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            flex: none;
            display: grid;
            place-items: center;
            border: 1px solid var(--arena-line);
            background: rgba(8, 5, 4, 0.8);
        }
        .arena-roster-name {
            display: block;
            font-weight: 600;
            font-size: 14px;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .arena-roster-meta { display: block; font-size: 11.5px; color: var(--arena-muted); }
        .arena-roster-pl {
            margin-left: auto;
            font-size: 12.5px;
            color: var(--arena-gold);
            font-variant-numeric: tabular-nums;
        }
        /* El reporte, dentro del panel de combate. */
        .arena-report-inline {
            margin: 0 22px 16px;
            border: 1px solid var(--arena-line-strong);
            border-radius: 14px;
            background: rgba(10, 7, 5, 0.6);
            overflow: hidden;
        }
        .arena-report-inline > summary {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
            padding: 13px 16px;
            cursor: pointer;
            font-weight: 600;
            color: var(--arena-gold-soft);
            list-style: none;
        }
        .arena-report-inline > summary::-webkit-details-marker { display: none; }
        .arena-report-inline > summary::after {
            content: '';
            width: 8px;
            height: 8px;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg);
            transition: transform 0.2s ease;
            opacity: 0.7;
        }
        .arena-report-inline[open] > summary::after { transform: rotate(-135deg); }
        .arena-report-inline-hint { font-size: 12px; font-weight: 400; color: var(--arena-muted); }
        .arena-report-inline-body { display: flex; flex-direction: column; gap: 14px; padding: 4px 16px 16px; }
        .arena-report-reject { display: flex; flex-direction: column; gap: 12px; }
        .arena-report-reject[hidden] { display: none; }

        .arena-report-inline.is-answer { padding: 14px 16px; display: flex; flex-direction: column; gap: 12px; }
        .arena-report-inline-lead { margin: 0; font-size: 13.5px; color: var(--arena-sand); }
        .arena-report-inline-lead b { color: var(--arena-gold-soft); }

        @media (max-width: 720px) {
            .arena-report-inline { margin: 0 16px 14px; }
        }

        /* ── Ventanas ──────────────────────────────────────────────────────
           Se pintan al final del documento. Antes vivian donde estaban
           escritas, y dentro de la consola del lobby (que recorta) salian
           cortadas y ancladas en medio del escenario. */
        .arena-modal-panel {
            display: flex;
            flex-direction: column;
            max-height: min(82vh, 720px);
        }
        .arena-modal-body {
            margin-top: 16px;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding-right: 4px;
        }
        .arena-modal-body::-webkit-scrollbar { width: 8px; }
        .arena-modal-body::-webkit-scrollbar-thumb {
            background: var(--arena-line-strong);
            border-radius: 999px;
        }

        /* ── Consola del lobby ─────────────────────────────────────────────
           Elegir guerrero, verlo y entrar a la cola ocurren en el mismo panel.
           Antes el guerrero se elegia dos veces (el rail y un desplegable a
           media pagina) y sus acciones vivian en una tarjeta suelta entre
           medias, asi que la vista se leia como tres cosas distintas. */
        .arena-console {
            display: grid;
            grid-template-columns: 250px minmax(0, 1fr);
            gap: 0;
            border: 1px solid var(--arena-line-strong);
            border-radius: 22px;
            overflow: hidden;
            background: var(--arena-panel);
            box-shadow: 0 18px 44px rgba(0, 0, 0, 0.4);
        }

        .arena-console-rail {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 16px 14px;
            border-right: 1px solid var(--arena-line);
            background: rgba(10, 7, 5, 0.5);
        }
        .arena-console-rail-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
            padding: 0 4px;
        }
        .arena-console-count { font-size: 11.5px; color: var(--arena-muted); font-variant-numeric: tabular-nums; }
        .arena-console-note {
            margin: 0;
            border: 1px solid var(--arena-line);
            border-radius: 12px;
            background: rgba(0, 0, 0, 0.3);
            padding: 8px 10px;
            font-size: 11.5px;
            color: var(--arena-muted);
        }
        .arena-console-slots { display: flex; flex-direction: column; gap: 8px; }
        .arena-roster-slot.is-locked { opacity: 0.45; pointer-events: none; }

        .arena-console-main { display: flex; flex-direction: column; gap: 16px; padding: 16px; min-width: 0; }
        .arena-console-stage { display: flex; flex-direction: column; gap: 10px; }

        /* Las acciones del guerrero, sobre su propia figura. */
        .arena-console-tools { position: absolute; top: 14px; left: 14px; display: flex; gap: 8px; }
        .arena-console-tools-set { display: flex; gap: 8px; }
        .arena-console-tool {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 11px;
            border-radius: 10px;
            border: 1px solid var(--arena-line);
            background: rgba(8, 5, 4, 0.72);
            backdrop-filter: blur(6px);
            font-size: 12px;
            font-weight: 600;
            color: var(--arena-sand);
            cursor: pointer;
            transition: border-color 0.2s ease, color 0.2s ease, background 0.2s ease;
        }
        .arena-console-tool:hover { border-color: var(--arena-line-strong); color: var(--arena-gold-soft); }
        .arena-console-tool.is-danger:hover { border-color: rgba(200, 90, 80, 0.5); color: #e8927c; }
        .arena-console-tool.is-muted { cursor: default; color: var(--arena-muted); }

        /* El nombre ocupa solo su mitad: estirado de lado a lado tapaba el
           selector de modo y no dejaba pulsarlo. */
        .arena-console-ident {
            position: absolute;
            left: 20px;
            right: 20px;
            bottom: 18px;
            max-width: min(55%, 420px);
        }

        /* Las cifras van sobre la figura en pantalla ancha y debajo en movil,
           donde encima le taparian la cara. */
        .arena-console .arena-champion-stats-inside {
            position: absolute;
            top: 14px;
            right: 14px;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }
        .arena-console .arena-champion-stats-outside { display: none; }

        /* Dentro de la consola no hay tarjetas dentro de tarjetas: el panel
           ya es el marco, y repetir borde y fondo dibuja cajas anidadas. */
        .arena-console-main > .arena-panel,
        .arena-console-main > div > .arena-panel,
        .arena-console-main > div > details.arena-panel {
            border: 0;
            background: transparent;
            box-shadow: none;
            padding: 0;
        }
        .arena-console-main > div > details.arena-panel > summary { padding: 12px 0; }
        .arena-console-main > div > details.arena-panel > div { padding: 0 0 8px; }
        .arena-console-main > div { display: flex; flex-direction: column; gap: 16px; }

        /* ── Invitaciones flotantes ────────────────────────────────────── */
        .arena-invites {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 55;
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: min(340px, calc(100vw - 36px));
            pointer-events: none;
        }
        .arena-invite {
            pointer-events: auto;
            border: 1px solid var(--arena-line-strong);
            border-radius: 16px;
            background: linear-gradient(180deg, rgba(40, 28, 20, 0.97), rgba(14, 10, 8, 0.98));
            box-shadow: 0 20px 44px rgba(0, 0, 0, 0.5);
            padding: 13px 15px;
            animation: arenaInviteIn 0.3s cubic-bezier(0.2, 0.9, 0.3, 1.2) both;
        }
        @keyframes arenaInviteIn {
            from { opacity: 0; transform: translateY(14px) scale(0.97); }
            to { opacity: 1; transform: none; }
        }
        .arena-invite header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .arena-invite-kicker {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--arena-gold);
        }
        .arena-invite-hide {
            border: 0;
            background: transparent;
            color: var(--arena-muted);
            cursor: pointer;
            padding: 2px;
            border-radius: 6px;
        }
        .arena-invite-hide:hover { color: var(--arena-text); background: rgba(255, 255, 255, 0.08); }
        .arena-invite-body { margin: 8px 0 0; font-size: 13px; color: var(--arena-sand); }
        .arena-invite-body b { color: var(--arena-gold-soft); }
        .arena-invite-actions { display: flex; gap: 8px; margin-top: 11px; }
        .arena-invite-actions > * { flex: 1; }
        .arena-invite-actions button { width: 100%; justify-content: center; }

        @media (max-width: 560px) {
            .arena-invites { right: 12px; left: 12px; bottom: 12px; width: auto; }
        }

        /* Modalidad de arena, encima del escenario: manda sobre todo lo que
           viene debajo, asi que se elige antes de mirar al guerrero. */
        .arena-console-arenas {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px;
            border-radius: 13px;
            border: 1px solid var(--arena-line);
            background: rgba(10, 7, 5, 0.55);
            align-self: flex-start;
        }
        .arena-console-arenas-key {
            padding: 0 8px 0 6px;
            font-size: 10px;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--arena-muted);
        }
        .arena-console-arena {
            padding: 7px 16px;
            border-radius: 9px;
            border: 1px solid transparent;
            font-size: 13px;
            font-weight: 700;
            color: var(--arena-muted);
            transition: color 0.2s ease, background 0.2s ease, border-color 0.2s ease;
        }
        .arena-console-arena:hover { color: var(--arena-sand); }
        .arena-console-arena.is-active {
            border-color: var(--arena-line-strong);
            background: linear-gradient(180deg, rgba(63, 45, 31, 0.9), rgba(22, 15, 11, 0.95));
            color: var(--arena-gold-soft);
        }

        /* La party, dentro del escenario. */
        .arena-console-party {
            position: absolute;
            right: 16px;
            bottom: 16px;
            box-shadow: 0 14px 32px rgba(0, 0, 0, 0.45);
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: flex-end;
            padding: 10px 12px;
            border-radius: 14px;
            border: 1px solid var(--arena-line);
            background: rgba(8, 5, 4, 0.72);
            backdrop-filter: blur(8px);
        }
        .arena-console-party-key {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--arena-gold-soft);
        }
        .arena-console-party-slots { display: flex; gap: 8px; }
        .arena-console-party-slot {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            width: 62px;
        }
        .arena-console-party-slot b {
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 10.5px;
            font-weight: 600;
            color: var(--arena-sand);
        }
        .arena-console-party-slot.is-empty b { color: var(--arena-muted); font-weight: 400; }
        .arena-console-party-portrait {
            width: 58px;
            border-radius: 10px;
            border: 1px dashed var(--arena-line-strong);
            background: rgba(0, 0, 0, 0.45);
        }
        .arena-console-party-slot.is-in .arena-console-party-portrait {
            border-style: solid;
            border-color: rgba(95, 174, 106, 0.5);
        }
        .arena-console-party-portrait.is-empty {
            display: grid;
            place-items: center;
            height: 64px;
            font-size: 20px;
            color: var(--arena-muted);
        }

        /* ── Barra de acciones del guerrero ────────────────────────────────
           Pegada al pie del escenario, como el menu de accion de un juego: lo
           que se puede hacer con el guerrero que estas viendo vive en su propio
           panel, no en una tarjeta suelta mas abajo. */
        .arena-console-stage {
            border: 1px solid var(--arena-line-strong);
            border-radius: 18px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(30, 21, 15, 0.6), rgba(14, 10, 8, 0.75));
        }
        .arena-console-stage > .arena-champion { border-radius: 0; border: 0; }
        .arena-console-stage > .arena-champion::after { border-radius: 0; }

        .arena-console-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            padding: 12px;
            border-top: 1px solid var(--arena-line);
            background: rgba(8, 5, 4, 0.55);
        }
        .arena-console-actions > form { display: contents; }
        .arena-console-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            padding: 13px 16px;
            border-radius: 12px;
            border: 1px solid var(--arena-line-strong);
            background: linear-gradient(180deg, rgba(52, 38, 26, 0.9), rgba(20, 14, 10, 0.95));
            font-family: "Cinzel", Georgia, serif;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.01em;
            color: var(--arena-gold-soft);
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }
        .arena-console-action:hover {
            transform: translateY(-1px);
            border-color: var(--arena-gold);
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.4);
        }
        .arena-console-action:disabled { opacity: 0.45; cursor: not-allowed; transform: none; }
        .arena-console-action.is-primary {
            border-color: rgba(95, 174, 106, 0.55);
            background: linear-gradient(180deg, rgba(58, 122, 74, 0.95), rgba(26, 62, 36, 0.98));
            color: #eaf7ec;
        }
        .arena-console-action.is-primary:hover { border-color: #7cc98a; }
        .arena-console-action.is-danger {
            border-color: rgba(190, 84, 76, 0.5);
            background: linear-gradient(180deg, rgba(78, 32, 30, 0.9), rgba(30, 14, 13, 0.95));
            color: #f0bdb5;
        }
        /* Con un aviso ocupando la fila, el boton que queda tambien la ocupa:
           medio boton suelto a la izquierda se ve como un descuadre. */
        .arena-console-actions:has(.arena-console-actions-note) .arena-console-action {
            grid-column: 1 / -1;
        }
        .arena-console-actions-note {
            grid-column: 1 / -1;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 11px 14px;
            border-radius: 11px;
            border: 1px solid var(--arena-line);
            background: rgba(0, 0, 0, 0.28);
            font-size: 13px;
            color: var(--arena-sand);
        }

        /* El pie del panel: con quien entras y donde estan las reglas. Dentro
           del mismo marco que la figura y la barra, no como una tarjeta suelta
           debajo. */
        .arena-console-foot {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-top: 1px solid var(--arena-line);
            background: rgba(0, 0, 0, 0.22);
        }
        .arena-queue-locked {
            margin: 12px;
            border: 1px solid rgba(190, 84, 76, 0.4);
            border-radius: 12px;
            background: rgba(78, 32, 30, 0.3);
            padding: 10px 14px;
            font-size: 13px;
            color: #f0bdb5;
        }
        .arena-queue-hint { margin: 0; font-size: 12.5px; color: var(--arena-muted); }
        .arena-queue-with {
            margin: 0;
            font-size: 13.5px;
            color: var(--arena-muted);
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px;
        }
        .arena-queue-with b { font-size: 15px; color: var(--arena-gold-soft); }

        /* El lider de la invitacion, fijo: es el guerrero que ya elegiste. */
        .arena-invite-leader {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 14px;
            border: 1px solid var(--arena-line-strong);
            background: rgba(10, 7, 5, 0.6);
        }
        .arena-invite-leader b { display: block; font-size: 14px; color: var(--arena-gold-soft); }
        .arena-invite-leader > span > span { display: block; font-size: 12px; color: var(--arena-muted); }
        .arena-invite-leader-tag {
            margin-left: auto;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid var(--arena-line);
            font-size: 10px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--arena-gold);
        }

        /* Las opciones del editor, en dos columnas. */
        .arena-edit-choices { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }

        @media (max-width: 620px) {
            .arena-console-actions { grid-template-columns: 1fr; }
            .arena-edit-choices { grid-template-columns: 1fr; }
        }

        .arena-party-state {
            display: flex;
            flex-direction: column;
            gap: 12px;
            border: 1px solid var(--arena-line);
            border-radius: 14px;
            background: rgba(10, 7, 5, 0.5);
            padding: 14px 16px;
        }
        .arena-party-state-line {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 13.5px;
            color: var(--arena-sand);
        }
        .arena-party-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex: none;
            background: var(--arena-gold);
        }
        .arena-party-dot.is-ready { background: #7cc98a; }
        .arena-party-dot.is-live { background: var(--arena-ice); animation: arenaPulseDot 1.6s ease-in-out infinite; }
        @keyframes arenaPulseDot { 50% { opacity: 0.35; } }

        @media (max-width: 720px) {
            .arena-queue-buttons { grid-template-columns: 1fr; }
            /* La party se queda arriba a la derecha y encoge: puesta en el
               flujo se pintaba sobre el guerrero y lo partia por la mitad. */
            .arena-console-party {
                top: 56px;
                right: 10px;
                bottom: auto;
                padding: 7px 8px;
                gap: 5px;
            }
            .arena-console-party-slots { gap: 5px; }
            .arena-console-party-slot { width: 44px; }
            .arena-console-party-slot b { display: none; }
            .arena-console-party-portrait { width: 42px; }
            .arena-console-party-portrait.is-empty { height: 48px; font-size: 15px; }
        }

        .arena-queue-with {
            margin: 0;
            font-size: 13.5px;
            color: var(--arena-muted);
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px;
        }
        .arena-queue-with b { font-size: 15px; color: var(--arena-gold-soft); }

        @media (max-width: 900px) {
            .arena-console { grid-template-columns: 1fr; border-radius: 18px; }
            /* El escenario primero: en movil lo que se mira es el guerrero, y
               la escuadra se consulta al cambiar, no todo el rato. */
            .arena-console-rail {
                order: 2;
                border-right: 0;
                border-top: 1px solid var(--arena-line);
            }
            .arena-console-main { order: 1; padding: 14px; }
            .arena-console .arena-champion-stats-inside { display: none; }
            .arena-console .arena-champion-stats-outside { display: block; padding: 0 2px; }
            .arena-console-tools { top: 10px; left: 10px; }
            .arena-console-arenas { align-self: stretch; }
            .arena-console-tool span { display: none; }
            .arena-console-tool { padding: 8px; }
            .arena-console-ident { left: 14px; right: 14px; bottom: 14px; max-width: none; }
            /* En movil el selector no cabe al lado del nombre: va debajo, a lo
               ancho, y el nombre sube. */
            .arena-console-modes { left: 14px; right: 14px; bottom: 14px; }
            .arena-console-mode { flex: 1; justify-content: center; padding: 9px 8px; }
        }

        /* ── Paginacion ────────────────────────────────────────────────── */
        .arena-pagination {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }
        .arena-pagination-count { margin: 0; font-size: 12.5px; color: var(--arena-muted); }

        /* Area tactil en el pie: en movil sus enlaces median 15px de alto, que
           es la altura del texto, no la de algo que se pueda pulsar. */
        @media (max-width: 720px) {
            .arena-footer-nav a {
                display: inline-flex;
                align-items: center;
                min-height: 40px;
            }
        }
        .arena-pagination-pages { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .arena-pagination-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid var(--arena-line);
            background: rgba(10, 7, 5, 0.55);
            font-size: 13px;
            font-weight: 600;
            color: var(--arena-sand);
            transition: border-color 0.2s ease, color 0.2s ease, background 0.2s ease;
        }
        .arena-pagination-link:hover { border-color: var(--arena-line-strong); color: var(--arena-gold-soft); }
        .arena-pagination-link.is-current {
            border-color: var(--arena-line-strong);
            background: linear-gradient(180deg, rgba(63, 45, 31, 0.9), rgba(22, 15, 11, 0.95));
            color: var(--arena-gold-soft);
        }
        .arena-pagination-link.is-disabled { opacity: 0.4; }
        .arena-pagination-gap { padding: 0 4px; color: var(--arena-muted); }

        @media (max-width: 560px) {
            .arena-pagination { justify-content: center; }
            .arena-pagination-count { width: 100%; text-align: center; }
        }

        .arena-home-champion { display: block; }
        .arena-home-champion-stage {
            border-radius: 14px;
            border: 1px solid var(--arena-line);
            background: rgba(0, 0, 0, 0.35);
        }

        .arena-champion-podium { display: block; }
        .arena-champion-podium-stage {
            border-radius: 12px;
            border: 1px solid var(--arena-line);
            background: rgba(0, 0, 0, 0.35);
        }

        .arena-profile-portrait {
            width: 118px;
            flex: none;
            border-radius: 14px;
            border: 1px solid var(--arena-line);
            background: rgba(0, 0, 0, 0.4);
        }
        @media (max-width: 520px) { .arena-profile-portrait { width: 92px; height: 120px !important; } }

        .arena-roster-lock {
            display: block;
            margin-top: 3px;
            font-size: 9.5px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #e0888a;
            font-weight: 600;
        }
        .arena-roster-empty {
            justify-content: center;
            color: var(--arena-muted);
            border: 1px dashed var(--arena-line);
            background: none;
            font-size: 13px;
            text-decoration: none;
        }
        .arena-roster-empty:hover { color: var(--arena-gold-soft); border-color: var(--arena-line-strong); transform: none; }

        /* ── Aviso de cruce ─────────────────────────────────────────────────
           Se apodera de la pantalla a proposito: encontrar rival es el momento
           que decide la partida y tiene un reloj corriendo. Un panel mas en
           medio del scroll se pierde. */
        .arena-duel {
            position: fixed;
            inset: 0;
            z-index: 60;
            /* Flex con margin:auto en la tarjeta, no place-items:center: cuando
               el aviso es mas alto que la pantalla, centrar con grid deja la
               cabecera por encima del borde y no hay forma de subir hasta ella. */
            display: flex;
            padding: 20px;
            background: rgba(5, 3, 2, 0.86);
            backdrop-filter: blur(7px);
            overflow-y: auto;
            animation: arenaDuelIn 0.28s ease-out both;
        }
        @keyframes arenaDuelIn { from { opacity: 0 } to { opacity: 1 } }

        .arena-duel-card {
            width: min(720px, 100%);
            margin: auto;
            flex: none;
            border: 1px solid var(--arena-line-strong);
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(32, 22, 17, 0.98), rgba(14, 10, 7, 0.98));
            box-shadow: 0 40px 90px rgba(0, 0, 0, 0.7);
            animation: arenaDuelPop 0.32s cubic-bezier(0.2, 0.9, 0.3, 1.25) both;
        }
        @keyframes arenaDuelPop { from { transform: scale(0.94) } to { transform: scale(1) } }

        .arena-duel-head {
            padding: 20px 24px 16px;
            text-align: center;
            border-bottom: 1px solid var(--arena-line);
        }
        .arena-duel-title {
            margin: 6px 0 0;
            font-size: clamp(21px, 3.4vw, 27px);
            font-weight: 700;
            color: var(--arena-gold-soft);
            text-wrap: balance;
        }
        .arena-duel-sub {
            margin: 6px auto 0;
            max-width: 46ch;
            font-size: 13.5px;
            color: var(--arena-muted);
        }
        .arena-duel-ring { position: relative; width: 78px; height: 78px; margin: 14px auto 0; }
        .arena-duel-ring svg { transform: rotate(-90deg); display: block; }
        .arena-duel-ring circle { fill: none; stroke-width: 5; stroke-linecap: round; }
        .arena-duel-ring .bg { stroke: rgba(222, 185, 99, 0.14); }
        .arena-duel-ring .fg {
            stroke: var(--arena-gold);
            transition: stroke-dashoffset 0.95s linear, stroke 0.3s ease;
        }
        .arena-duel-ring.is-urgent .fg { stroke: #c4553f; }
        .arena-duel-ring b {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            font-size: 21px;
            font-weight: 700;
            color: var(--arena-gold-soft);
            font-variant-numeric: tabular-nums;
        }
        .arena-duel-count {
            margin: 8px 0 0;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--arena-muted);
        }

        .arena-duel-body { padding: 18px 24px 4px; display: flex; flex-direction: column; gap: 16px; }
        /* Retrato, no panoramica: a lo ancho el guerrero se quedaba en un
           munequito en medio de mucho aire. */
        .arena-duel-stage {
            border-radius: 14px;
            width: min(320px, 100%);
            margin: 0 auto;
        }

        .arena-duel-lineups {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 14px;
            align-items: center;
        }
        .arena-duel-team { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
        .arena-duel-team h3 {
            margin: 0 0 2px;
            font-size: 10.5px;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--team-color, var(--arena-gold));
        }
        .arena-duel-fighter {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 11px;
            background: rgba(10, 7, 5, 0.6);
            border: 1px solid var(--arena-line);
            transition: border-color 0.25s ease;
        }
        .arena-duel-fighter.is-ready { border-color: rgba(95, 174, 106, 0.45); }
        .arena-duel-avatar {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            flex: none;
            display: grid;
            place-items: center;
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid var(--arena-line);
        }
        .arena-duel-fighter b {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--arena-text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .arena-duel-fighter > span > span { display: block; font-size: 11px; color: var(--arena-muted); }
        .arena-duel-ready {
            margin-left: auto;
            font-size: 10.5px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--arena-muted);
            white-space: nowrap;
        }
        .arena-duel-fighter.is-ready .arena-duel-ready { color: #7cc98a; }
        .arena-duel-versus { font-size: 19px; font-weight: 700; color: var(--arena-muted); letter-spacing: 0.08em; }

        .arena-duel-zone {
            display: flex;
            gap: 14px;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-radius: 13px;
            border: 1px solid var(--arena-line);
            background: rgba(10, 7, 5, 0.6);
        }
        .arena-duel-zone-key {
            margin: 0;
            font-size: 10px;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--arena-muted);
        }
        .arena-duel-zone-value { margin: 2px 0 0; font-size: 16px; font-weight: 600; color: var(--arena-gold-soft); }

        .arena-duel-foot { display: flex; gap: 10px; flex-wrap: wrap; padding: 16px 24px 22px; }
        .arena-duel-foot > * { min-width: 140px; }

        @media (max-width: 620px) {
            .arena-duel { padding: 12px; }
            /* Menos escenario y mas alineaciones: en una pantalla de movil lo
               que hay que decidir es si aceptas, no admirar el retrato. */
            .arena-duel-stage { height: 170px !important; width: min(220px, 100%); }
            .arena-duel-lineups { grid-template-columns: 1fr; }
            .arena-duel-versus { text-align: center; }
            .arena-duel-body { padding: 14px 16px 4px; }
            .arena-duel-head { padding: 16px 16px 14px; }
            .arena-duel-foot { padding: 14px 16px 18px; }
        }

        /* ── Panel de combate en el sitio ──────────────────────────────────
           El cruce y el combate vivian en una capa a pantalla completa. Un
           combate no es una interrupcion de lo que estabas haciendo: ES lo que
           estabas haciendo, asi que ocupa su sitio en la columna, con el mismo
           lenguaje (anillo, alineaciones, figuras) en las tres fases: aceptar,
           pelear y reportar. */
        .arena-duel-panel {
            border: 1px solid var(--arena-line-strong);
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(32, 22, 17, 0.96), rgba(14, 10, 7, 0.97));
            box-shadow: 0 18px 44px rgba(0, 0, 0, 0.42);
            animation: arenaDuelPop 0.32s cubic-bezier(0.2, 0.9, 0.3, 1.25) both;
            /* La franja de la izquierda dice de un vistazo en que fase estas
               sin tener que leer el titulo. */
            border-left: 4px solid var(--arena-gold);
        }
        .arena-duel-panel.is-waiting { border-left-color: var(--arena-ice); }
        .arena-duel-panel.is-live { border-left-color: var(--arena-emerald, #5faE6a); }

        .arena-duel-panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            padding: 20px 22px 16px;
            border-bottom: 1px solid var(--arena-line);
        }
        .arena-duel-panel-title {
            margin: 6px 0 0;
            font-size: clamp(20px, 2.6vw, 26px);
            font-weight: 700;
            color: var(--arena-gold-soft);
            text-wrap: balance;
        }
        .arena-duel-panel-sub {
            margin: 6px 0 0;
            max-width: 52ch;
            font-size: 13.5px;
            color: var(--arena-muted);
        }

        .arena-duel-clock { position: relative; width: 78px; flex: none; text-align: center; }
        .arena-duel-clock svg { transform: rotate(-90deg); display: block; margin: 0 auto; }
        .arena-duel-clock circle { fill: none; stroke-width: 5; stroke-linecap: round; }
        .arena-duel-clock .bg { stroke: rgba(222, 185, 99, 0.14); }
        .arena-duel-clock .fg {
            stroke: var(--arena-gold);
            transition: stroke-dashoffset 0.95s linear, stroke 0.3s ease;
        }
        .arena-duel-clock.is-urgent .fg { stroke: #c4553f; }
        .arena-duel-clock b {
            position: absolute;
            inset: 0 0 auto;
            height: 70px;
            display: grid;
            place-items: center;
            font-size: 20px;
            font-weight: 700;
            color: var(--arena-gold-soft);
            font-variant-numeric: tabular-nums;
        }
        .arena-duel-clock.is-urgent b { color: #e8927c; }
        /* El reloj de la cola cuenta hacia arriba: no hay anillo que llenar,
           asi que el numero se coloca solo. */
        .arena-duel-clock.is-elapsed b { position: static; height: auto; display: block; }
        .arena-duel-clock-note {
            display: block;
            margin-top: 6px;
            font-size: 10.5px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--arena-muted);
        }

        .arena-duel-panel .arena-duel-lineups { padding: 18px 22px; }
        .arena-duel-portrait {
            width: 56px;
            flex: none;
            border-radius: 10px;
            border: 1px solid var(--arena-line);
            background: rgba(0, 0, 0, 0.45);
        }
        .arena-duel-portrait::after { display: none; }

        .arena-duel-panel-foot {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 0 22px 20px;
        }
        .arena-duel-panel-foot .arena-duel-zone { flex: 1 1 240px; justify-content: flex-start; gap: 10px; }
        .arena-duel-panel-foot .arena-duel-zone-value { margin: 0; font-size: 14px; }
        .arena-duel-zone-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid rgba(222, 185, 99, 0.3);
            background: rgba(222, 185, 99, 0.1);
            border-radius: 9px;
            padding: 5px 10px;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .arena-duel-zone-btn:hover { background: rgba(222, 185, 99, 0.2); }
        .arena-duel-actions { display: flex; flex-wrap: wrap; gap: 10px; }

        .arena-queue-body {
            display: grid;
            grid-template-columns: minmax(0, 240px) minmax(0, 1fr);
            gap: 18px;
            padding: 18px 22px;
            align-items: start;
        }
        .arena-queue-portrait { border-radius: 14px; }
        .arena-queue-pulse {
            border: 1px solid var(--arena-line);
            border-radius: 14px;
            background: rgba(10, 7, 5, 0.6);
            padding: 14px 16px;
        }
        .arena-queue-realm {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border: 1px solid var(--arena-line);
            border-radius: 11px;
            padding: 8px 12px;
        }

        @media (max-width: 720px) {
            .arena-duel-panel-head { flex-direction: column-reverse; align-items: flex-start; gap: 12px; padding: 16px 16px 14px; }
            .arena-duel-clock { display: flex; align-items: center; gap: 10px; width: auto; text-align: left; }
            .arena-duel-clock b { position: absolute; left: 0; width: 70px; }
            .arena-duel-clock.is-elapsed b { position: static; width: auto; }
            .arena-duel-clock-note { margin: 0; }
            .arena-duel-panel .arena-duel-lineups { grid-template-columns: 1fr; padding: 16px; }
            .arena-duel-versus { text-align: center; }
            .arena-duel-panel-foot { padding: 0 16px 16px; }
            .arena-duel-actions { width: 100%; }
            .arena-duel-actions > *, .arena-duel-actions form, .arena-duel-actions button, .arena-duel-actions a { width: 100%; justify-content: center; }
            .arena-queue-body { grid-template-columns: 1fr; padding: 16px; }
        }

        /* ── Asistente de creacion ── */
        .arena-wizard-step {
            border: 1px solid var(--arena-line);
            border-radius: 16px;
            padding: 18px;
            background: rgba(16, 11, 8, 0.6);
        }
        .arena-wizard-num {
            display: inline-grid;
            place-items: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            font-size: 12px;
            font-weight: 700;
            background: rgba(216, 177, 92, 0.16);
            color: var(--arena-gold-soft);
            border: 1px solid var(--arena-line-strong);
        }
        .arena-wizard-step[data-done="1"] .arena-wizard-num {
            background: var(--arena-gold);
            color: #20160e;
        }
        .arena-choice {
            position: relative;
            display: block;
            cursor: pointer;
        }
        /* El radio cubre toda la tarjeta en vez de encogerse a cero: sigue
           siendo un radio de verdad (teclado, lectores, autofill) y ademas se
           puede pulsar en cualquier punto de la tarjeta. */
        .arena-choice input {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            opacity: 0;
            cursor: pointer;
            z-index: 1;
        }
        .arena-choice-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 14px 10px;
            border-radius: 14px;
            border: 1px solid var(--arena-line);
            background: rgba(9, 6, 4, 0.55);
            text-align: center;
            transition: border-color 0.18s ease, background 0.18s ease, transform 0.18s ease;
        }
        .arena-choice:hover .arena-choice-body { transform: translateY(-2px); background: rgba(36, 26, 20, 0.7); }
        .arena-choice input:focus-visible + .arena-choice-body { outline: 2px solid var(--arena-gold); outline-offset: 2px; }
        .arena-choice input:checked + .arena-choice-body {
            border-color: var(--choice-color, var(--arena-gold));
            background: rgba(63, 45, 31, 0.6);
            box-shadow: 0 0 0 1px var(--choice-color, var(--arena-gold)) inset;
        }
        .arena-choice-title { font-size: 13.5px; font-weight: 600; color: var(--arena-text); }
        /* Solo hace falta cuando se ven las razas de los tres reinos a la vez,
           que es lo que pasa sin JavaScript. Con scripts, el paso 1 ya ha
           filtrado y esta linea sobra. */
        .arena-choice-realm {
            font-size: 10.5px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--arena-gold);
            opacity: 0.75;
        }
        /* Opciones con marca: una fila de icono y texto se lee mucho mas rapido
           que doce titulos sueltos, que es lo que eran las razas. */
        .arena-choice-body-row {
            flex-direction: row;
            align-items: flex-start;
            gap: 11px;
            text-align: left;
        }
        .arena-choice-mark {
            display: grid;
            place-items: center;
            width: 34px;
            height: 34px;
            flex: none;
            border-radius: 10px;
            border: 1px solid var(--arena-line);
            background: rgba(0, 0, 0, 0.35);
            color: var(--choice-color, var(--arena-gold));
        }
        .arena-choice input:checked + .arena-choice-body .arena-choice-mark {
            border-color: var(--choice-color, var(--arena-gold));
            background: rgba(216, 177, 92, 0.14);
        }
        .arena-choice-body-row .arena-choice-title,
        .arena-choice-body-row .arena-choice-note,
        .arena-choice-body-row .arena-choice-realm { display: block; }

        /* La vista previa se queda pegada mientras se rellenan los pasos. El
           desfase es la barra de navegacion, que tambien esta pegada: sin
           contarla el guerrero quedaba medio tapado por la cabecera y solo se
           le veia de cintura para abajo. */
        .arena-preview-dock {
            position: sticky;
            top: calc(var(--arena-navbar-height, 72px) + 10px);
            --preview-height: clamp(360px, 62vh, 640px);
        }
        @media (max-width: 1023px) {
            .arena-preview-dock {
                /* Alto suficiente para que el guerrero entre entero: con 180px
                   se le cortaba la cabeza. */
                --preview-height: 240px;
                padding-bottom: 8px;
                margin-bottom: 4px;
                background: var(--arena-night);
            }
            .arena-preview-dock .arena-champion-name { font-size: 18px; }
            .arena-preview-dock .arena-champion-caption { display: none; }
            /* El rotulo, mas pegado al borde: en 240px cada pixel cuenta. */
            .arena-preview-dock .arena-champion-overlay > div { inset-inline: 14px !important; bottom: 10px !important; }
        }

        .arena-choice-hint {
            margin: 0 0 2px;
            font-size: 12.5px;
            color: var(--arena-muted);
        }
        /* Con JavaScript el filtrado deja un solo reino a la vista, y repetir
           su nombre en cada tarjeta es ruido. */
        .arena-wizard-step[data-races-filtered="1"] .arena-choice-realm,
        .arena-wizard-step[data-races-filtered="1"] .arena-choice-hint { display: none; }
        .arena-choice-note { font-size: 11.5px; color: var(--arena-muted); line-height: 1.4; }
    </style>
    {{-- Utilidades de Tailwind, compiladas y servidas desde este dominio.

         Antes esto era <script src="cdn.tailwindcss.com">, que compila Tailwind
         en el navegador de cada visitante: si ese dominio fallaba, la pagina se
         quedaba sin una sola clase.

         Va DESPUES del <style> de arriba a proposito. El CDN inyectaba sus
         reglas al final de la cabecera, asi que las utilidades ganaban a las
         clases arena-* cuando compartian propiedad. El marcado depende de ello:
         "arena-field px-4 py-2" o "arena-nav-link block w-full" solo tienen
         sentido si el px-4 y el block ganan. Moverlo antes del <style> cambia
         el relleno de campos y botones y rompe el menu movil. Comprobado
         comparando los estilos calculados de las 1.438 etiquetas de las dos
         paginas con cada orden. --}}
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ @filemtime(public_path('css/site.css')) ?: '1' }}">
    @stack('arena-map-styles')
</head>
@php
    $arenaAdminSessionActive = session('arena_admin.authenticated') === true;
    $arenaAdminDisplayName = session('arena_admin.display_name', 'admin');
@endphp
<body class="arena-shell min-h-screen">
    {{-- ── NAVBAR ── --}}
    <nav class="arena-navbar sticky top-0 z-40" data-arena-navbar>
        <div class="mx-auto max-w-7xl px-4 py-3">
            <div class="flex items-center justify-between gap-4">
                <a href="{{ route('home') }}" class="shrink-0">
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
                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-400" data-arena-alert-indicator></span>
                            <span data-arena-alert-label>Alertas activas</span>
                        </button>
                        <span class="arena-chip hidden border-amber-500/30 bg-amber-950/30 text-amber-100 lg:inline-flex">🛡️ {{ $arenaAdminDisplayName }}</span>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit" class="arena-btn-ghost px-3 py-1.5 text-xs">Cerrar Admin</button>
                        </form>
                    @else
                        {{-- Contexto de Jugador (Juego) --}}
                        <a href="{{ route('ladder.index') }}" class="arena-nav-link {{ request()->routeIs('ladder.*') ? 'arena-nav-link-active' : '' }}">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M5 3a1 1 0 000 2c5.523 0 10 4.477 10 10a1 1 0 102 0C17 8.373 11.627 3 5 3z"/><path d="M4 9a1 1 0 011-1 7 7 0 017 7 1 1 0 11-2 0 5 5 0 00-5-5 1 1 0 01-1-1zM3 15a2 2 0 114 0 2 2 0 01-4 0z"/></svg>
                            Ladder
                        </a>
                        @auth
                            <a href="{{ route('lobby') }}" class="arena-nav-link relative {{ request()->routeIs('lobby') ? 'arena-nav-link-active' : '' }}">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                                Lobby
                            </a>
                            <a href="{{ route('matches.index') }}" class="arena-nav-link {{ request()->routeIs('matches.*') ? 'arena-nav-link-active' : '' }}">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/></svg>
                                Matches
                            </a>
                            <button type="button" class="arena-btn-ghost px-3 py-1.5 text-xs" data-arena-alert-toggle>
                                <span class="inline-block h-2 w-2 rounded-full bg-emerald-400" data-arena-alert-indicator></span>
                                <span data-arena-alert-label>Alertas activas</span>
                            </button>
                            <span class="arena-chip hidden lg:inline-flex">{{ auth()->user()->discord_username }}</span>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="arena-btn-ghost px-3 py-1.5 text-xs">Salir</button>
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

                {{-- Mobile hamburger --}}
                <button type="button" class="lg:hidden rounded-xl border border-[color:var(--arena-line)] bg-[rgba(15,10,8,0.7)] p-2.5 text-[color:var(--arena-sand)] transition hover:bg-white/10" id="arenaMenuOpen" aria-label="Abrir menú">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
                </button>
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
                        <span class="inline-block h-2 w-2 rounded-full bg-emerald-400" data-arena-alert-indicator></span>
                        <span data-arena-alert-label>Alertas activas</span>
                    </button>
                    
                    <div class="my-4 border-t border-[color:var(--arena-line)]"></div>
                    <a href="{{ route('home') }}" class="block text-center text-sm font-semibold text-[color:var(--arena-sand)] hover:text-white">Cambiar al juego</a>
                    <form method="POST" action="{{ route('admin.logout') }}" class="mt-3">
                        @csrf
                        <button type="submit" class="arena-btn-danger-ghost w-full">Cerrar Sesión Admin</button>
                    </form>
                @else
                    {{-- Mobile User Context --}}
                    <a href="{{ route('ladder.index') }}" class="arena-nav-link block w-full {{ request()->routeIs('ladder.*') ? 'arena-nav-link-active' : '' }}">Ladder</a>
                    @auth
                        <a href="{{ route('lobby') }}" class="arena-nav-link block w-full {{ request()->routeIs('lobby') ? 'arena-nav-link-active' : '' }}">Lobby</a>
                        <a href="{{ route('matches.index') }}" class="arena-nav-link block w-full {{ request()->routeIs('matches.*') ? 'arena-nav-link-active' : '' }}">Matches</a>
                        <button type="button" class="arena-btn-ghost mt-3 w-full justify-center" data-arena-alert-toggle>
                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-400" data-arena-alert-indicator></span>
                            <span data-arena-alert-label>Alertas activas</span>
                        </button>
                        
                        <div class="my-4 border-t border-[color:var(--arena-line)]"></div>
                        <div class="arena-chip mb-3 w-full justify-center">👤 {{ auth()->user()->discord_username }}</div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="arena-btn-ghost w-full">Salir</button>
                        </form>
                    @else
                        <a href="{{ route('auth.discord') }}" class="arena-btn-secondary mt-3 w-full">Entrar con Discord</a>
                    @endauth

                    @if($arenaAdminSessionActive)
                        <div class="my-4 border-t border-[color:var(--arena-line)]"></div>
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
    <main class="flex-1 pb-16 pt-4">
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

    {{-- ── GLOBAL SCRIPTS ── --}}
    <script>
        /* ── Mobile menu ── */
        (function() {
            const menu = document.getElementById('arenaMobileMenu');
            const openBtn = document.getElementById('arenaMenuOpen');
            const closeBtn = document.getElementById('arenaMenuClose');
            const backdrop = document.getElementById('arenaMenuBackdrop');
            if (!menu || !openBtn) return;

            const toggle = (open) => {
                menu.classList.toggle('is-open', open);
                document.body.style.overflow = open ? 'hidden' : '';
            };

            openBtn.addEventListener('click', () => toggle(true));
            closeBtn?.addEventListener('click', () => toggle(false));
            backdrop?.addEventListener('click', () => toggle(false));
        })();

        /* ── Modal system ── */
        window.arenaModal = (function () {
            /* Una ventana abierta se cierra con Escape, devuelve el foco a donde
               estaba y no deja que el tabulador se escape por detras. Sin esto
               quien navega con teclado se quedaba dando vueltas por la pagina de
               abajo sin poder cerrar lo que tenia delante. */
            var abierta = null;
            var focoPrevio = null;

            function enfocables(el) {
                return [...el.querySelectorAll('a[href],button:not([disabled]),select,textarea,input:not([type=hidden]):not([disabled]),[tabindex]:not([tabindex="-1"])')]
                    .filter(function (n) { return n.offsetParent !== null; });
            }

            document.addEventListener('keydown', function (event) {
                if (!abierta) { return; }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    api.close(abierta.id);
                    return;
                }

                if (event.key !== 'Tab') { return; }

                var items = enfocables(abierta);
                if (!items.length) { return; }

                var primero = items[0];
                var ultimo = items[items.length - 1];

                if (event.shiftKey && document.activeElement === primero) {
                    event.preventDefault();
                    ultimo.focus();
                } else if (!event.shiftKey && document.activeElement === ultimo) {
                    event.preventDefault();
                    primero.focus();
                }
            });

            var api = {
                open(id) {
                    const el = document.getElementById(id);
                    if (!el) { return; }

                    focoPrevio = document.activeElement;
                    el.style.display = 'flex';
                    abierta = el;
                    document.body.style.overflow = 'hidden';

                    var items = enfocables(el);
                    if (items.length) {
                        try { items[0].focus({ preventScroll: true }); } catch (e) { items[0].focus(); }
                    }
                },
                close(id) {
                    const el = document.getElementById(id);
                    if (!el) { return; }

                    el.style.display = 'none';
                    document.body.style.overflow = '';

                    if (abierta === el) { abierta = null; }
                    if (focoPrevio && focoPrevio.focus) {
                        try { focoPrevio.focus({ preventScroll: true }); } catch (e) { focoPrevio.focus(); }
                        focoPrevio = null;
                    }
                }
            };

            return api;
        })();
        document.addEventListener('click', (e) => {
            const closer = e.target.closest('[data-modal-close]');
            if (closer) {
                arenaModal.close(closer.dataset.modalClose);
            }
            const opener = e.target.closest('[data-modal-open]');
            if (opener) {
                arenaModal.open(opener.dataset.modalOpen);
            }
        });

        /* ── Toast system ── */
        window.arenaToast = function(message, type = 'info', duration = 5000) {
            const container = document.getElementById('arenaToastContainer');
            const template = document.getElementById('arenaToastTemplate');
            if (!container || !template) return;

            const toast = template.content.cloneNode(true).firstElementChild;
            toast.classList.add('arena-toast-' + type);
            toast.querySelector('.arena-toast-message').textContent = message;

            const icons = {
                success: '<svg class="h-5 w-5 text-emerald-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>',
                warning: '<svg class="h-5 w-5 text-amber-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>',
                error: '<svg class="h-5 w-5 text-rose-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>',
                info: '<svg class="h-5 w-5 text-sky-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>',
            };
            toast.querySelector('.arena-toast-icon').innerHTML = icons[type] || icons.info;
            container.appendChild(toast);

            if (duration > 0) {
                setTimeout(() => {
                    toast.style.animation = 'arenaFadeIn 0.2s ease-out reverse forwards';
                    setTimeout(() => toast.remove(), 200);
                }, duration);
            }
        };

        /* ── Button loading states ── */
        /* ── Browser sound alerts ── */
        (function() {
            const enabledKey = 'arena:sound-alerts:enabled';
            const dedupeKey = 'arena:sound-alerts:last-event';
            const dedupeWindowMs = 4000;
            const unlockEvents = ['pointerdown', 'touchstart', 'keydown'];
            const safeGet = (key) => {
                try {
                    return localStorage.getItem(key);
                } catch (_) {
                    return null;
                }
            };
            const safeSet = (key, value) => {
                try {
                    localStorage.setItem(key, value);
                    return true;
                } catch (_) {
                    return false;
                }
            };

            let enabled = safeGet(enabledKey) !== '0';
            let audioContext = null;
            let unlocked = false;

            // Dos notas como mucho, separadas, y la ultima con cola larga para
            // que el aviso se apague solo. Los avisos importantes suben de tono
            // (match encontrado, caceria); los informativos se quedan planos.
            const patterns = {
                match_found: {
                    tones: [
                        { freq: 880, duration: 0.55, delay: 0.00, gain: 0.055 },
                        { freq: 1318, duration: 1.60, delay: 0.16, gain: 0.055 },
                    ],
                    vibrate: [80, 40, 120],
                },
                party_invite: {
                    tones: [
                        { freq: 784, duration: 0.45, delay: 0.00, gain: 0.045 },
                        { freq: 1046, duration: 1.30, delay: 0.14, gain: 0.045 },
                    ],
                    vibrate: [60, 30, 80],
                },
                party_ready: {
                    tones: [
                        { freq: 659, duration: 0.40, delay: 0.00, gain: 0.045 },
                        { freq: 988, duration: 1.25, delay: 0.14, gain: 0.045 },
                    ],
                    vibrate: [70],
                },
                hunt_start: {
                    tones: [
                        { freq: 587, duration: 0.40, delay: 0.00, gain: 0.05 },
                        { freq: 880, duration: 0.40, delay: 0.15, gain: 0.05 },
                        { freq: 1174, duration: 1.70, delay: 0.30, gain: 0.05 },
                    ],
                    vibrate: [120, 50, 120],
                },
                report_submitted: {
                    tones: [
                        { freq: 698, duration: 0.35, delay: 0.00, gain: 0.04 },
                        { freq: 880, duration: 1.10, delay: 0.13, gain: 0.04 },
                    ],
                    vibrate: [50, 25, 50],
                },
                report_confirmed: {
                    tones: [
                        { freq: 659, duration: 0.35, delay: 0.00, gain: 0.045 },
                        { freq: 988, duration: 1.45, delay: 0.14, gain: 0.045 },
                    ],
                    vibrate: [90, 40, 90],
                },
                generic: {
                    tones: [
                        { freq: 880, duration: 1.10, delay: 0.00, gain: 0.04 },
                    ],
                    vibrate: [60],
                },
            };

            const alertButtons = () => Array.from(document.querySelectorAll('[data-arena-alert-toggle]'));

            const getAudioContext = () => {
                if (audioContext) {
                    return audioContext;
                }

                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextClass) {
                    return null;
                }

                audioContext = new AudioContextClass();
                unlocked = audioContext.state === 'running';

                return audioContext;
            };

            const updateButtons = () => {
                alertButtons().forEach((button) => {
                    const label = button.querySelector('[data-arena-alert-label]');
                    const indicator = button.querySelector('[data-arena-alert-indicator]');
                    // Un solo interruptor: encendido o silenciado. El desbloqueo
                    // del audio (unlocked) es un requisito del navegador, no una
                    // decision del usuario, asi que no se muestra como un tercer
                    // estado: se resuelve solo con el primer gesto en la pagina.
                    const activeLabel = enabled ? 'Alertas activas' : 'Alertas silenciadas';

                    button.classList.toggle('border-emerald-500/30', enabled);
                    button.classList.toggle('text-emerald-200', enabled);
                    button.classList.toggle('border-rose-500/30', !enabled);
                    button.classList.toggle('text-rose-200', !enabled);
                    button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
                    button.setAttribute('title', enabled ? 'Silenciar las alertas sonoras' : 'Activar las alertas sonoras');

                    if (label) {
                        label.textContent = activeLabel;
                    }

                    if (indicator) {
                        indicator.classList.toggle('bg-emerald-400', enabled);
                        indicator.classList.toggle('bg-rose-400', !enabled);
                    }
                });
            };

            const unlock = async () => {
                const context = getAudioContext();
                if (!context) {
                    return false;
                }

                try {
                    if (context.state !== 'running') {
                        await context.resume();
                    }
                } catch (_) {
                    unlocked = false;
                    updateButtons();
                    return false;
                }

                unlocked = context.state === 'running';
                updateButtons();
                return unlocked;
            };

            const installUnlockListeners = () => {
                const tryUnlock = async () => {
                    const didUnlock = await unlock();
                    if (didUnlock) {
                        unlockEvents.forEach((eventName) => {
                            document.removeEventListener(eventName, tryUnlock, true);
                        });
                    }
                };

                unlockEvents.forEach((eventName) => {
                    document.addEventListener(eventName, tryUnlock, true);
                });
            };

            const shouldEmit = (eventKey) => {
                try {
                    const raw = safeGet(dedupeKey);
                    if (raw) {
                        const parsed = JSON.parse(raw);
                        if (parsed.key === eventKey && (Date.now() - parsed.timestamp) < dedupeWindowMs) {
                            return false;
                        }
                    }

                    safeSet(dedupeKey, JSON.stringify({
                        key: eventKey,
                        timestamp: Date.now(),
                    }));
                } catch (_) {
                    return true;
                }

                return true;
            };

            const playPattern = (type) => {
                const context = getAudioContext();
                if (!context || !unlocked) {
                    // Silencio en vez de un aviso: el desbloqueo del audio es
                    // cosa del navegador y se resuelve solo en cuanto la persona
                    // toca cualquier parte de la pagina. Pedirselo explicitamente
                    // convertia un detalle tecnico en una tarea para el usuario.
                    unlock();

                    return false;
                }

                const pattern = patterns[type] || patterns.generic;
                const baseTime = context.currentTime + 0.02;

                pattern.tones.forEach((tone) => {
                    // Campana, no pitido. El ataque es casi instantaneo (6 ms) y
                    // luego la cola cae exponencialmente durante todo el resto:
                    // eso es lo que hace que suene y se vaya apagando solo, en
                    // vez de cortarse de golpe como antes, cuando la nota entera
                    // duraba poco mas de una decima de segundo.
                    //
                    // Cada nota lleva ademas un armonico agudo mas corto y mas
                    // bajo de volumen. Es lo que le da el timbre metalico: una
                    // sinusoide sola suena a tono de prueba.
                    const startAt = baseTime + (tone.delay ?? 0);
                    const duration = tone.duration ?? 1.2;
                    const peak = tone.gain ?? 0.05;
                    const attack = 0.006;

                    const voices = [
                        { freq: tone.freq ?? 660, gain: peak, decay: duration, type: tone.type ?? 'sine' },
                        { freq: (tone.freq ?? 660) * (tone.partial ?? 2.76), gain: peak * 0.22, decay: duration * 0.45, type: 'sine' },
                    ];

                    voices.forEach((voice) => {
                        const oscillator = context.createOscillator();
                        const gainNode = context.createGain();
                        const endAt = startAt + voice.decay;

                        oscillator.type = voice.type;
                        oscillator.frequency.setValueAtTime(voice.freq, startAt);

                        gainNode.gain.setValueAtTime(0.0001, startAt);
                        gainNode.gain.exponentialRampToValueAtTime(voice.gain, startAt + attack);
                        gainNode.gain.exponentialRampToValueAtTime(0.0001, endAt);

                        oscillator.connect(gainNode);
                        gainNode.connect(context.destination);
                        oscillator.start(startAt);
                        oscillator.stop(endAt + 0.03);
                    });
                });

                if (navigator.vibrate && pattern.vibrate) {
                    navigator.vibrate(pattern.vibrate);
                }

                return true;
            };

            const setEnabled = async (value, options = {}) => {
                enabled = !!value;
                safeSet(enabledKey, enabled ? '1' : '0');

                if (enabled) {
                    // Se intenta desbloquear en segundo plano. Si el navegador
                    // aun no lo permite, los listeners de gesto lo resuelven en
                    // la siguiente interaccion sin molestar al usuario.
                    await unlock();
                    if (!options.silent) {
                        arenaToast('Alertas sonoras activadas.', 'success', 3500);
                    }
                } else if (!options.silent) {
                    arenaToast('Alertas sonoras silenciadas.', 'info', 3000);
                }

                updateButtons();
            };

            const notify = (type, message, options = {}) => {
                const eventKey = options.key || type;
                if (!enabled || !shouldEmit(eventKey)) {
                    return false;
                }

                playPattern(type);

                if (message) {
                    arenaToast(message, options.toastType || 'info', options.duration || 5500);
                }

                return true;
            };

            document.addEventListener('click', (event) => {
                const toggle = event.target.closest('[data-arena-alert-toggle]');
                if (!toggle) {
                    return;
                }

                event.preventDefault();

                // El clic siempre hace lo mismo: encender o silenciar. Antes,
                // cuando el audio no estaba desbloqueado, el primer clic lo
                // desbloqueaba en vez de conmutar, y el boton parecia moverse
                // entre tres estados sin logica aparente.
                setEnabled(!enabled);
            });

            window.ArenaSoundAlerts = {
                notify,
                unlock,
                setEnabled,
                toggle: () => setEnabled(!enabled),
                isEnabled: () => enabled,
                isUnlocked: () => unlocked,
            };

            installUnlockListeners();
            updateButtons();
        })();

        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (form.tagName !== 'FORM') return;
            const btn = form.querySelector('button[type="submit"]');
            if (btn && !btn.classList.contains('arena-btn-loading')) {
                btn.classList.add('arena-btn-loading');
                btn.disabled = true;
            }
        });

        /* ── Reset loading state on back/forward navigation ── */
        window.addEventListener('pageshow', (e) => {
            if (e.persisted) {
                document.querySelectorAll('.arena-btn-loading').forEach(btn => {
                    btn.classList.remove('arena-btn-loading');
                    btn.disabled = false;
                });
            }
        });

        /* ── Tab system ── */
        document.addEventListener('click', (e) => {
            const tab = e.target.closest('[data-arena-tab]');
            if (!tab) return;

            const group = tab.dataset.arenaTabGroup;
            const key = tab.dataset.arenaTab;

            document.querySelectorAll(`[data-arena-tab][data-arena-tab-group="${group}"]`).forEach(t => {
                const isActive = t.dataset.arenaTab === key;
                t.setAttribute('aria-selected', isActive ? 'true' : 'false');
                t.className = t.className
                    .replace(/bg-\[linear-gradient[^\]]*\]/g, '')
                    .replace(/text-\[color:var\(--arena-gold-soft\)\]/g, '')
                    .replace(/shadow-\[[^\]]*\]/g, '')
                    .replace(/text-\[color:var\(--arena-muted\)\]/g, '')
                    .replace(/hover:text-\[color:var\(--arena-sand\)\]/g, '')
                    .replace(/hover:bg-white\/\[0\.04\]/g, '')
                    .replace(/\s+/g, ' ').trim();

                if (isActive) {
                    t.classList.add('bg-[linear-gradient(180deg,rgba(63,45,31,0.85),rgba(22,15,11,0.95))]', 'text-[color:var(--arena-gold-soft)]', 'shadow-[0_4px_16px_rgba(0,0,0,0.2),inset_0_1px_0_rgba(255,215,134,0.12)]');
                } else {
                    t.classList.add('text-[color:var(--arena-muted)]', 'hover:text-[color:var(--arena-sand)]', 'hover:bg-white/[0.04]');
                }
            });

            document.querySelectorAll(`[data-arena-tab-panel][data-arena-tab-group="${group}"]`).forEach(panel => {
                const isActive = panel.dataset.arenaTabPanel === key;
                panel.classList.toggle('hidden', !isActive);
                if (isActive) {
                    panel.style.animation = 'arenaFadeIn 0.25s ease-out';
                }
            });
        });

        /* ── Convert flash messages to toasts ── */
        document.addEventListener('DOMContentLoaded', function() {
            @if(session('success'))
                arenaToast(@json(session('success')), 'success');
            @endif
            @if(session('warning'))
                arenaToast(@json(session('warning')), 'warning');
            @endif
            @if(session('error'))
                arenaToast(@json(session('error')), 'error');
            @endif
        });
    </script>

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

    @stack('champion-boot')

    @stack('arena-map-scripts')
    <script>
        /* Relojes de la arena.
           Un solo motor para los tres: el plazo para aceptar el cruce, el plazo
           para pelear y reportar, y el tiempo que llevas en cola. El servidor ya
           pinta el valor correcto; esto solo lo mantiene vivo, asi que sin
           JavaScript la pagina sigue diciendo algo cierto. */
        (function () {
            var clocks = document.querySelectorAll('[data-arena-clock]');
            if (!clocks.length) { return; }

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

                clocks.forEach(function (clock) {
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
                        window.setTimeout(function () { window.location.reload(); }, 1500);
                    }
                });
            }

            tick();
            window.setInterval(tick, 1000);
        })();
    </script>
    @stack('scripts')
</body>
</html>
