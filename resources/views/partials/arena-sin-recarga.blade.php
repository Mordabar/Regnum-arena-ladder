{{-- Sin recargas, y sin perder el sitio.

     Dos cosas, las dos para que la pagina no te saque de donde estabas:

     1. Los formularios marcados con `data-sin-recarga` (aceptar o rechazar una
        invitacion, entrar o salir de la cola, armar o dejar una party...) se
        envian por detras. Los avisos salen como avisos flotantes y el panel se
        repinta en su sitio. Si la accion lleva a otra pagina, se navega alli.
        Si algo falla -sin red, sin respuesta-, el formulario se envia de la
        forma de siempre: nada se pierde.

     2. Cuando de verdad hay que recargar -una pagina de combate que cambia de
        estado, un formulario con archivos-, se guarda por donde ibas y se
        vuelve a ese punto. Solo si es la misma pagina y hace nada: un enlace a
        otra pagina empieza arriba, como debe. --}}
<script>
(function () {
    'use strict';

    var CLAVE = 'arena:lugar';
    var VIGENCIA_MS = 30000;

    function guardarLugar() {
        try {
            sessionStorage.setItem(CLAVE, JSON.stringify({ p: location.pathname, y: window.scrollY, t: Date.now() }));
        } catch (e) { /* Sin almacenamiento, se empieza arriba como siempre. */ }
    }

    window.arenaGuardarLugar = guardarLugar;
    window.arenaRecargar = function () { guardarLugar(); window.location.reload(); };

    /* ── Volver al sitio ── */
    (function restaurar() {
        var guardado = null;
        try {
            guardado = JSON.parse(sessionStorage.getItem(CLAVE) || 'null');
            sessionStorage.removeItem(CLAVE);
        } catch (e) { return; }

        if (!guardado || guardado.p !== location.pathname || Date.now() - guardado.t > VIGENCIA_MS || location.hash) { return; }

        var y = Number(guardado.y) || 0;
        if (y < 1) { return; }

        // El navegador a veces ya lo hace solo; si no, o si lo hace a medias,
        // se corrige. Tres intentos porque la pagina crece mientras carga
        // (tipografias, el visor 3D) y un solo salto temprano se quedaria corto.
        try { if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; } } catch (e) {}
        var ir = function () { window.scrollTo(0, y); };
        ir();
        document.addEventListener('DOMContentLoaded', ir);
        window.addEventListener('load', function () { ir(); window.setTimeout(ir, 250); });
    })();

    /* ── Cualquier formulario que se envia de la forma de siempre ── */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM' || (form.method || '').toLowerCase() !== 'post') { return; }
        if (event.defaultPrevented) { return; }
        // Cerrar sesion lleva a otra pagina: no hay sitio que conservar.
        if (/\/logout\/?$/.test(form.getAttribute('action') || '')) { return; }

        if (!form.hasAttribute('data-sin-recarga') || !puedeSinRecarga()) {
            // Se envia entero: al volver, a donde estabas.
            guardarLugar();
            return;
        }

        event.preventDefault();
        enviar(form, event.submitter);
    });

    // Solo donde hay un panel que repintar en su sitio.
    function puedeSinRecarga() {
        if (!('fetch' in window)) { return false; }
        // En el lobby se repinta el panel; en la pagina de un combate, la pagina.
        var enLobby = !!document.querySelector('.arena-console') && typeof window.arenaConsoleGo === 'function';
        var enPagina = !!document.getElementById('contenido') && typeof window.arenaRefrescarPagina === 'function';
        return enLobby || enPagina;
    }

    var textos = {
        reintenta: @json(__('Sin conexión. Inténtalo otra vez.')),
        caducado: @json(__('Esta página ha caducado. Recargando…')),
    };

    function avisar(texto, tipo) {
        if (typeof window.arenaToast === 'function') { window.arenaToast(texto, tipo || 'info', 4500); }
    }

    function enviar(form, boton) {
        if (form.dataset.enviando === '1') { return; }
        form.dataset.enviando = '1';

        var botones = form.querySelectorAll('button[type=submit], input[type=submit]');
        botones.forEach(function (b) { b.disabled = true; b.setAttribute('aria-busy', 'true'); });

        var libre = function () {
            delete form.dataset.enviando;
            botones.forEach(function (b) { b.disabled = false; b.removeAttribute('aria-busy'); });
        };

        // El sondeo no avisa de lo que el propio jugador acaba de hacer.
        window.arenaAccionPropia = Date.now();

        var corte = new AbortController();
        var plazo = window.setTimeout(function () { corte.abort(); }, 20000);

        var datos;
        try { datos = boton ? new FormData(form, boton) : new FormData(form); } catch (e) { datos = new FormData(form); }

        fetch(form.action, {
            method: 'POST',
            body: datos,
            credentials: 'same-origin',
            redirect: 'follow',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Arena-Sin-Recarga': '1',
                'X-Arena-Aqui': location.pathname,
            },
            signal: corte.signal,
        }).then(function (r) {
            window.clearTimeout(plazo);
            var tipo = r.headers.get('content-type') || '';
            if (r.ok && tipo.indexOf('json') !== -1) { return r.json(); }

            // La pagina caduco (sesion o token) o el recurso ya no existe: se
            // recarga en el mismo sitio, que es lo unico que lo arregla. Reenviar
            // el formulario daria el mismo error otra vez.
            if (r.status === 419 || r.status === 404 || r.status === 401) {
                return { caducado: true };
            }
            return { fallo: true };
        }).then(function (cuerpo) {
            if (cuerpo && cuerpo.caducado) {
                avisar(textos.caducado, 'info');
                libre();
                window.setTimeout(function () { window.arenaRecargar(); }, 900);
                return { manejado: true };
            }
            // Cualquier otra respuesta que no sea la esperada: se avisa y se
            // deja todo como esta. Reenviar entero subia los archivos otra vez.
            if (cuerpo && cuerpo.fallo) { avisar(textos.reintenta, 'error'); libre(); return { manejado: true }; }
            return cuerpo;
        }).then(function (cuerpo) {
            if (cuerpo && cuerpo.manejado) { return; }
            if (!cuerpo || !cuerpo.redirect) { avisar(textos.reintenta, 'error'); libre(); return; }

            // A otra pagina: se va. El aviso sigue en la sesion y lo pinta ella.
            if (!cuerpo.misma_ruta) { window.location.href = cuerpo.redirect; return; }

            (cuerpo.avisos || []).forEach(function (a) { avisar(a.texto, a.tipo); });

            // Una ventana (invitar a premade, reglas...) se cierra al terminar,
            // pero solo si salio bien: con un error se queda abierta para
            // corregirlo sin volver a escribir nada.
            var huboError = (cuerpo.avisos || []).some(function (a) { return a.tipo === 'error'; });
            var ventana = form.closest('[role=dialog]');
            if (ventana && !huboError && window.arenaModal && typeof window.arenaModal.close === 'function') {
                window.arenaModal.close(ventana.id);
            }

            // Con un error y una ventana abierta no se repinta nada: el panel
            // de debajo cambiaria mientras se esta leyendo el motivo.
            if (huboError && ventana) { libre(); return; }

            var repintar = document.querySelector('.arena-console') && typeof window.arenaConsoleGo === 'function'
                ? window.arenaConsoleGo(cuerpo.redirect, { reemplazar: true })
                : window.arenaRefrescarPagina();

            return repintar.then(function (hecho) {
                if (hecho === true || hecho === 'obsoleto') { libre(); return; }
                // No se pudo repintar el panel: se recarga, pero en el mismo sitio.
                window.arenaRecargar();
            });
        }).catch(function () {
            window.clearTimeout(plazo);
            // Sin red o sin respuesta no se envia de nuevo: la accion pudo llegar
            // a hacerse, y repetirla seria peor que pedir otro intento.
            avisar(textos.reintenta, 'error');
            libre();
        });
    }
})();
</script>
