@auth
{{-- El interruptor de avisos flotante. Solo en movil.

     Ya no decide nada por su cuenta. La vez anterior tenia su propia idea del
     estado y su propia forma de activarse, y se peleaba con el de la barra:
     uno pintaba verde, el otro rojo, y un toque podia apagar en vez de
     encender. Ahora pinta lo que dice el controlador (`ArenaAvisos`) y al
     tocarlo le pide a el que cambie. Un estado, una decision.

     Rojo apagado, verde activo, ambar mientras se activa. Nada mas. --}}
<div class="arena-avisos is-comprobando" data-avisos>
    <button type="button"
            class="arena-avisos-pista"
            data-avisos-pista
            hidden
            tabindex="-1"
            aria-hidden="true">
        Toca para activar los avisos
    </button>

    <button type="button"
            class="arena-avisos-btn"
            data-avisos-btn
            aria-pressed="false"
            aria-label="Avisos">
        <span class="arena-avisos-punto" aria-hidden="true"></span>
        <svg class="arena-avisos-campana" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 2a6 6 0 0 0-6 6v3.6l-1.7 3.2A1 1 0 0 0 5.2 16h13.6a1 1 0 0 0 .9-1.2L18 11.6V8a6 6 0 0 0-6-6zM9.5 17.5a2.5 2.5 0 0 0 5 0z"/>
        </svg>
    </button>
</div>

