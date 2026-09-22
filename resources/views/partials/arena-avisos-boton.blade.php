@auth
@if(app(\App\Services\WebPushService::class)->configurado())
{{-- El interruptor de avisos, flotante y siempre a la vista.

     Esto sale de un fallo real: el interruptor decia "Alertas activas" desde
     la primera visita -porque el ajuste viene encendido de fabrica- cuando en
     realidad el navegador no habia dado permiso todavia y no habia ninguna
     suscripcion. El boton mentia, y para arreglarlo habia que apagarlo y
     volver a encenderlo, que es justo lo que nadie va a adivinar.

     Dos decisiones, las dos por lo mismo:

       - VERDE solo cuando de verdad va a llegar un aviso: permiso concedido,
         suscripcion viva y alertas encendidas. Rojo en cualquier otro caso.
         Un indicador que se pone verde por un ajuste guardado en el navegador
         no informa de nada.
       - Un solo toque lo arregla. Si esta en rojo, el boton pide permiso, se
         suscribe y enciende las alertas, sea cual sea el estado del que
         venga: no hay que apagar nada primero.

     Va flotante en movil porque ahi el menu esta detras de una hamburguesa, y
     un interruptor que hay que ir a buscar es un interruptor que no se toca.
     En escritorio se queda el de la barra, que ya esta siempre visible. --}}
<div class="arena-avisos" data-avisos hidden>
    <p class="arena-avisos-pista" data-avisos-pista hidden>
        Toca aqui para que te avisemos de los cruces
    </p>

    <button type="button"
            class="arena-avisos-btn"
            data-avisos-btn
            aria-live="polite">
        <span class="arena-avisos-punto" aria-hidden="true"></span>
        <svg class="arena-avisos-campana" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 2a6 6 0 0 0-6 6v3.6l-1.7 3.2A1 1 0 0 0 5.2 16h13.6a1 1 0 0 0 .9-1.2L18 11.6V8a6 6 0 0 0-6-6zM9.5 17.5a2.5 2.5 0 0 0 5 0z"/>
        </svg>
        <span class="sr-only" data-avisos-texto>Avisos</span>
    </button>
</div>

