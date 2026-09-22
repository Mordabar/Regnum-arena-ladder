<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Regnum Arena Ladder')</title>
    <meta name="description" content="Regnum Arena Ladder — Conquest PvP por reino y subclase, ranking automático PL/MMR, duelos 1v1 y arenas 2v2 y 3v3.">
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

        /* Los secundarios de la portada, algo mas apretados.
           Con cuatro botones -entrar, Salon, ladder y la guia- el ultimo
           saltaba a una segunda linea, y eso son sesenta pixeles de alto
           en la pantalla que mas cuesta. La columna del texto mide unos
           600px: con iconos los cuatro sumaban 653 y no habia manera. Los
           iconos de los tres secundarios eran decoracion -el rotulo ya
           dice a donde va-, asi que se van y los cuatro entran en 571. El
           principal conserva el suyo, que es el que hay que mirar. */
        @media (min-width: 1024px) {
            .arena-hero-acciones .arena-btn-ghost {
                padding-left: 0.85rem;
                padding-right: 0.85rem;
                font-size: 0.82rem;
            }
            .arena-hero-acciones .arena-btn-ghost svg { display: none; }
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

        /* El boton que abre el explorador de archivos.
           Sin esto lo pinta el sistema operativo: un rectangulo gris claro con
           "Choose Files" en ingles, en medio de un panel oscuro. Es de las
           pocas cosas de la pantalla que delatan que esto es una pagina web y
           no un juego, y ademas sale justo en el paso que cierra la partida. */
        .arena-field[type="file"] { padding: 0.55rem 0.6rem; cursor: pointer; }
        .arena-field[type="file"]::file-selector-button {
            margin-right: 0.7rem;
            padding: 0.5rem 0.95rem;
            border-radius: 0.8rem;
            border: 1px solid rgba(217, 177, 92, 0.3);
            background: linear-gradient(180deg, rgba(58, 42, 22, 0.92), rgba(30, 21, 12, 0.94));
            color: var(--arena-text);
            font-family: "Inter", sans-serif;
            font-size: 0.82rem;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }
        .arena-field[type="file"]::file-selector-button:hover {
            border-color: rgba(216, 177, 92, 0.55);
            background: linear-gradient(180deg, rgba(74, 54, 28, 0.95), rgba(40, 28, 16, 0.96));
        }
        /* Lo mismo para Safari de iOS anterior a 14.1 y Chrome anterior al 89,
           que solo entienden el nombre viejo. No se pueden juntar con una coma:
           un selector que el navegador no reconoce tumba la regla entera, asi
           que van duplicados a proposito. */
        .arena-field[type="file"]::-webkit-file-upload-button {
            margin-right: 0.7rem;
            padding: 0.5rem 0.95rem;
            border-radius: 0.8rem;
            border: 1px solid rgba(217, 177, 92, 0.3);
            background: linear-gradient(180deg, rgba(58, 42, 22, 0.92), rgba(30, 21, 12, 0.94));
            color: var(--arena-text);
            font-family: "Inter", sans-serif;
            font-size: 0.82rem;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }
        .arena-field[type="file"]::-webkit-file-upload-button:hover {
            border-color: rgba(216, 177, 92, 0.55);
            background: linear-gradient(180deg, rgba(74, 54, 28, 0.95), rgba(40, 28, 16, 0.96));
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

        /* El escenario llega hasta el borde del panel. Con un margen alrededor
           y un marco propio se veian dos bordes concentricos para una sola
           cosa, y en pantalla estrecha quedaba todo pegado al borde exterior.
           Lo que viene despues del escenario si respira. */
        .arena-console-main { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
        .arena-console-main > *:not(.arena-console-stage) { margin: 0 16px; }
        .arena-console-main > *:last-child:not(.arena-console-stage) { margin-bottom: 16px; }
        /* Y el de arriba tambien. Sin esto, el primer panel -el de la cola, el
           del combate- quedaba con hueco a los lados y abajo pero pegado al
           borde superior del cuadro que lo contiene: una caja dentro de otra
           con solo tres margenes se lee como un fallo de maqueta. El escenario
           3D se queda fuera de la regla a proposito: ese va a sangre. */
        .arena-console-main > *:first-child:not(.arena-console-stage) { margin-top: 16px; }
        .arena-console-stage { display: flex; flex-direction: column; }

        /* Las acciones del guerrero, sobre su propia figura. */
        .arena-console-tools { position: absolute; top: 62px; left: 14px; display: flex; gap: 8px; }
        /* Sin selector de modalidad no hay nada encima: las acciones suben. */
        .arena-champion-overlay:not(:has(.arena-console-arenas)) .arena-console-tools { top: 14px; }
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
        /* Van en el centro de la pantalla, no en una esquina. Abajo a la
           derecha se comian los botones de entrar a cola e invitar aliado,
           que es justo lo que el jugador tiene debajo cuando le llega una;
           y en movil competian con los avisos de color, que si viven en la
           esquina. Aqui no tapan nada que haga falta y se ven siempre, ya
           este la pagina donde este. */
        .arena-invites {
            position: fixed;
            inset: 0;
            z-index: 55;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 18px;
            pointer-events: none;
        }
        .arena-invites[hidden] { display: none; }
        /* Velo, solo mientras haya alguna sin plegar: separa la invitacion de
           la pagina sin llegar a bloquearla, porque plegar sigue siendo una
           salida valida y detras hay cosas que mirar. */
        .arena-invites:has(.arena-invite:not(.is-folded))::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(8, 5, 4, 0.55);
            backdrop-filter: blur(2px);
            pointer-events: none;
        }
        .arena-invite {
            position: relative;
            pointer-events: auto;
            width: min(380px, 100%);
            border: 1px solid var(--arena-line-strong);
            border-radius: 16px;
            background: linear-gradient(180deg, rgba(40, 28, 20, 0.97), rgba(14, 10, 8, 0.98));
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.62);
            padding: 13px 15px;
            animation: arenaInviteIn 0.3s cubic-bezier(0.2, 0.9, 0.3, 1.2) both;
        }
        @keyframes arenaInviteIn {
            from { opacity: 0; transform: scale(0.94); }
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
        .arena-invite-folded {
            display: block;
            width: 100%;
            margin-top: 8px;
            padding: 7px 10px;
            border: 1px dashed var(--arena-line-strong);
            border-radius: 10px;
            background: transparent;
            color: var(--arena-sand);
            font-size: 12px;
            text-align: left;
            cursor: pointer;
        }
        .arena-invite-folded:hover { background: rgba(255, 255, 255, 0.05); color: var(--arena-text); }
        /* Con todas plegadas la cosa deja de ser urgente: el aviso baja a la
           esquina de abajo a la izquierda, lejos de los avisos de color que
           viven a la derecha, y devuelve el centro de la pantalla. */
        .arena-invites:not(:has(.arena-invite:not(.is-folded))) {
            align-items: flex-start;
            justify-content: flex-end;
        }
        .arena-invite.is-folded { padding: 10px 13px; width: min(300px, 100%); }
        .arena-invite.is-folded .arena-invite-folded { margin-top: 6px; }

        @media (max-width: 560px) {
            .arena-invites { padding: 12px; }
        }

        /* Modalidad de arena, encima del escenario: manda sobre todo lo que
           viene debajo, asi que se elige antes de mirar al guerrero. */
        .arena-console-arenas {
            position: absolute;
            top: 14px;
            left: 14px;
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px;
            border-radius: 12px;
            border: 1px solid var(--arena-line);
            background: rgba(8, 5, 4, 0.72);
            backdrop-filter: blur(6px);
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

        /* Menos texto en movil: el lobby tenia demasiado a la vista y lo
           primero que sobra es lo que ya se sabe. */
        @media (max-width: 720px) {
            .arena-hide-mobile { display: none; }
            .arena-console-title { font-size: 24px; }
            .arena-console-lede { font-size: 13.5px; }
        }

        /* ── Escuadra como cajon en movil ──────────────────────────────────
           En una pantalla estrecha la lista entera colgaba del final de la
           pagina, visible todo el rato incluso durante un combate, y era la
           parte mas larga de un lobby que ya tenia demasiado a la vista. */
        .arena-roster-close { display: none; }
        .arena-roster-scrim { display: none; }
        .arena-console-foot-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .arena-roster-open { display: none; }

        @media (max-width: 900px) {
            .arena-roster-open { display: inline-flex; }
            .arena-console-rail {
                position: fixed;
                inset: auto 0 0 0;
                z-index: 60;
                max-height: 76vh;
                overflow-y: auto;
                border: 1px solid var(--arena-line-strong);
                border-bottom: 0;
                border-radius: 20px 20px 0 0;
                background: linear-gradient(180deg, rgba(32, 22, 17, 0.99), rgba(14, 10, 8, 0.99));
                box-shadow: 0 -20px 50px rgba(0, 0, 0, 0.6);
                transform: translateY(102%);
                transition: transform 0.28s cubic-bezier(0.2, 0.9, 0.3, 1.05);
                padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px));
            }
            .arena-console-rail.is-open { transform: none; }
            .arena-roster-close {
                display: inline-grid;
                place-items: center;
                margin-left: auto;
                width: 32px;
                height: 32px;
                border-radius: 9px;
                border: 1px solid var(--arena-line);
                background: rgba(0, 0, 0, 0.35);
                color: var(--arena-muted);
                cursor: pointer;
            }
            .arena-roster-scrim {
                display: block;
                position: fixed;
                inset: 0;
                z-index: 59;
                background: rgba(5, 3, 2, 0.72);
                backdrop-filter: blur(3px);
            }
            .arena-roster-scrim[hidden] { display: none; }
            body.arena-roster-locked { overflow: hidden; }
        }

        /* ── Barra de acciones del guerrero ────────────────────────────────
           Pegada al pie del escenario, como el menu de accion de un juego: lo
           que se puede hacer con el guerrero que estas viendo vive en su propio
           panel, no en una tarjeta suelta mas abajo. */
        .arena-console-stage {
            overflow: hidden;
            background: linear-gradient(180deg, rgba(30, 21, 15, 0.6), rgba(14, 10, 8, 0.75));
        }
        .arena-console-stage > .arena-champion { border-radius: 0; border: 0; }
        .arena-console-stage > .arena-champion::after { border-radius: 0; }

        /* El rol del conjurador, justo encima de las acciones y dentro del
           mismo marco: es una condicion para entrar, no un ajuste suelto. */
        .arena-console-role {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 12px;
            border-top: 1px solid var(--arena-line);
            background: rgba(8, 5, 4, 0.45);
        }
        .arena-console-role label {
            font-size: 11px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--arena-gold);
            white-space: nowrap;
        }
        .arena-console-role .arena-select { flex: 1; min-width: 0; }
        .arena-console-role + .arena-console-actions { border-top: 0; }

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
            .arena-console-main { order: 1; }
            .arena-console-main > *:not(.arena-console-stage) { margin: 0 14px; }
            .arena-console-main > *:last-child:not(.arena-console-stage) { margin-bottom: 14px; }
            .arena-console .arena-champion-stats-inside { display: none; }
            .arena-console .arena-champion-stats-outside { display: block; padding: 0 16px; }
            /* La modalidad manda la esquina de arriba y las acciones se
               apartan a la derecha: en estrecho no caben una sobre otra sin
               taparse. */
            .arena-console-arenas { top: 10px; left: 10px; }
            .arena-console-tools { top: 10px; left: auto; right: 10px; }
            .arena-champion-overlay:not(:has(.arena-console-arenas)) .arena-console-tools { top: 10px; }
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
        /* "2 vs 2" es una sola cosa: partido en dos lineas se lee como dos. */
        .arena-duel-zone-value { margin: 2px 0 0; font-size: 16px; font-weight: 600; color: var(--arena-gold-soft); white-space: nowrap; }
        .arena-duel-zone-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: normal;
            text-align: left;
            padding: 7px 13px;
            border-radius: 11px;
            border: 1px solid rgba(217, 177, 92, 0.34);
            background: rgba(38, 26, 15, 0.72);
            cursor: pointer;
            transition: border-color .2s ease, background .2s ease, transform .15s ease;
        }
        .arena-duel-zone-btn:hover { border-color: rgba(222, 185, 99, 0.7); background: rgba(56, 39, 22, 0.85); }
        .arena-duel-zone-btn:active { transform: scale(.98); }
        .arena-duel-zone-cta {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--arena-muted);
            white-space: nowrap;
        }

        /* La zona, arriba y con peso. Con el cruce recien dado es el unico dato
           que hace falta ya mismo, y estaba en el pie del panel. */
        /* El bloque del punto de encuentro.

           Va separado de la cabecera -antes el filo del `border-bottom` le
           caia justo encima y parecia que se salia del recuadro- y se apila:
           rotulo, boton e instruccion. En linea solo se leia "ZONA - nombre -
           1 vs 1", que no dice nada que no se supiera ya. */
        .arena-duel-zone.is-destacada {
            display: block;
            margin: clamp(14px, 2vw, 20px) clamp(14px, 2.4vw, 26px) 0;
            padding: 13px 16px 14px;
            border-color: rgba(217, 177, 92, 0.24);
            background: linear-gradient(180deg, rgba(46, 33, 19, 0.72), rgba(16, 11, 7, 0.78));
        }
        .arena-duel-zone-cabecera {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
        }
        /* El modo, como chapa y no como texto suelto en dorado: al lado del
           nombre de la zona se leia como si fuera parte de ella. */
        .arena-duel-zone-modo {
            flex: none;
            padding: 2px 9px;
            border-radius: 99px;
            border: 1px solid rgba(217, 177, 92, 0.28);
            background: rgba(217, 177, 92, 0.1);
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: 0.04em;
            color: var(--arena-gold-soft);
            white-space: nowrap;
        }
        .arena-duel-zone.is-destacada .arena-duel-zone-btn {
            margin: 8px 0 0;
            font-size: 17px;
        }
        .arena-duel-zone-pin { width: 17px; height: 17px; flex: none; }
        .arena-duel-zone-nombre { min-width: 0; }
        .arena-duel-zone-pista {
            margin: 8px 0 0;
            font-size: 12px;
            line-height: 1.45;
            color: var(--arena-muted);
        }

        @media (max-width: 720px) {
            .arena-duel-zone.is-destacada {
                margin: 12px 12px 0;
                padding: 11px 13px 12px;
            }
            /* El boton se lleva el ancho entero: a media fila, "Zona 7 -
               Central Ruins" se partia en dos o tres lineas. */
            .arena-duel-zone.is-destacada .arena-duel-zone-btn {
                display: flex;
                width: 100%;
                font-size: 15px;
            }
            /* Y "Ver el mapa" pasa a "Mapa": esas dos palabras de mas eran las
               que acababan de partir el nombre. */
            .arena-duel-zone.is-destacada .arena-duel-zone-cta {
                margin-left: auto;
                padding-left: 8px;
                font-size: 0;
            }
            .arena-duel-zone.is-destacada .arena-duel-zone-cta::after {
                content: 'Mapa';
                font-size: 10px;
            }
        }

        /* El brillo del boton de zona.

           Se enciende al darse el cruce y al confirmarse, y se apaga en cuanto
           se abre el mapa una vez: el aviso deja de serlo si sigue puesto
           despues de hacerle caso. Quien no lo pulsa lo sigue viendo latir. */
        .arena-duel-zone-btn.is-llamando {
            border-color: rgba(255, 214, 128, 0.8);
            animation: arenaZonaLatido 1.9s ease-in-out infinite;
        }
        @keyframes arenaZonaLatido {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(255, 201, 106, 0.42), 0 0 14px rgba(255, 201, 106, 0.18);
                background: rgba(38, 26, 15, 0.72);
            }
            50% {
                box-shadow: 0 0 0 7px rgba(255, 201, 106, 0), 0 0 26px rgba(255, 201, 106, 0.38);
                background: rgba(70, 49, 24, 0.9);
            }
        }
        @media (prefers-reduced-motion: reduce) {
            /* Sin latido, pero el boton tiene que seguir destacando: quien pide
               menos animacion no esta pidiendo menos informacion. */
            .arena-duel-zone-btn.is-llamando {
                animation: none;
                background: rgba(70, 49, 24, 0.9);
                box-shadow: 0 0 18px rgba(255, 201, 106, 0.32);
            }
        }

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

        /* ── Podio de la temporada ─────────────────────────────────────────
           Tres cajones en perspectiva con el campeon de cada puesto encima.
           El orden es el de siempre -segundo, primero, tercero- porque un podio
           leido de izquierda a derecha obliga a buscar quien gano, y asi se ve
           solo: el de en medio es el mas alto. */
        .arena-podium {
            border: 1px solid var(--arena-line-strong);
            border-radius: 22px;
            background:
                radial-gradient(90% 60% at 50% 0%, rgba(216, 177, 92, 0.13), transparent 70%),
                linear-gradient(180deg, rgba(34, 24, 17, 0.95), rgba(13, 9, 7, 0.97));
            box-shadow: 0 20px 48px rgba(0, 0, 0, 0.38);
            overflow: hidden;
        }

        .arena-podium-head { padding: clamp(14px, 2.2vw, 20px) clamp(18px, 3vw, 28px) 0; text-align: center; }

        /* El trofeo. Va centrado y arriba del todo, como en la pantalla de
           resultados de cualquier juego: es lo que anuncia que aqui hay algo
           que ganar antes de leer una sola palabra. */
        .arena-podium-cup {
            display: block;
            width: clamp(32px, 4.4vw, 42px);
            height: auto;
            margin: 0 auto 2px;
            filter: drop-shadow(0 4px 14px rgba(216, 177, 92, 0.45));
        }

        .arena-podium-title {
            margin: 4px 0 0;
            font-family: 'Cinzel', serif;
            font-size: clamp(21px, 3.4vw, 31px);
            font-weight: 700;
            color: var(--arena-gold-soft);
            line-height: 1.15;
            /* El cristal y el texto en una linea, centrados juntos. */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: clamp(8px, 1.4vw, 14px);
            flex-wrap: wrap;
        }
        /* La gema de verdad al lado de la cifra. Decora y, sobre todo, dice de
           que es el premio sin tener que leerlo. */
        .arena-podium-gema {
            width: clamp(40px, 6.5vw, 62px);
            height: auto;
            flex: none;
            filter: drop-shadow(0 3px 12px rgba(62, 214, 150, 0.4));
        }
        .arena-podium-total {
            /* El numero es el gancho: va mas grande que el resto del titulo. */
            font-size: 1.32em;
            color: var(--arena-gold);
            text-shadow: 0 0 26px rgba(216, 177, 92, 0.38);
        }
        .arena-podium-note {
            margin: 8px auto 0;
            max-width: 46ch;
            font-size: 13px;
            color: var(--arena-muted);
        }

        .arena-podium-stage {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            align-items: end;
            gap: clamp(6px, 1.4vw, 16px);
            /* Sin hueco abajo: los cajones llegan al borde del panel, como un
               podio llega al suelo. Flotando sobre un margen parecian tres
               tarjetas puestas en escalera. */
            padding: clamp(14px, 2.4vw, 24px) clamp(12px, 2.4vw, 26px) 0;
        }

        .arena-podium-slot { display: flex; flex-direction: column; align-items: center; min-width: 0; }

        .arena-podium-figure {
            width: 100%;
            position: relative;
            height: clamp(104px, 12vw, 140px);
        }
        .arena-podium-slot.is-1 .arena-podium-figure { height: clamp(132px, 15.5vw, 180px); }

        .arena-podium-champion { display: block; height: 100%; }
        .arena-podium-viewer { width: 100%; }
        .arena-podium-viewer::after { display: none; }

        .arena-podium-empty {
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            gap: 2px;
            padding-bottom: 8px;
            color: var(--arena-muted);
        }
        .arena-podium-empty span {
            font-family: 'Cinzel', serif;
            font-size: clamp(28px, 4vw, 44px);
            opacity: .34;
            line-height: 1;
        }
        .arena-podium-empty small { font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; }

        /* El cajon.

           Los tres apoyan en la MISMA linea de suelo y lo que cambia es su
           altura, como en un podio de verdad. Elevarlos con hueco debajo -que
           fue el primer intento- los dejaba flotando y, peor, subia tambien el
           nombre, asi que los tres nombres quedaban a tres alturas distintas. */
        .arena-podium-block {
            position: relative;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            gap: 2px;
            padding: 10px 6px 12px;
            border: 1px solid rgba(216, 177, 92, 0.3);
            border-bottom: 0;
            border-radius: 10px 10px 0 0;
            background: linear-gradient(180deg, rgba(74, 54, 32, 0.92), rgba(26, 18, 11, 0.96));
            box-shadow: inset 0 1px 0 rgba(255, 220, 150, 0.16);
            min-height: 120px;
        }
        .arena-podium-block::before {
            content: '';
            position: absolute;
            inset: 0 0 auto;
            height: 4px;
            border-radius: 10px 10px 0 0;
            background: linear-gradient(90deg, transparent, rgba(255, 222, 156, 0.55), transparent);
        }
        .arena-podium-slot.is-1 .arena-podium-block {
            min-height: 132px;
            padding-top: 12px;
            border-color: rgba(230, 195, 106, 0.6);
            background: linear-gradient(180deg, rgba(110, 80, 40, 0.95), rgba(36, 25, 13, 0.97));
        }
        .arena-podium-slot.is-2 .arena-podium-block { min-height: 114px; }
        .arena-podium-slot.is-3 .arena-podium-block { min-height: 98px; }

        .arena-podium-medal { font-size: clamp(19px, 2.4vw, 26px); line-height: 1; }
        .arena-podium-prize { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; }
        .arena-podium-prize-line { display: inline-flex; align-items: baseline; gap: 4px; }
        .arena-podium-prize b {
            font-size: clamp(18px, 2.3vw, 25px);
            font-weight: 700;
            color: #fff;
            line-height: 1;
        }
        .arena-podium-prize small { font-size: 12.5px; color: rgba(255, 233, 190, 0.92); letter-spacing: .01em; }
        /* La palabra entera en pantalla grande; en movil la cambia la inicial. */
        .arena-podium-unit-short { display: none; font-weight: 700; }
        .arena-podium-gema-mini {
            width: 15px;
            height: auto;
            flex: none;
            align-self: center;
            filter: drop-shadow(0 1px 4px rgba(62, 214, 150, 0.55));
        }

        .arena-podium-who {
            display: block;
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid rgba(216, 177, 92, 0.2);
            width: 100%;
            text-align: center;
            min-width: 0;
        }
        .arena-podium-who b {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--arena-text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .arena-podium-who > span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            /* 11px al 62% de opacidad era practicamente invisible sobre el
               cajon oscuro: es el dato que dice quien va ganando. */
            font-size: 13px;
            font-weight: 600;
            color: rgba(240, 226, 199, 0.92);
        }

        /* La figura del podio en vivo del Salon de la Fama. */
        .arena-hof-figura {
            display: block;
            height: clamp(150px, 18vw, 200px);
            margin-top: 12px;
            border-radius: 12px;
            overflow: hidden;
            background: rgba(0, 0, 0, 0.28);
        }
        .arena-hof-visor { width: 100%; }
        .arena-hof-visor::after { display: none; }

        /* ── Podio compacto ─────────────────────────────────────────────────
           El mismo podio sin figuras 3D ni escenario, para paginas donde los
           premios acompañan en vez de protagonizar: en el ladder, el podio
           completo se comia seiscientos pixeles y empujaba la tabla -que es a
           lo que se viene- fuera de la pantalla. */
        .arena-podium.is-compacto .arena-podium-cup { width: 30px; margin-bottom: 2px; }
        .arena-podium.is-compacto .arena-podium-head { padding: 14px 16px 0; }
        .arena-podium.is-compacto .arena-podium-title { font-size: clamp(17px, 2.2vw, 22px); gap: 8px; }
        .arena-podium.is-compacto .arena-podium-gema { width: 34px; }
        .arena-podium.is-compacto .arena-podium-note { font-size: 12px; margin-top: 4px; }
        .arena-podium.is-compacto .arena-podium-stage { align-items: stretch; padding: 12px 14px 14px; gap: 8px; }

        /* Las figuras se quedan, en pequeño.

           Quitarlas dejaba la seccion en tres cajitas de texto y el sitio
           pierde justo lo que lo distingue del resto. A un tercio de alto
           siguen viendose -raza, reino y arquetipo se leen de lejos- y el
           podio entero cabe en cuatrocientos pixeles. */
        .arena-podium.is-compacto .arena-podium-figure,
        .arena-podium.is-compacto .arena-podium-slot.is-1 .arena-podium-figure {
            /* Estrecho y alto, no una franja apaisada: el visor encuadra por
               el lado corto, asi que un recuadro de 400x104 dejaba al guerrero
               como una mota en medio de una banda vacia. En vertical se ve la
               figura entera. */
            width: clamp(74px, 8vw, 96px);
            height: clamp(96px, 10.5vw, 126px);
            margin-inline: auto;
        }
        .arena-podium.is-compacto .arena-podium-empty { font-size: 13px; }

        /* Los tres cajones se igualan: el escalonado necesita figuras de
           alturas distintas encima, y aqui las tres miden lo mismo. */
        .arena-podium.is-compacto .arena-podium-block,
        .arena-podium.is-compacto .arena-podium-slot.is-1 .arena-podium-block,
        .arena-podium.is-compacto .arena-podium-slot.is-2 .arena-podium-block,
        .arena-podium.is-compacto .arena-podium-slot.is-3 .arena-podium-block {
            min-height: 0;
            padding: 10px 8px;
            border-bottom: 1px solid rgba(216, 177, 92, 0.3);
            border-radius: 10px;
        }
        .arena-podium.is-compacto .arena-podium-medal { font-size: 17px; }
        .arena-podium.is-compacto .arena-podium-prize b { font-size: 20px; }
        .arena-podium.is-compacto .arena-podium-gema-mini { width: 15px; }

        @media (max-width: 1023px) {
            /* En vertical, el premio va PRIMERO.

               Debajo del titulo y de tres botones apilados quedaba a pantalla y
               media de scroll: lo que engancha a quien llega sin cuenta es ver
               que hay un premio en juego y quien lo lleva, no leer la
               descripcion del sistema de puntos. */
            .arena-hero { gap: 22px; }
            .arena-hero-side { order: -1; }
        }

        @media (max-width: 640px) {
            /* Sigue siendo un podio: apilarlo en tres tarjetas perderia lo
               unico que aporta, que es ver de un golpe quien va ganando. Se
               encoge, no se deshace. */
            .arena-podium-stage { gap: 4px; padding: 10px 8px 0; }
            .arena-podium-figure { height: clamp(92px, 25vw, 126px); }
            .arena-podium-slot.is-1 .arena-podium-figure { height: clamp(116px, 31vw, 156px); }
            .arena-podium-block { padding: 8px 3px 10px; border-radius: 8px 8px 0 0; min-height: 104px; }
            .arena-podium-slot.is-1 .arena-podium-block { min-height: 134px; padding-top: 10px; }
            .arena-podium-slot.is-2 .arena-podium-block { min-height: 120px; }
            .arena-podium-slot.is-3 .arena-podium-block { min-height: 102px; }
            /* "10 L" con la gema al lado. La palabra entera no cabe en un
               cajon de un tercio de pantalla, pero esconderla del todo dejaba
               un numero suelto que no decia de que era. */
            .arena-podium-unit { display: none; }
            .arena-podium-unit-short { display: inline; font-size: 11px; }
            /* La gema pasa a su propia linea, debajo de la cifra. Al lado
               estrecha el numero, que es lo que hay que leer primero, y en un
               cajon de un tercio de pantalla eso se nota. */
            .arena-podium-prize { gap: 3px; }
            /* Mas pequeña: en un cajon de un tercio de pantalla, la gema
               competia con la cifra en vez de acompañarla. */
            .arena-podium-gema-mini { width: 14px; }
            .arena-podium-who { margin-top: 4px; padding-top: 4px; }
            .arena-podium-who b { font-size: 11px; }
            .arena-podium-who > span { font-size: 9.5px; }
        }

        /* El numero de cada paso en la guia. Un circulo con la cifra dentro
           hace que los tres cuadros se lean como una secuencia y no como tres
           cosas sueltas puestas en fila. */
        .arena-guia-num {
            display: grid;
            place-items: center;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 1px solid rgba(216, 177, 92, 0.42);
            background: rgba(216, 177, 92, 0.12);
            color: var(--arena-gold);
            font-family: 'Cinzel', serif;
            font-size: 18px;
            font-weight: 700;
        }

        /* ── Escenario del combate ─────────────────────────────────────────
           Las figuras dejan de ser un icono al lado del nombre y pasan a ser
           lo principal. Dos motivos, los dos practicos: reconocer al rival
           cuando llegas al punto de encuentro se hace de lejos -raza, sexo y
           arquetipo- y los avisos aparecen encima de quien los manda, que es
           mucho mas rapido de leer que una lista con nombres. */
        /* Escenario y chat en paralelo.

           Apilados, el panel de combate pasaba de los mil doscientos pixeles:
           figuras, chat, formulario de reporte y el pie, todo en fila india.
           Y se usan a la vez -se avisa mirando a quien avisa-, asi que
           ponerlos uno al lado del otro no solo ahorra alto: quita el viaje
           de ida y vuelta entre las dos mitades.

           Solo cuando hay chat: sin el, el escenario se queda a todo lo ancho
           como estaba. */
        /* Desde 1180px, que es donde caben las dos columnas sin estrujar
           ninguna: por debajo, el reparto dejaba el chat en 288px -bocadillos
           de tres lineas- y era peor que apilarlo. */
        @media (min-width: 1180px) {
            .arena-live-arena.has-chat {
                display: grid;
                /* Casi a partes iguales, y el chat con un suelo de 380px.

                   Con 1.25fr contra 0.75fr el chat salia en una tira de 290px
                   en un portatil: los bocadillos se partian en tres lineas y
                   las frases de la botonera no cabian. El escenario aguanta de
                   sobra la mitad -las figuras son altas, no anchas- y el chat
                   es lo que se lee. */
                grid-template-columns: minmax(300px, 1fr) minmax(360px, 1fr);
            }

            /* Con equipos, el escenario necesita mas: cuatro o seis figuras a
               medias con el chat salen a cien pixeles cada una y los nombres
               se cortan. El chat cede lo justo y conserva su suelo. */
            .arena-live-arena.has-chat[data-team-size="2"],
            .arena-live-arena.has-chat[data-team-size="3"] {
                grid-template-columns: minmax(400px, 1.25fr) minmax(340px, 1fr);
            }

            /* Y el nombre se parte en dos lineas antes que cortarse con
               puntos suspensivos: es como se reconoce al rival en la zona, y
               "Guerrero Anón..." no reconoce a nadie. */
            .arena-live-arena.has-chat .arena-battle-fighter figcaption b {
                white-space: normal;
                overflow-wrap: anywhere;
                line-height: 1.25;
                gap: clamp(12px, 1.6vw, 20px);
                /* Las dos columnas a la misma altura y el escenario centrado
                   dentro de la suya: con `start`, las figuras se quedaban
                   arriba y debajo colgaba un palmo de fondo vacio del alto
                   que le sacara el chat. */
                align-items: stretch;
                padding-right: clamp(14px, 2.4vw, 26px);
            }
            /* El escenario, centrado en su columna.

               Estirarlo para llenar el alto que marca el chat no vale: el
               visor 3D mide el recuadro una sola vez al montarse, asi que las
               figuras se quedaban diminutas en medio de un cajon vacio. Se
               deja a su tamaño y se centra, y el que se acerca es el chat. */
            .arena-live-arena.has-chat .arena-battle { align-content: center; }
            /* El escenario ya trae su propio relleno lateral. */
            .arena-live-arena.has-chat .arena-battle { padding-right: 0; }
            .arena-live-arena.has-chat .arena-chat {
                margin-top: clamp(14px, 2.4vw, 26px);
                /* Las columnas de dentro del chat dejan de estrecharse a 620:
                   aqui la caja ya es estrecha y ese tope la dejaba flotando
                   con aire a los dos lados. */
                align-self: stretch;
            }
            .arena-live-arena.has-chat .arena-chat-head,
            .arena-live-arena.has-chat .arena-chat-log,
            .arena-live-arena.has-chat .arena-chat-quick,
            .arena-live-arena.has-chat .arena-chat-status { max-width: none; }
            /* Algo mas alto que apilado -la columna da sitio- pero sin pasarse:
               si el chat crece de mas, la columna de al lado se queda con un
               palmo de fondo vacio, porque el escenario no puede estirarse. */
            .arena-live-arena.has-chat .arena-chat-log { min-height: 150px; max-height: 250px; }
            /* Dos columnas de frases: en una caja de 380px, tres no caben. */
            .arena-live-arena.has-chat .arena-chat-quick { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        .arena-battle {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: clamp(10px, 2vw, 22px);
            align-items: stretch;
            padding: clamp(14px, 2.4vw, 26px) clamp(14px, 2.4vw, 26px) clamp(10px, 1.6vw, 18px);
        }
        .arena-battle-side { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
        .arena-battle-side h3 {
            margin: 0;
            font-size: 10.5px;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--team-color, var(--arena-gold));
        }
        .arena-battle-fighters {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: 1fr;
            gap: 8px;
            min-width: 0;
        }

        .arena-battle-fighter {
            position: relative;
            margin: 0;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        /* El suelo del escenario. Un degradado y una sombra elíptica bastan
           para que la figura no parezca recortada y pegada sobre el panel. */
        .arena-battle-stage {
            position: relative;
            border-radius: 16px;
            border: 1px solid var(--arena-line);
            background:
                radial-gradient(120% 70% at 50% 8%, color-mix(in srgb, var(--team-color, #d8b15c) 16%, transparent), transparent 70%),
                linear-gradient(180deg, rgba(8, 6, 4, 0.55), rgba(4, 3, 2, 0.9));
            overflow: hidden;
            height: clamp(190px, 26vw, 280px);
            transition: border-color .3s ease, box-shadow .3s ease;
        }
        .arena-battle-fighters[data-count="2"] .arena-battle-stage { height: clamp(150px, 19vw, 215px); }
        .arena-battle-fighters[data-count="3"] .arena-battle-stage { height: clamp(124px, 15vw, 180px); }
        /* El escenario recorta para que el canvas respete el redondeo, asi que
           el bocadillo va por dentro y con su propio margen. */
        .arena-battle-stage { isolation: isolate; }

        .arena-battle-stage::after {
            content: '';
            position: absolute;
            left: 50%;
            bottom: 8%;
            width: 58%;
            height: 10px;
            transform: translateX(-50%);
            border-radius: 50%;
            background: radial-gradient(closest-side, rgba(0, 0, 0, 0.72), transparent);
            pointer-events: none;
        }
        .arena-battle-portrait { border: 0; border-radius: 0; background: transparent; width: 100%; }
        .arena-battle-portrait::after { display: none; }

        .arena-battle-fighter figcaption { padding: 8px 4px 0; min-width: 0; text-align: center; }
        .arena-battle-fighter figcaption b {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--arena-text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .arena-battle-fighter figcaption span {
            display: block;
            font-size: 11px;
            color: var(--arena-muted);
        }

        .arena-battle-vs {
            display: grid;
            place-items: center;
            align-self: center;
        }
        .arena-battle-vs span {
            font-family: 'Cinzel', serif;
            font-size: clamp(15px, 2.2vw, 22px);
            font-weight: 700;
            letter-spacing: 0.1em;
            color: var(--arena-muted);
            padding: 8px 10px;
            border-radius: 99px;
            border: 1px solid var(--arena-line);
            background: rgba(10, 7, 5, 0.65);
        }

        /* ── El bocadillo del aviso ────────────────────────────────────────
           Sale encima de la figura de quien avisa, aguanta unos segundos y se
           va. No se apila: el ultimo aviso sustituye al anterior, porque lo que
           importa es lo ultimo que dijo, no el historial -que esta debajo-. */
        /* Abajo, sobre el suelo, y no encima de la cabeza.

           Encima quedaba mas natural -es donde lo pone cualquier juego- pero el
           escenario mide casi 300px y la barra superior es fija: con la pagina
           desplazada hacia la botonera, que es donde se esta mirando al pulsar,
           el aviso aparecia detras de la barra. Aqui se lee siempre, y ademas
           cae justo encima del nombre. */
        .arena-battle-bubble {
            position: absolute;
            left: 50%;
            bottom: 12px;
            z-index: 4;
            transform: translate(-50%, 6px);
            display: flex;
            align-items: center;
            gap: 7px;
            max-width: min(220px, calc(100% - 20px));
            padding: 7px 12px;
            border-radius: 13px;
            border: 1px solid rgba(222, 185, 99, 0.42);
            background: linear-gradient(180deg, rgba(34, 24, 16, 0.98), rgba(18, 12, 8, 0.98));
            box-shadow: 0 10px 26px rgba(0, 0, 0, 0.62);
            color: var(--arena-text);
            font-size: 12.5px;
            line-height: 1.25;
            opacity: 0;
            pointer-events: none;
            animation: arenaBubbleIn .34s cubic-bezier(.2,.9,.3,1.3) forwards;
        }
        .arena-battle-bubble[hidden] { display: none; }
        .arena-battle-bubble::after {
            content: '';
            position: absolute;
            left: 50%;
            top: -6px;
            width: 11px;
            height: 11px;
            transform: translateX(-50%) rotate(45deg);
            background: rgba(34, 24, 16, 0.98);
            border-left: 1px solid rgba(222, 185, 99, 0.42);
            border-top: 1px solid rgba(222, 185, 99, 0.42);
        }
        .arena-battle-bubble.is-leaving { animation: arenaBubbleOut .3s ease forwards; }
        .arena-battle-bubble [data-fighter-bubble-icon] { font-size: 14px; line-height: 1; }
        .arena-battle-bubble [data-fighter-bubble-text] { min-width: 0; }

        /* El tono tiñe el borde: de un vistazo se distingue un "ya llegue" de
           un "me han matado" sin leer. */
        .arena-battle-bubble.is-sitio { border-color: rgba(124, 201, 138, 0.55); }
        .arena-battle-bubble.is-aviso { border-color: rgba(255, 107, 94, 0.55); }
        .arena-battle-bubble.is-prisa { border-color: rgba(255, 190, 92, 0.6); }

        /* Y la figura de quien acaba de avisar se marca un momento, para que el
           ojo vaya solo hacia ella en un 3v3. */
        .arena-battle-fighter.is-talking .arena-battle-stage {
            border-color: rgba(222, 185, 99, 0.6);
            box-shadow: 0 0 0 1px rgba(222, 185, 99, 0.25), 0 12px 30px rgba(0, 0, 0, 0.5);
        }

        @keyframes arenaBubbleIn {
            from { opacity: 0; transform: translate(-50%, 16px) scale(.9); }
            to { opacity: 1; transform: translate(-50%, 6px) scale(1); }
        }
        @keyframes arenaBubbleOut {
            from { opacity: 1; transform: translate(-50%, 6px) scale(1); }
            to { opacity: 0; transform: translate(-50%, -4px) scale(.96); }
        }

        /* ── Chat rapido del combate ───────────────────────────────────────
           Plegado es una sola linea con lo ultimo que se dijo. Abierto, un chat
           de toda la vida: burbujas a un lado y a otro, lo mas nuevo abajo, y
           una barra de frases que se desliza. Un combate se juega mirando el
           mapa y el reloj: la caja de mensajes no puede comerse la pantalla. */
        .arena-chat {
            margin: 0 clamp(14px, 2.4vw, 26px) clamp(12px, 1.8vw, 18px);
            border: 1px solid var(--arena-line);
            border-radius: 14px;
            background: rgba(10, 7, 5, 0.66);
            overflow: hidden;
        }

        /* La cabecera ya no es un boton: el chat esta siempre abierto. Plegado
           no servia -se usa con prisa, a mitad de un combate, y abrir la caja
           era un paso que nadie daba- asi que aqui solo queda el rotulo y el
           contador de lo que llego con la pestaña de lado. */
        .arena-chat-head {
            display: flex;
            align-items: center;
            gap: 9px;
            width: 100%;
            max-width: 620px;
            margin-inline: auto;
            padding: 10px 13px;
            text-align: left;
        }

        .arena-chat-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex: none;
            background: #7cc98a;
            box-shadow: 0 0 0 3px rgba(124, 201, 138, 0.16);
        }
        .arena-chat-title {
            font-size: 10.5px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--arena-gold-soft);
            white-space: nowrap;
            flex: none;
        }
        .arena-chat-title { flex: 1 1 auto; }

        /* Lo que llego con la pestaña de lado. Se limpia al volver a mirar: un
           contador que no baja nunca deja de significar nada. */
        .arena-chat-badge {
            flex: none;
            min-width: 19px;
            height: 19px;
            padding: 0 6px;
            border-radius: 99px;
            display: grid;
            place-items: center;
            font-size: 11px;
            font-weight: 700;
            color: #1a1005;
            background: var(--arena-gold);
            animation: arenaChatPop .3s cubic-bezier(.2,.9,.3,1.4);
        }
        .arena-chat-badge[hidden] { display: none; }

        .arena-chat-body { border-top: 1px solid var(--arena-line); }

        .arena-chat-log {
            list-style: none;
            margin: 0;
            padding: 11px 13px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            /* Cabe poco a proposito: son cinco frases, no una conversacion. */
            min-height: 96px;
            max-height: 168px;
            /* La columna de mensajes no se estira con el panel. En un monitor
               ancho las burbujas acababan a novecientos pixeles una de otra,
               cada una pegada a un borde, y aquello no parecia un chat sino dos
               notas sueltas en una banda vacia. */
            width: 100%;
            max-width: 620px;
            margin-inline: auto;
            overflow-y: auto;
            scrollbar-width: thin;
            overscroll-behavior: contain;
        }
        /* Pocos mensajes se pegan abajo, como en cualquier chat, en vez de
           quedarse flotando en medio de una caja medio vacia.

           Con `justify-content: flex-end`, que es lo evidente, el chat se
           quedaba SIN historial: en una caja que desborda, esa propiedad
           empuja el contenido mas alla del origen del scroll y lo que sale
           por arriba deja de ser alcanzable -la barra no sube hasta ahi-.
           Un margen automatico en el primero baja el grupo igual cuando
           sobra sitio y no hace nada cuando falta, que es justo lo que
           queremos. */
        .arena-chat-log > :first-child { margin-top: auto; }
        .arena-chat-msg { display: flex; }
        .arena-chat-msg.is-mine { justify-content: flex-end; }
        .arena-chat-msg.is-theirs { justify-content: flex-start; }

        .arena-chat-bubble {
            display: inline-flex;
            flex-direction: column;
            gap: 1px;
            max-width: min(78%, 330px);
            padding: 6px 11px 5px;
            border-radius: 13px;
            border: 1px solid var(--arena-line);
            background: rgba(22, 15, 10, 0.9);
            position: relative;
        }
        /* Las mias a la derecha y en dorado; las suyas a la izquierda y en
           frio. Es lo que hace que se lea como un chat de un vistazo, sin
           tener que leer los nombres. */
        .arena-chat-msg.is-mine .arena-chat-bubble {
            border-color: rgba(222, 185, 99, 0.42);
            background: linear-gradient(180deg, rgba(58, 42, 22, 0.92), rgba(30, 21, 12, 0.94));
            border-bottom-right-radius: 4px;
        }
        .arena-chat-msg.is-theirs .arena-chat-bubble {
            border-color: rgba(120, 170, 255, 0.34);
            background: linear-gradient(180deg, rgba(20, 28, 44, 0.92), rgba(12, 16, 26, 0.94));
            border-bottom-left-radius: 4px;
        }

        .arena-chat-who {
            font-size: 10px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--arena-muted);
        }
        .arena-chat-msg.is-mine .arena-chat-who { color: rgba(222, 185, 99, 0.75); }
        .arena-chat-said { font-size: 13px; color: var(--arena-text); line-height: 1.3; }
        .arena-chat-bubble time {
            align-self: flex-end;
            font-size: 9.5px;
            color: var(--arena-muted);
            margin-top: 1px;
        }
        .arena-chat-empty {
            font-size: 12px;
            color: var(--arena-muted);
            text-align: center;
            padding: 10px 0;
        }

        /* La barra de frases. Se desliza de lado, como los emotes de cualquier
           juego: doce botones apilados comerian media pantalla en un movil. */
        /* La barra se desliza, y eso tiene que VERSE. Sin el degradado del
           final, la ultima frase aparece cortada y parece un fallo de maqueta
           en vez de una invitacion a arrastrar. */
        /* Las seis frases, en rejilla y todas a la vista.
           Antes iban en una barra que se arrastraba de lado. En un movil eso
           funciona; con raton no, asi que en escritorio la mitad de las frases
           sencillamente no existian: no habia forma de llegar a ellas. */
        .arena-chat-quick {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 7px;
            width: 100%;
            max-width: 620px;
            margin-inline: auto;
            padding: 10px 13px;
            border-top: 1px solid var(--arena-line);
        }
        .arena-chat-quick-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 0;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid var(--arena-line);
            background: rgba(24, 17, 11, 0.9);
            color: var(--arena-text);
            font-size: 12px;
            line-height: 1.2;
            text-align: center;
            cursor: pointer;
            transition: transform .15s ease, border-color .2s ease, background .2s ease;
        }
        .arena-chat-quick-icon { flex: none; }
        /* La frase se parte en dos lineas antes que salirse del boton: en una
           rejilla de tres columnas, "Esperame, ya voy" no cabe de una pieza. */
        .arena-chat-quick-text { min-width: 0; }
        .arena-chat-quick-btn:hover:not(:disabled) {
            transform: translateY(-1px);
            border-color: rgba(222, 185, 99, 0.5);
            background: rgba(40, 28, 17, 0.95);
        }
        .arena-chat-quick-btn:active:not(:disabled) { transform: translateY(0) scale(.96); }
        .arena-chat-quick-btn:disabled { opacity: .45; cursor: default; }
        .arena-chat-quick-btn.is-sitio:hover:not(:disabled) { border-color: rgba(124, 201, 138, 0.6); }
        .arena-chat-quick-btn.is-aviso:hover:not(:disabled) { border-color: rgba(255, 107, 94, 0.6); }
        .arena-chat-quick-btn.is-prisa:hover:not(:disabled) { border-color: rgba(255, 190, 92, 0.6); }

        .arena-chat-status {
            margin: 0;
            /* Con la misma anchura que los mensajes y los botones. Suelto a
               todo lo ancho, en un panel de novecientos pixeles el aviso de
               error salia pegado al borde izquierdo, a un palmo de la columna
               con la que debia alinearse, justo cuando algo falla. */
            width: 100%;
            max-width: 620px;
            margin-inline: auto;
            padding: 0 13px 9px;
            font-size: 11px;
            color: var(--arena-muted);
            min-height: 13px;
        }
        .arena-chat-status.is-error { color: #ff9b8f; }

        @keyframes arenaChatPop {
            from { transform: scale(.5); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        @media (max-width: 720px) {
            /* El duelo se queda cara a cara: dos columnas y el VS en medio.
               Apilado ocupaba dos pantallas enteras y habia que desplazarse
               para ver contra quien juegas, que es justo lo que se viene a
               mirar. */
            .arena-battle { padding: 12px 12px 8px; gap: 8px; }
            .arena-battle[data-team-size="1"] { grid-template-columns: 1fr auto 1fr; }
            .arena-battle[data-team-size="1"] .arena-battle-stage { height: clamp(150px, 42vw, 200px); }

            /* Con equipos si se apila: tres figuras repartidas en media
               pantalla no serian ni siluetas. */
            .arena-battle:not([data-team-size="1"]) { grid-template-columns: 1fr; }
            .arena-battle:not([data-team-size="1"]) .arena-battle-vs { justify-self: center; margin: 2px 0; }
            .arena-battle-fighters[data-count="2"] .arena-battle-stage { height: clamp(128px, 30vw, 165px); }
            .arena-battle-fighters[data-count="3"] .arena-battle-stage { height: clamp(104px, 24vw, 140px); }

            .arena-battle-vs span { padding: 4px 9px; font-size: 12px; }
            .arena-battle-fighter figcaption b { font-size: 12px; }
            .arena-battle-fighter figcaption span { font-size: 10px; }
            .arena-battle-bubble { font-size: 11px; padding: 5px 9px; gap: 5px; }

            .arena-chat-head { padding: 9px 12px; }
            /* Mas alto para el historial. Lo que se gana abajo -la botonera
               en una sola fila en vez de tres- se le da aqui: el chat ocupa
               menos en total y aun asi se leen mas mensajes de una vez. */
            .arena-chat-log { min-height: 116px; max-height: 210px; padding: 10px; }
            .arena-chat-bubble { max-width: 86%; }

            /* Las seis frases en una sola fila que se arrastra de lado.

               En rejilla ocupaban tres filas -mas de ciento treinta pixeles-
               justo debajo del historial, y en un movil eso es la mitad de lo
               que se ve. Aqui hay dedos, asi que arrastrar SI funciona: es lo
               que no valia en escritorio, donde siguen estando todas a la
               vista. */
            .arena-chat-quick {
                display: flex;
                flex-wrap: nowrap;
                overflow-x: auto;
                scroll-snap-type: x proximity;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior-x: contain;
                scrollbar-width: none;
                padding: 9px 10px;
                gap: 8px;
            }
            .arena-chat-quick::-webkit-scrollbar { display: none; }
            /* Que la fila se arrastra tiene que VERSE. Sin el desvanecido del
               borde, la ultima frase aparece cortada y parece un fallo de
               maqueta en vez de una invitacion a empujar. */
            .arena-chat-quick {
                -webkit-mask-image: linear-gradient(90deg, #000 88%, transparent 100%);
                mask-image: linear-gradient(90deg, #000 88%, transparent 100%);
            }

            /* Dedos, no raton. Un boton de treinta pixeles de alto termina en
               toques fallados o en frases mandadas sin querer, y esto se usa
               con prisa a mitad de un combate. */
            .arena-chat-quick-btn {
                flex: 0 0 auto;
                scroll-snap-align: start;
                min-height: 44px;
                padding: 8px 12px;
                font-size: 12.5px;
                white-space: nowrap;
            }
            .arena-chat-status { padding-bottom: 11px; }
        }

        /* Un movil bajo -o con el teclado fuera- no puede quedarse sin sitio
           para el 3D por culpa del chat. */
        @media (max-width: 720px) and (max-height: 700px) {
            .arena-chat-log { min-height: 64px; max-height: 112px; }
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

        @media (max-width: 620px) {
            .arena-duel-panel-foot .arena-duel-zone {
                flex-wrap: wrap;
                row-gap: 8px;
            }
            /* El punto separador sobra cuando los dos lados no comparten fila. */
            .arena-duel-zone-key + .arena-duel-zone-btn + .arena-duel-zone-key { display: none; }
            .arena-duel-zone-btn { flex: 1 1 100%; justify-content: center; }
            .arena-duel-panel-foot .arena-duel-zone-value { width: 100%; text-align: center; }
        }
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
        .arena-choice[hidden] { display: none !important; }
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
                        <a href="{{ route('hall-of-fame') }}" class="arena-nav-link {{ request()->routeIs('hall-of-fame') ? 'arena-nav-link-active' : '' }}">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 1l2.39 4.84 5.34.78-3.86 3.77.91 5.32L10 13.2l-4.78 2.51.91-5.32L2.27 6.62l5.34-.78L10 1z"/></svg>
                            Fama
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
                    <a href="{{ route('hall-of-fame') }}" class="arena-nav-link block w-full {{ request()->routeIs('hall-of-fame') ? 'arena-nav-link-active' : '' }}">Salon de la Fama</a>
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
                // El sondeo lo consulta: cambiar el panel debajo de una ventana
                // abierta la haria desaparecer a media lectura.
                isOpen() {
                    return !!abierta;
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

            /* ── El aviso cuando la pestaña no esta delante ──

               Un toast es un div: con la pestaña de lado nadie lo ve, y al
               volver ya se habia ido solo. El sonido tampoco basta -el
               navegador puede tener el contexto suspendido en segundo plano-,
               asi que quien dejaba la pagina abierta en otra pestaña se
               enteraba del cruce al volver a mirar, que es justo cuando ya no
               sirve de nada.

               Tres capas, de mas a menos fiable: notificacion del sistema si
               hay permiso, el titulo de la pestaña parpadeando siempre, y el
               toast de siempre para cuando SI se esta mirando. */
            // Se relee al empezar a parpadear y no una sola vez: el panel se
            // repinta solo y cambia el titulo por el camino.
            let tituloBase = document.title;
            let tituloTimer = null;
            let tituloPendiente = null;

            const hayNotificaciones = () => typeof window.Notification === 'function';

            /* El permiso se pide con el mismo gesto que enciende las alertas.
               Pedirlo al cargar la pagina es la forma mas rapida de que lo
               denieguen para siempre, y una vez denegado no hay vuelta atras
               desde la pagina. */
            const pedirPermiso = async () => {
                if (!hayNotificaciones() || Notification.permission !== 'default') {
                    return hayNotificaciones() && Notification.permission === 'granted';
                }

                try {
                    return (await Notification.requestPermission()) === 'granted';
                } catch (_) {
                    return false;
                }
            };

            const pararTitulo = () => {
                if (tituloTimer) { window.clearInterval(tituloTimer); tituloTimer = null; }
                tituloPendiente = null;
                document.title = tituloBase;
            };

            /* El titulo alterna entre el de la pagina y el aviso. Es el unico
               canal que funciona sin permisos, sin sonido y con la pestaña
               minimizada: se ve en la barra de pestañas y en la del sistema. */
            const parpadearTitulo = (mensaje) => {
                tituloPendiente = mensaje;
                if (tituloTimer) { return; }

                tituloBase = document.title;

                let alterno = false;
                tituloTimer = window.setInterval(() => {
                    alterno = !alterno;
                    document.title = alterno ? ('🔔 ' + tituloPendiente) : tituloBase;
                }, 1200);
            };

            const notificarSistema = (type, mensaje) => {
                if (!hayNotificaciones() || Notification.permission !== 'granted') { return false; }

                try {
                    // La etiqueta agrupa: diez avisos del mismo combate
                    // sustituyen al anterior en vez de apilar diez globos.
                    const aviso = new Notification('Regnum Arena Ladder', {
                        body: mensaje,
                        tag: 'arena:' + type,
                        renotify: true,
                        icon: '{{ asset('images/logo-arena-ladder.png') }}',
                        silent: false,
                    });

                    aviso.onclick = () => {
                        try { window.focus(); } catch (_) {}
                        aviso.close();
                    };

                    return true;
                } catch (_) {
                    return false;
                }
            };

            // Volver a la pestaña es haberse enterado: el titulo deja de
            // parpadear sin tener que tocar nada.
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) { pararTitulo(); }
            });
            window.addEventListener('focus', pararTitulo);

            // Dos notas como mucho, separadas, y la ultima con cola larga para
            // que el aviso se apague solo. Los avisos importantes suben de tono
            // (match encontrado, caceria); los informativos se quedan planos.
            //
            // Las ganancias estan x2,6 respecto a las primeras que puse. Con
            // 0.05 el toque se oia en una habitacion en silencio y nada mas:
            // esto suena por encima del juego, que es donde esta el jugador
            // cuando le toca enterarse. Aun asi se queda lejos de 1.0, que es
            // donde WebAudio empieza a saturar.
            const patterns = {
                match_found: {
                    tones: [
                        { freq: 880, duration: 0.55, delay: 0.00, gain: 0.143 },
                        { freq: 1318, duration: 1.60, delay: 0.16, gain: 0.143 },
                    ],
                    vibrate: [80, 40, 120],
                },
                /* El aviso del rival dentro del combate.

                   Corto y discreto a proposito: durante una partida pueden
                   llegar varios seguidos, y un toque tan largo como el de
                   "combate encontrado" acabaria siendo un estorbo. Dos notas
                   breves, como el mensaje de cualquier chat. */
                match_ping: {
                    tones: [
                        { freq: 1046, duration: 0.18, delay: 0.00, gain: 0.099 },
                        { freq: 1396, duration: 0.30, delay: 0.07, gain: 0.083 },
                    ],
                    vibrate: [35],
                },
                /* El aviso que mando YO.

                   Mas grave y de una sola nota, al reves que el del rival: son
                   dos cosas distintas y tienen que distinguirse a ciegas, sin
                   mirar la pantalla. Sin esto, dar al boton no sonaba a nada y
                   no habia forma de saber si el aviso habia salido. */
                match_ping_sent: {
                    tones: [
                        { freq: 620, duration: 0.14, delay: 0.00, gain: 0.078 },
                    ],
                    vibrate: [18],
                },
                party_invite: {
                    tones: [
                        { freq: 784, duration: 0.45, delay: 0.00, gain: 0.117 },
                        { freq: 1046, duration: 1.30, delay: 0.14, gain: 0.117 },
                    ],
                    vibrate: [60, 30, 80],
                },
                party_ready: {
                    tones: [
                        { freq: 659, duration: 0.40, delay: 0.00, gain: 0.117 },
                        { freq: 988, duration: 1.25, delay: 0.14, gain: 0.117 },
                    ],
                    vibrate: [70],
                },
                hunt_start: {
                    tones: [
                        { freq: 587, duration: 0.40, delay: 0.00, gain: 0.130 },
                        { freq: 880, duration: 0.40, delay: 0.15, gain: 0.130 },
                        { freq: 1174, duration: 1.70, delay: 0.30, gain: 0.130 },
                    ],
                    vibrate: [120, 50, 120],
                },
                report_submitted: {
                    tones: [
                        { freq: 698, duration: 0.35, delay: 0.00, gain: 0.104 },
                        { freq: 880, duration: 1.10, delay: 0.13, gain: 0.104 },
                    ],
                    vibrate: [50, 25, 50],
                },
                report_confirmed: {
                    tones: [
                        { freq: 659, duration: 0.35, delay: 0.00, gain: 0.117 },
                        { freq: 988, duration: 1.45, delay: 0.14, gain: 0.117 },
                    ],
                    vibrate: [90, 40, 90],
                },
                generic: {
                    tones: [
                        { freq: 880, duration: 1.10, delay: 0.00, gain: 0.104 },
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
                /* Los oyentes NO se quitan tras el primer exito. En iOS el
                   contexto se interrumpe al bloquear la pantalla, al cambiar de
                   app o al entrar una llamada, y se queda suspendido para
                   siempre: quien deja el movil un momento y vuelve ya no oye
                   nada, aunque el interruptor diga "alertas activas". */
                const tryUnlock = () => {
                    if (!audioContext || audioContext.state !== 'running') {
                        unlock();
                    }
                };

                unlockEvents.forEach((eventName) => {
                    document.addEventListener(eventName, tryUnlock, true);
                });

                /* El permiso de notificaciones, para quien ya traia las
                   alertas encendidas de otra visita y por tanto no va a
                   volver a tocar el interruptor. Se pide en el primer gesto
                   -el navegador no lo acepta sin uno- y una sola vez en la
                   vida: si lo ignoran, queda el parpadeo del titulo, que no
                   pide permiso a nadie. */
                const pedidoKey = 'arena:notif:pedido';

                const pedirUnaVez = () => {
                    document.removeEventListener('pointerdown', pedirUnaVez, true);
                    document.removeEventListener('keydown', pedirUnaVez, true);

                    if (!enabled || safeGet(pedidoKey) === '1') { return; }
                    if (!hayNotificaciones() || Notification.permission !== 'default') { return; }

                    safeSet(pedidoKey, '1');
                    pedirPermiso();
                };

                document.addEventListener('pointerdown', pedirUnaVez, true);
                document.addEventListener('keydown', pedirUnaVez, true);

                // Volver a primer plano cuenta como oportunidad de reanudar.
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) { tryUnlock(); }
                });
                window.addEventListener('pageshow', tryUnlock);
                window.addEventListener('focus', tryUnlock);
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

                /* En segundo plano el navegador puede haber suspendido el
                   contexto por su cuenta. Programar notas sobre un contexto
                   suspendido no suena: se queda todo esperando y sale de
                   golpe al volver, que es peor que el silencio. Se le pide
                   que vuelva y se sigue -la promesa no se espera porque esto
                   no es async y el aviso no puede quedarse colgado-. */
                if (context.state !== 'running') {
                    try { context.resume(); } catch (_) {}
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

                    // El permiso de notificaciones va aqui y no al cargar la
                    // pagina: este es el gesto en el que la persona ha dicho
                    // "avisame", que es el unico momento en que el globo del
                    // navegador tiene sentido. Preguntado en frio se deniega,
                    // y denegado no se puede volver a pedir.
                    if (!options.silent) { await pedirPermiso(); }

                    /* Y con el permiso dado, este navegador se apunta al push.
                       Es la misma decision -"avisame"- asi que va con el mismo
                       interruptor: un ajuste aparte para "avisame tambien con
                       la pestaña cerrada" es un ajuste que nadie encuentra. */
                    document.dispatchEvent(new CustomEvent('arena:alertas', {
                        detail: { enabled: true },
                    }));

                    if (!options.silent) {
                        // Un toque de prueba al encender: es la unica forma de
                        // saber si de verdad va a sonar. En un iPhone con el
                        // interruptor lateral en silencio no suena nada, y eso
                        // no lo puede saltar ninguna pagina web.
                        playPattern('generic');
                        arenaToast(
                            unlocked
                                ? 'Alertas sonoras activadas. Si no has oido el toque, revisa el interruptor de silencio del movil.'
                                : 'Alertas activadas. Tu navegador todavia no deja sonar: toca cualquier parte de la pagina.',
                            unlocked ? 'success' : 'warning',
                            5000
                        );
                    }
                } else {
                    document.dispatchEvent(new CustomEvent('arena:alertas', {
                        detail: { enabled: false },
                    }));

                    if (!options.silent) {
                        arenaToast('Alertas silenciadas. Tampoco te avisaremos con la pagina cerrada.', 'info', 3500);
                    }
                }

                updateButtons();
            };

            const notify = (type, message, options = {}) => {
                const eventKey = options.key || type;
                if (!enabled || !shouldEmit(eventKey)) {
                    return false;
                }

                playPattern(type);

                if (!message) {
                    return true;
                }

                /* Con la pestaña delante, el toast de siempre. De lado, el
                   toast no lo va a ver nadie: el aviso tiene que salir de la
                   pagina -notificacion del sistema- y quedarse puesto en el
                   titulo hasta que se vuelva. */
                if (document.hidden) {
                    notificarSistema(type, message);
                    parpadearTitulo(message);
                } else {
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
                // Solo el sonido, sin aviso flotante ni antirrepeticion. Para lo
                // que confirma una accion propia -mandar un aviso-, donde el
                // toast sobra porque la pantalla ya lo esta enseñando y donde
                // dos iguales seguidos SI tienen que sonar las dos veces.
                play: (type) => enabled && playPattern(type),
                unlock,
                setEnabled,
                toggle: () => setEnabled(!enabled),
                isEnabled: () => enabled,
                isUnlocked: () => unlocked,
                // Para la pestaña en reposo.
                pedirPermiso,
                permisoNotificaciones: () => (hayNotificaciones() ? Notification.permission : 'unsupported'),
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
                        window.setTimeout(function () { window.location.reload(); }, 1500);
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
            /* Subir 3 imagenes tarda. Sin senal el jugador vuelve a pulsar y
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
                panel.scrollIntoView({ block: 'center', behavior: 'smooth' });

                var accept = panel.querySelector('button[data-duel-accept]');
                if (accept) {
                    try { accept.focus({ preventScroll: true }); } catch (error) { accept.focus(); }
                }
            });
        })();
    </script>


    @stack('scripts')
</body>
</html>