<style>
    /* Abajo a la derecha, por encima de la barra del sistema en los moviles
       que la tienen. */
    /* Arriba a la derecha, en la cabecera: el sitio que tenia la hamburguesa.
       Abajo quedaba debajo de la barra de navegacion y de los avisos. Con el
       menu de admin (que conserva la hamburguesa) se corre a su izquierda. */
    .arena-avisos {
        position: fixed;
        right: 16px;
        top: calc(env(safe-area-inset-top, 0px) + 24px);
        z-index: 60;
        display: flex;
        align-items: center;
        gap: 8px;
        pointer-events: none;
    }

    /* En escritorio manda el de la barra, que esta siempre a la vista. */
    @media (min-width: 1024px) {
        .arena-avisos { display: none; }
    }

    /* En movil el de la barra sale del menu: detras de la hamburguesa no lo
       encontraba nadie, y tenerlo dos veces en la misma pantalla hace dudar
       de si hacen cosas distintas. */
    @media (max-width: 1023px) {
        .arena-mobile-menu [data-arena-alert-toggle] { display: none; }
    }

    .arena-avisos-btn {
        pointer-events: auto;
        position: relative;
        display: grid;
        place-items: center;
        /* 46px: por debajo de 44 los dedos fallan; por encima empieza a tapar. */
        width: 46px;
        height: 46px;
        border-radius: 50%;
        border: 1px solid rgba(255, 120, 120, 0.5);
        background: linear-gradient(180deg, rgba(48, 18, 18, 0.96), rgba(26, 10, 10, 0.97));
        color: #ffb4b4;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45);
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
        transition: color .25s ease, border-color .25s ease, background .25s ease, transform .15s ease;
    }
    .arena-avisos-btn:active { transform: scale(.92); }
    .arena-avisos-campana { width: 21px; height: 21px; }

    .arena-avisos-punto {
        position: absolute;
        top: 6px;
        right: 6px;
        width: 9px;
        height: 9px;
        border-radius: 50%;
        background: #ff6b6b;
        box-shadow: 0 0 0 2px rgba(26, 10, 10, 0.95);
        transition: background .25s ease;
    }

    /* Verde: el aviso va a llegar de verdad. */
    .arena-avisos.is-activo .arena-avisos-btn {
        border-color: rgba(124, 201, 138, 0.6);
        background: linear-gradient(180deg, rgba(18, 42, 24, 0.96), rgba(9, 22, 13, 0.97));
        color: #9fe0ad;
    }
    .arena-avisos.is-activo .arena-avisos-punto { background: #4ade80; box-shadow: 0 0 0 2px rgba(9, 22, 13, 0.95); }

    /* Ambar mientras se activa o se comprueba: ni verde de mentira ni rojo
       que asuste mientras el navegador contesta. */
    .arena-avisos.is-cargando .arena-avisos-btn,
    .arena-avisos.is-comprobando .arena-avisos-btn {
        border-color: rgba(252, 211, 77, 0.45);
        color: #fde68a;
        background: linear-gradient(180deg, rgba(46, 36, 14, 0.96), rgba(24, 18, 8, 0.97));
    }
    .arena-avisos.is-cargando .arena-avisos-punto,
    .arena-avisos.is-comprobando .arena-avisos-punto { background: #fcd34d; }
    .arena-avisos.is-cargando .arena-avisos-campana { animation: arenaCampanaDuda 0.9s ease-in-out infinite; }

    /* Apagado: un latido lento en el aro. Llama sin parpadear. */
    .arena-avisos.is-apagado .arena-avisos-btn { animation: arenaAvisosLatido 2.4s ease-in-out infinite; }

    @keyframes arenaAvisosLatido {
        0%, 100% { box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45), 0 0 0 0 rgba(255, 107, 107, 0.42); }
        50% { box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45), 0 0 0 8px rgba(255, 107, 107, 0); }
    }

    @keyframes arenaCampanaDuda {
        0%, 100% { transform: rotate(0); }
        25% { transform: rotate(-12deg); }
        75% { transform: rotate(12deg); }
    }

    /* La pista.
       Una pildora pequeña -una linea, 12px- que respira: crece y se encoge un
       4 %. Lo justo para que el ojo la note sin que tape la pantalla. Se
       ancla al boton por la derecha, asi que al crecer lo hace hacia el
       centro y no se sale del borde. */
    body.arena-con-menu .arena-avisos { right: 72px; }

    /* La pista, debajo de la campana. */
    .arena-avisos-pista {
        pointer-events: auto;
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        margin: 0;
        padding: 6px 11px;
        border-radius: 99px;
        border: 1px solid rgba(255, 150, 150, 0.4);
        background: linear-gradient(180deg, rgba(58, 24, 22, 0.97), rgba(30, 12, 11, 0.98));
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4);
        font-size: 12px;
        font-weight: 600;
        line-height: 1.2;
        white-space: nowrap;
        color: #ffd2cc;
        cursor: pointer;
        transform-origin: top right;
        animation:
            arenaPistaEntra .35s cubic-bezier(.2, .9, .3, 1.3) both,
            arenaPistaRespira 1.6s ease-in-out .35s infinite;
    }
    .arena-avisos-pista[hidden] { display: none; }
    .arena-avisos-pista.is-saliendo { animation: arenaPistaSale .25s ease-in both; }

    @keyframes arenaPistaEntra {
        from { opacity: 0; transform: translateX(8px) scale(.9); }
        to { opacity: 1; transform: translateX(0) scale(1); }
    }
    @keyframes arenaPistaRespira {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.045); }
    }
    @keyframes arenaPistaSale {
        from { opacity: 1; transform: scale(1); }
        to { opacity: 0; transform: translateX(8px) scale(.92); }
    }

    @media (prefers-reduced-motion: reduce) {
        .arena-avisos.is-apagado .arena-avisos-btn,
        .arena-avisos.is-cargando .arena-avisos-campana,
        .arena-avisos-pista { animation: none; }
    }
</style>