<style>
    /* Abajo a la derecha, por encima de la barra del sistema en los moviles
       que la tienen (`safe-area-inset`) y por encima del contenido. */
    .arena-avisos {
        position: fixed;
        right: 14px;
        bottom: calc(14px + env(safe-area-inset-bottom, 0px));
        z-index: 60;
        display: flex;
        align-items: center;
        gap: 8px;
        pointer-events: none;
    }
    .arena-avisos[hidden] { display: none; }

    /* En escritorio no hace falta: el de la barra de arriba esta siempre a la
       vista. Dos interruptores de lo mismo en la misma pantalla solo hacen
       dudar de si hacen cosas distintas. */
    @media (min-width: 1024px) {
        .arena-avisos { display: none; }
    }

    /* Y en movil se va del menu: estaba detras de la hamburguesa, que es
       donde nadie lo encuentra, y tener el mismo interruptor dos veces en la
       misma pantalla solo hace dudar de si hacen cosas distintas. */
    @media (max-width: 1023px) {
        .arena-mobile-menu [data-arena-alert-toggle] { display: none; }
    }

    .arena-avisos-btn {
        pointer-events: auto;
        position: relative;
        display: grid;
        place-items: center;
        /* 46px: por debajo de 44 los dedos fallan, y por encima empieza a
           tapar contenido en una pantalla de movil. */
        width: 46px;
        height: 46px;
        border-radius: 50%;
        border: 1px solid rgba(255, 120, 120, 0.5);
        background: linear-gradient(180deg, rgba(48, 18, 18, 0.96), rgba(26, 10, 10, 0.97));
        color: #ffb4b4;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45);
        cursor: pointer;
        transition: color .2s ease, border-color .2s ease, background .2s ease, transform .15s ease;
    }
    .arena-avisos-btn:active { transform: scale(.94); }
    .arena-avisos-campana { width: 21px; height: 21px; }

    /* El punto de estado, arriba a la derecha de la campana. Rojo apagadas,
       verde activadas: es lo unico que hay que mirar. */
    .arena-avisos-punto {
        position: absolute;
        top: 6px;
        right: 6px;
        width: 9px;
        height: 9px;
        border-radius: 50%;
        background: #ff6b6b;
        box-shadow: 0 0 0 2px rgba(26, 10, 10, 0.95);
    }

    .arena-avisos.is-activo .arena-avisos-btn {
        border-color: rgba(124, 201, 138, 0.55);
        background: linear-gradient(180deg, rgba(18, 42, 24, 0.96), rgba(9, 22, 13, 0.97));
        color: #9fe0ad;
    }
    .arena-avisos.is-activo .arena-avisos-punto {
        background: #4ade80;
        box-shadow: 0 0 0 2px rgba(9, 22, 13, 0.95);
    }

    /* Apagadas y sin tocar todavia: late despacio. Es lo que hace que se
       repare en el, sin llegar a ser un parpadeo molesto. */
    .arena-avisos.is-apagado .arena-avisos-btn {
        animation: arenaAvisosLatido 2.4s ease-in-out infinite;
    }
    @keyframes arenaAvisosLatido {
        0%, 100% { box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45), 0 0 0 0 rgba(255, 107, 107, 0.4); }
        50% { box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45), 0 0 0 9px rgba(255, 107, 107, 0); }
    }

    /* La pista de la primera visita. A la izquierda del boton para no salirse
       de la pantalla, y se va sola. */
    .arena-avisos-pista {
        pointer-events: auto;
        margin: 0;
        max-width: min(62vw, 230px);
        padding: 8px 12px;
        border-radius: 12px;
        border: 1px solid rgba(217, 177, 92, 0.32);
        background: linear-gradient(180deg, rgba(52, 37, 21, 0.97), rgba(24, 16, 10, 0.98));
        box-shadow: 0 8px 22px rgba(0, 0, 0, 0.45);
        font-size: 12.5px;
        line-height: 1.35;
        color: var(--arena-sand);
        animation: arenaAvisosEntra .3s ease-out both;
    }
    .arena-avisos-pista[hidden] { display: none; }
    .arena-avisos-pista.is-saliendo { animation: arenaAvisosSale .3s ease-in both; }

    @keyframes arenaAvisosEntra {
        from { opacity: 0; transform: translateX(10px); }
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes arenaAvisosSale {
        from { opacity: 1; transform: translateX(0); }
        to { opacity: 0; transform: translateX(10px); }
    }

    @media (prefers-reduced-motion: reduce) {
        .arena-avisos.is-apagado .arena-avisos-btn { animation: none; }
        .arena-avisos-pista, .arena-avisos-pista.is-saliendo { animation: none; }
    }
</style>

<script>
(function () {
    'use strict';

    var caja = document.querySelector('[data-avisos]');
    if (!caja) { return; }

    var boton = caja.querySelector('[data-avisos-btn]');
    var pista = caja.querySelector('[data-avisos-pista]');
    var texto = caja.querySelector('[data-avisos-texto]');

    /* Cuanto se queda la pista de la primera visita. Diez segundos: lo que
       tarda alguien en leerla sin que se convierta en un cartel fijo. */
    var PISTA_MS = 10000;
    var PISTA_VISTA = 'arena:avisos:pista-vista';

    var timerPista = null;

    function guardado(clave) {
        try { return localStorage.getItem(clave); } catch (e) { return null; }
    }

    function guardar(clave, valor) {
        try { localStorage.setItem(clave, valor); } catch (e) {}
    }

    /* Activo de verdad: las tres cosas a la vez.
     *
     * El ajuste por si solo no vale -viene encendido de fabrica- y el permiso
     * por si solo tampoco: se puede tener permiso y haber perdido la
     * suscripcion porque el navegador limpio los datos del sitio. */
    async function activoDeVerdad() {
        if (!window.ArenaSoundAlerts || !window.ArenaSoundAlerts.isEnabled()) { return false; }
        if (typeof Notification !== 'function' || Notification.permission !== 'granted') { return false; }
        if (!window.ArenaPush) { return false; }

        var estado = await window.ArenaPush.diagnostico();

        return !!(estado.suscrito && estado.claveCorrecta);
    }

    async function pintar() {
        var activo = await activoDeVerdad();

        caja.hidden = false;
        caja.classList.toggle('is-activo', activo);
        caja.classList.toggle('is-apagado', !activo);

        boton.setAttribute('aria-pressed', activo ? 'true' : 'false');
        boton.setAttribute('title', activo ? 'Avisos activados. Toca para silenciarlos.' : 'Toca para que te avisemos de los cruces.');

        if (texto) { texto.textContent = activo ? 'Avisos activados' : 'Avisos apagados, toca para activarlos'; }

        pintarLosDeLaBarra(activo);

        // La pista solo mientras esten apagados, y una sola vez en la vida de
        // este navegador: pasada esa vez, el boton rojo ya lo dice.
        if (!activo && guardado(PISTA_VISTA) !== '1') {
            enseñarPista();
        } else {
            esconderPista();
        }
    }

    /* El interruptor de la barra tenia la misma mentira: decia "Alertas
       activas" mirando solo el ajuste guardado. Se corrige aqui, que es donde
       se sabe la verdad, y se hace al final para que no lo pise el repintado
       del propio interruptor. */
    function pintarLosDeLaBarra(activo) {
        document.querySelectorAll('[data-arena-alert-toggle]').forEach(function (btn) {
            var etiqueta = btn.querySelector('[data-arena-alert-label]');
            var punto = btn.querySelector('[data-arena-alert-indicator]');

            if (etiqueta) { etiqueta.textContent = activo ? 'Avisos activos' : 'Activar avisos'; }

            if (punto) {
                punto.classList.toggle('bg-emerald-400', activo);
                punto.classList.toggle('bg-rose-400', !activo);
            }

            btn.classList.toggle('border-emerald-500/30', activo);
            btn.classList.toggle('text-emerald-200', activo);
            btn.classList.toggle('border-rose-500/30', !activo);
            btn.classList.toggle('text-rose-200', !activo);
        });
    }

    function enseñarPista() {
        if (!pista || timerPista) { return; }

        pista.hidden = false;
        guardar(PISTA_VISTA, '1');

        timerPista = window.setTimeout(function () {
            pista.classList.add('is-saliendo');
            window.setTimeout(function () {
                pista.hidden = true;
                pista.classList.remove('is-saliendo');
                timerPista = null;
            }, 300);
        }, PISTA_MS);
    }

    function esconderPista() {
        if (!pista) { return; }

        window.clearTimeout(timerPista);
        timerPista = null;
        pista.hidden = true;
        pista.classList.remove('is-saliendo');
    }

    /* Un toque y listo.
     *
     * Aqui NO se usa el conmutador de siempre. Ese hace `setEnabled(!enabled)`
     * y en el caso que nos ocupa -ajuste encendido pero sin permiso ni
     * suscripcion- lo que haria es APAGARLO, que es exactamente el bucle del
     * que hay que salir. Estando en rojo, esto siempre enciende. */
    async function alPulsar() {
        esconderPista();

        var activo = await activoDeVerdad();

        if (activo) {
            await window.ArenaSoundAlerts.setEnabled(false);
            await pintar();

            return;
        }

        // `setEnabled(true)` pide el permiso y avisa al runtime de push, que
        // se suscribe. Se llama aunque el ajuste ya estuviera en "encendido":
        // es la unica forma de rehacer lo que falte.
        await window.ArenaSoundAlerts.setEnabled(true);

        // Y por si el ajuste ya estaba encendido y `setEnabled` no disparo
        // nada nuevo: se pide la suscripcion a mano, contando los fallos.
        if (window.ArenaPush) { await window.ArenaPush.suscribir(true); }

        await pintar();
    }

    boton.addEventListener('click', function (e) {
        e.preventDefault();
        alPulsar();
    });

    if (pista) {
        pista.addEventListener('click', function () {
            esconderPista();
            alPulsar();
        });
    }

    // El interruptor de la barra tambien cambia el estado: los dos tienen que
    // decir lo mismo.
    document.addEventListener('arena:alertas', function () {
        window.setTimeout(pintar, 400);
    });

    // Al volver a la pestaña, por si se revoco el permiso desde los ajustes
    // del navegador mientras tanto.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { pintar(); }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', pintar);
    } else {
        pintar();
    }
})();
</script>
@endif
@endauth