<script>
(function () {
    'use strict';

    var caja = document.querySelector('[data-avisos]');
    if (!caja) { return; }

    var boton = caja.querySelector('[data-avisos-btn]');
    var pista = caja.querySelector('[data-avisos-pista]');

    /* La pista: diez segundos, una vez por visita, y solo mientras los avisos
       esten apagados. Una vez por visita y no una en la vida: quien no los
       activo la primera vez sigue necesitando que se lo recuerden, pero no a
       cada pagina que abre. */
    var PISTA_MS = 10000;
    var PISTA_VISTA = 'arena:avisos:pista-visita';
    var timerPista = null;

    var ETIQUETAS = {
        activo: 'Avisos activados. Toca para silenciarlos.',
        inactivo: 'Avisos apagados. Toca para activarlos.',
        bloqueado: 'Avisos bloqueados en el navegador. Toca para ver como permitirlos.',
        'no-soportado': 'Toca para ver como recibir avisos en este dispositivo.',
        cargando: 'Activando los avisos…',
        comprobando: 'Comprobando los avisos…',
        desactivando: 'Silenciando los avisos…',
    };

    function vistaEnEstaVisita() {
        try { return sessionStorage.getItem(PISTA_VISTA) === '1'; } catch (e) { return false; }
    }

    function marcarVista() {
        try { sessionStorage.setItem(PISTA_VISTA, '1'); } catch (e) {}
    }

    function pintar(estado) {
        var activo = estado === 'activo';
        var neutro = estado === 'cargando' || estado === 'comprobando' || estado === 'desactivando';

        caja.classList.toggle('is-activo', activo);
        caja.classList.toggle('is-apagado', !activo && !neutro);
        caja.classList.toggle('is-cargando', estado === 'cargando');
        caja.classList.toggle('is-comprobando', estado === 'comprobando' || estado === 'desactivando');

        boton.setAttribute('aria-pressed', activo ? 'true' : 'false');
        boton.setAttribute('aria-busy', estado === 'cargando' || estado === 'desactivando' ? 'true' : 'false');
        boton.setAttribute('aria-label', ETIQUETAS[estado] || ETIQUETAS.inactivo);

        var apagado = estado === 'inactivo' || estado === 'no-soportado';

        // En escritorio el flotante no se ve: gastar ahi la pista de la
        // visita la dejaria sin enseñar si luego se estrecha la ventana.
        var visible = window.getComputedStyle(caja).display !== 'none';

        if (apagado && visible && !vistaEnEstaVisita()) {
            enseñarPista();
        } else if (!apagado) {
            esconderPista();
        }
    }

    function enseñarPista() {
        if (!pista || timerPista) { return; }

        marcarVista();
        pista.hidden = false;
        pista.classList.remove('is-saliendo');

        timerPista = window.setTimeout(esconderPista, PISTA_MS);
    }

    function esconderPista() {
        if (!pista || pista.hidden) { return; }

        window.clearTimeout(timerPista);
        timerPista = null;
        pista.classList.add('is-saliendo');

        window.setTimeout(function () {
            pista.hidden = true;
            pista.classList.remove('is-saliendo');
        }, 250);
    }

    function alTocar(evento) {
        evento.preventDefault();
        esconderPista();

        if (window.ArenaAvisos) { window.ArenaAvisos.alternar(); return; }

        // Sin push (pantalla tactil sin el, o ventana estrecha en escritorio):
        // el boton es el del sonido.
        var s = window.ArenaSoundAlerts;
        if (!s) { return; }
        if (s.isEnabled() && s.isUnlocked()) { s.setEnabled(false); } else { s.unlock(); s.setEnabled(true); }
    }

    boton.addEventListener('click', alTocar);
    if (pista) { pista.addEventListener('click', alTocar); }

    document.addEventListener('arena:avisos-estado', function (evento) {
        pintar(evento.detail && evento.detail.estado);
    });

    // Sin controlador de push, el estado es el del sonido.
    document.addEventListener('arena:sonido-estado', function (evento) {
        if (window.ArenaAvisos) { return; }
        var d = evento.detail || {};
        pintar(d.enabled && d.unlocked ? 'activo' : 'inactivo');
    });

    // El controlador pudo pintar antes de que este script existiera.
    if (window.ArenaAvisos) {
        pintar(window.ArenaAvisos.estado());
    } else if (window.ArenaSoundAlerts) {
        pintar(window.ArenaSoundAlerts.isEnabled() && window.ArenaSoundAlerts.isUnlocked() ? 'activo' : 'inactivo');
    }
})();
</script>
@endauth
