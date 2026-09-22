@auth
@if(app(\App\Services\WebPushService::class)->configurado())
{{-- El controlador de los avisos. UNO solo.

     Antes habia tres manos tocando lo mismo -el conmutador de la barra, el
     boton flotante y el runtime de push- y se pisaban:

       - El de la barra hacia `setEnabled(!enabled)` mientras su etiqueta
         decia "Activar avisos". Con el ajuste encendido de fabrica, pulsarlo
         APAGABA las alertas, y con ellas el aviso por pagina que antes si
         llegaba con la pestaña de lado.
       - Cada interruptor se pintaba con su propia idea del estado: uno verde
         por el ajuste guardado y, 400 ms despues, otro rojo por la
         suscripcion. De ahi el "se pone verde y luego rojo".
       - Un toque lanzaba dos suscripciones en paralelo.

     Ahora todo pasa por aqui: un estado, una forma de cambiarlo y un pintor
     para todos los interruptores. --}}
<script>
(function () {
    'use strict';

    var CLAVE_SERVIDOR = @json(app(\App\Services\WebPushService::class)->clavePublica());
    var RUTAS = {
        suscribir: @json(route('avisos.suscribir')),
        desuscribir: @json(route('avisos.desuscribir')),
        fallo: @json(route('avisos.fallo')),
        probar: @json(route('avisos.probar')),
    };

    /* Lo que el navegador sabe hacer.
     *
     * Sin service worker o sin PushManager no hay aviso con la pagina cerrada.
     * Es el caso del iPhone en una pestaña de Safari: alli el push solo existe
     * con el sitio añadido a la pantalla de inicio. No se esconde el boton:
     * se explica que hacer. */
    var soportado = 'serviceWorker' in navigator
        && 'PushManager' in window
        && typeof Notification === 'function';

    var esIOS = /iPad|iPhone|iPod/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    var enStandalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;

    // La confirmacion del servidor, por direccion de suscripcion. Verde es
    // "el servidor la tiene", no "el navegador dice que esta suscrito": son
    // cosas distintas, y la diferencia es justo el fallo que no se veia.
    var CONFIRMADA = 'arena:avisos:confirmada';
    var CONFIRMADA_EN = 'arena:avisos:confirmada-en';
    var RECONFIRMAR_MS = 6 * 60 * 60 * 1000;

    var estadoActual = 'comprobando';
    var enVuelo = null;
    var ultimoFallo = null;
    var registro = null;

    function leer(clave) { try { return localStorage.getItem(clave); } catch (e) { return null; } }
    function escribir(clave, valor) { try { localStorage.setItem(clave, valor); } catch (e) {} }
    function borrar(clave) { try { localStorage.removeItem(clave); } catch (e) {} }

    function sonidos() { return window.ArenaSoundAlerts || null; }

    function toast(texto, tipo, ms) {
        if (typeof window.arenaToast === 'function') { window.arenaToast(texto, tipo || 'info', ms || 5000); }
    }

    function aBytes(base64url) {
        var base64 = (base64url + '='.repeat((4 - base64url.length % 4) % 4))
            .replace(/-/g, '+').replace(/_/g, '/');
        var crudo = atob(base64);
        var bytes = new Uint8Array(crudo.length);

        for (var i = 0; i < crudo.length; i++) { bytes[i] = crudo.charCodeAt(i); }

        return bytes;
    }

    /* ¿Esta suscripcion se hizo con NUESTRA clave?
     *
     * `true` si coincide, `false` solo si hay clave y es otra, y `null` si el
     * navegador no la expone. Antes el `null` contaba como "distinta", y en
     * los navegadores que no rellenan `options` eso tiraba la suscripcion y
     * la rehacia en cada carga. */
    function claveDeLaSuscripcion(suscripcion) {
        try {
            var actual = suscripcion.options && suscripcion.options.applicationServerKey;
            if (!actual) { return null; }

            var esperada = aBytes(CLAVE_SERVIDOR);
            var vista = new Uint8Array(actual);

            if (vista.length !== esperada.length) { return false; }

            for (var i = 0; i < vista.length; i++) {
                if (vista[i] !== esperada[i]) { return false; }
            }

            return true;
        } catch (e) {
            return null;
        }
    }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function enviar(ruta, cuerpo) {
        return fetch(ruta, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(cuerpo),
        });
    }

    /* ── El estado ─────────────────────────────────────────────────────── */

    async function obtenerRegistro() {
        if (registro) { return registro; }
        try { registro = await navigator.serviceWorker.getRegistration('/'); } catch (e) { registro = null; }
        return registro;
    }

    /**
     * Uno de: activo · inactivo · bloqueado · no-soportado.
     *
     * Activo exige TODO a la vez: alertas encendidas, permiso, suscripcion
     * con nuestra clave y el servidor habiendola guardado. Un verde que no
     * cumple eso es el verde que mentia.
     */
    async function calcularEstado() {
        if (!soportado) { return 'no-soportado'; }

        var s = sonidos();
        if (!s || !s.isEnabled()) { return 'inactivo'; }

        if (Notification.permission === 'denied') { return 'bloqueado'; }
        if (Notification.permission !== 'granted') { return 'inactivo'; }

        var reg = await obtenerRegistro();
        if (!reg) { return 'inactivo'; }

        var suscripcion = null;
        try { suscripcion = await reg.pushManager.getSubscription(); } catch (e) {}
        if (!suscripcion) { return 'inactivo'; }

        if (claveDeLaSuscripcion(suscripcion) === false) { return 'inactivo'; }
        if (leer(CONFIRMADA) !== suscripcion.endpoint) { return 'inactivo'; }

        return 'activo';
    }

    /* ── El pintor ─────────────────────────────────────────────────────── */

    var TEXTOS = {
        comprobando: { barra: 'Avisos', titulo: 'Comprobando los avisos…' },
        cargando: { barra: 'Activando…', titulo: 'Activando los avisos…' },
        activo: { barra: 'Avisos activos', titulo: 'Te avisaremos aunque cierres la pagina. Toca para silenciarlos.' },
        inactivo: { barra: 'Activar avisos', titulo: 'Toca para que te avisemos de los cruces, aunque cierres la pagina.' },
        bloqueado: { barra: 'Avisos bloqueados', titulo: 'El navegador tiene bloqueados los avisos de este sitio. Toca para ver como permitirlos.' },
        'no-soportado': { barra: 'Activar avisos', titulo: 'Toca para ver como recibir avisos en este dispositivo.' },
    };

    /* Todos los interruptores, del mismo estado y a la vez.
     *
     * Los de la barra conservan su marcado -punto y etiqueta- y los colores
     * de siempre; el flotante lleva su propia clase por estado. Lo que ya no
     * pasa es que cada uno decida por su cuenta. */
    function pintar(estado) {
        estadoActual = estado;
        var t = TEXTOS[estado] || TEXTOS.inactivo;
        var verde = estado === 'activo';
        var neutro = estado === 'comprobando' || estado === 'cargando';

        document.querySelectorAll('[data-arena-alert-toggle]').forEach(function (btn) {
            var etiqueta = btn.querySelector('[data-arena-alert-label]');
            var punto = btn.querySelector('[data-arena-alert-indicator]');

            if (etiqueta) { etiqueta.textContent = t.barra; }

            if (punto) {
                punto.classList.toggle('bg-emerald-400', verde);
                punto.classList.toggle('bg-rose-400', !verde && !neutro);
                punto.classList.toggle('bg-amber-300', neutro);
                punto.classList.toggle('animate-pulse', estado === 'cargando');
            }

            btn.classList.toggle('border-emerald-500/30', verde);
            btn.classList.toggle('text-emerald-200', verde);
            btn.classList.toggle('border-rose-500/30', !verde && !neutro);
            btn.classList.toggle('text-rose-200', !verde && !neutro);
            btn.setAttribute('aria-pressed', verde ? 'true' : 'false');
            btn.setAttribute('aria-busy', estado === 'cargando' ? 'true' : 'false');
            btn.setAttribute('title', t.titulo);
        });

        document.dispatchEvent(new CustomEvent('arena:avisos-estado', { detail: { estado: estado } }));
    }

    async function repintar() {
        if (enVuelo) { return estadoActual; }

        var estado = await calcularEstado();

        // Si mientras se calculaba empezo una activacion, manda ella.
        if (!enVuelo) { pintar(estado); }

        return estado;
    }

    /* ── La baliza ─────────────────────────────────────────────────────── */

    /* Cada fallo le llega al servidor.
     *
     * Hasta ahora, "no me llegan los avisos" solo se podia diagnosticar con
     * la consola del navegador abierta, que es justo lo que un jugador no va
     * a hacer. Con esto, `php artisan arena:push-check` enseña los ultimos
     * fallos con su motivo real. Va por `sendBeacon` cuando se puede: sale
     * aunque la pagina se este cerrando, y no depende del token CSRF, que es
     * uno de los sospechosos. */
    function informar(causa, error) {
        var datos = {
            causa: causa,
            detalle: error ? String(error && error.message || error).slice(0, 300) : null,
            permiso: typeof Notification === 'function' ? Notification.permission : 'sin-api',
            standalone: !!enStandalone,
        };

        try {
            var cuerpo = new Blob([JSON.stringify(datos)], { type: 'application/json' });
            if (navigator.sendBeacon && navigator.sendBeacon(RUTAS.fallo, cuerpo)) { return; }
        } catch (e) {}

        try {
            fetch(RUTAS.fallo, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(datos),
                keepalive: true,
            });
        } catch (e) {}
    }

    function fallo(causa, mensaje, error, avisar) {
        ultimoFallo = { causa: causa, mensaje: mensaje, detalle: error ? String(error && error.message || error) : null };

        try { console.warn('[arena/avisos] ' + causa + ': ' + mensaje, error || ''); } catch (e) {}

        informar(causa, error);

        if (avisar) { toast(mensaje, 'warning', 7000); }

        return { ok: false, causa: causa, mensaje: mensaje };
    }

    /* ── Activar ───────────────────────────────────────────────────────── */

    async function registrarWorker() {
        var existente = await obtenerRegistro();
        if (existente && existente.active) { return existente; }

        /* Antes de registrar, se mira que el fichero esta. `register()` con
           un 404 da un "failed to register" que no señala a nada, y este es
           EL fallo de despliegue: sw.js tiene que estar en la misma carpeta
           que index.php. */
        var prueba = await fetch('/sw.js', { cache: 'no-store' });
        if (!prueba.ok) {
            throw new Error('/sw.js responde HTTP ' + prueba.status);
        }

        registro = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        await navigator.serviceWorker.ready;

        return registro;
    }

    /* Suscribirse, o reutilizar la que haya si es nuestra. Rehacerla sin
       motivo cambia la direccion y deja la vieja muerta en el servidor. */
    async function suscripcionValida(reg) {
        var suscripcion = await reg.pushManager.getSubscription();

        if (suscripcion && claveDeLaSuscripcion(suscripcion) === false) {
            try { await suscripcion.unsubscribe(); } catch (e) {}
            suscripcion = null;
        }

        if (!suscripcion) {
            suscripcion = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: aBytes(CLAVE_SERVIDOR),
            });
        }

        return suscripcion;
    }

    async function guardarEnServidor(suscripcion) {
        var datos = suscripcion.toJSON();

        // Si este navegador tenia otra direccion antes, se dice cual: el
        // servidor la sustituye en vez de guardar las dos y mandar cada aviso
        // por duplicado hasta que la vieja caduque.
        var anterior = leer(CONFIRMADA);
        if (anterior && anterior !== suscripcion.endpoint) { datos.anterior = anterior; }

        var r = await enviar(RUTAS.suscribir, datos);

        if (!r.ok) {
            var err = new Error('POST /avisos/suscribir respondio HTTP ' + r.status);
            err.estado = r.status;
            throw err;
        }

        escribir(CONFIRMADA, suscripcion.endpoint);
        escribir(CONFIRMADA_EN, String(Date.now()));
    }

    /**
     * Encender los avisos. Idempotente: si ya hay una activacion en marcha,
     * devuelve esa misma en vez de lanzar otra.
     *
     * `permisoPedido` es la promesa de `requestPermission()` lanzada DENTRO
     * del gesto -antes de cualquier `await`-, porque Safari y Firefox la
     * rechazan si el gesto ya se ha "gastado".
     */
    function activar(opciones) {
        if (enVuelo) { return enVuelo; }

        opciones = opciones || {};
        var avisar = opciones.avisar !== false;
        var permisoPedido = opciones.permisoPedido || null;

        enVuelo = (async function () {
            pintar('cargando');

            var paso = 'permiso';

            try {
                var permiso = Notification.permission;

                if (permiso === 'default') {
                    permiso = permisoPedido ? await permisoPedido : await Notification.requestPermission();
                }

                if (permiso === 'denied') {
                    return fallo('permiso-denegado', instruccionesDesbloqueo(), null, avisar);
                }

                if (permiso !== 'granted') {
                    // Cerro el globo sin elegir. No es un error: no se informa.
                    ultimoFallo = { causa: 'permiso-sin-respuesta', mensaje: 'No elegiste nada.' };
                    if (avisar) { toast('Para avisarte, el navegador necesita tu permiso. Vuelve a tocar y elige "Permitir".', 'info', 6000); }
                    return { ok: false, causa: 'permiso-sin-respuesta' };
                }

                paso = 'worker';
                var reg = await registrarWorker();

                paso = 'suscripcion';
                var suscripcion = await suscripcionValida(reg);

                paso = 'servidor';
                await guardarEnServidor(suscripcion);

                // Las alertas de sonido van con el mismo interruptor: "avisame"
                // es una sola decision. Silencioso para no repetir globos.
                var s = sonidos();
                if (s) { await s.setEnabled(true, { silent: true }); }

                ultimoFallo = null;

                if (avisar) {
                    if (s && s.play) { s.play('generic'); }

                    // La prueba de ida y vuelta va aparte: puede tardar unos
                    // segundos y el boton no tiene por que quedarse en
                    // "activando" mientras. El servidor ya tiene la
                    // suscripcion; lo que dice la prueba llega en un aviso.
                    window.setTimeout(function () { avisoDePrueba(suscripcion); }, 0);
                }

                return { ok: true };
            } catch (e) {
                var mensajes = {
                    worker: 'No se pudo preparar el aviso en segundo plano. Recarga la pagina y vuelve a probar.',
                    suscripcion: esIOS && !enStandalone
                        ? instruccionesIOS()
                        : 'Tu navegador no deja recibir avisos aqui. Si estas en una ventana privada, abre el sitio en una normal.',
                    servidor: e && e.estado === 419
                        ? 'Tu sesion caduco. Recarga la pagina y vuelve a tocar.'
                        : 'No se pudo guardar en el servidor. Vuelve a tocar en un momento.',
                    permiso: 'No se pudo pedir el permiso de avisos.',
                };

                return fallo(paso, mensajes[paso] || 'No se pudieron activar los avisos.', e, avisar);
            }
        })();

        enVuelo.finally(function () {
            enVuelo = null;
            repintar();
        });

        return enVuelo;
    }

    /* La prueba de que funciona: un push DE VERDAD, de ida y vuelta.
     *
     * El servidor le manda a este dispositivo un push por el mismo camino que
     * usara con los cruces -servidor, Google/Mozilla/Apple, dispositivo,
     * worker- y el worker, al enseñarlo, nos lo confirma. Es la unica forma
     * de saber que llegara con la pagina cerrada: pintar la notificacion desde
     * la propia pagina solo demostraba que el sistema enseña notificaciones.
     *
     * Y si falla, se sabe en que tramo: el servidor dice que respondio el
     * servicio de push, y si respondio bien pero el worker no confirma, el
     * problema es del dispositivo. */
    var ESPERA_PRUEBA_MS = 15000;

    function esperarAcuse() {
        return new Promise(function (resolver) {
            var hecho = false;

            function alRecibir(evento) {
                if (evento.data && evento.data.tipo === 'arena:prueba-recibida') { terminar(true); }
            }

            function terminar(valor) {
                if (hecho) { return; }
                hecho = true;
                navigator.serviceWorker.removeEventListener('message', alRecibir);
                resolver(valor);
            }

            navigator.serviceWorker.addEventListener('message', alRecibir);
            window.setTimeout(function () { terminar(false); }, ESPERA_PRUEBA_MS);
        });
    }

    async function avisoDePrueba(suscripcion) {
        var acuse = esperarAcuse();
        var respuesta = null;

        try {
            var r = await enviar(RUTAS.probar, { endpoint: suscripcion.endpoint });
            respuesta = await r.json().catch(function () { return {}; });
            respuesta.http = r.status;
        } catch (e) {
            respuesta = { ok: false, cuerpo: String(e) };
        }

        if (!respuesta.ok) {
            informar('prueba-servidor', 'HTTP ' + (respuesta.estado || respuesta.http) + ' ' + (respuesta.cuerpo || ''));
            toast(respuesta.estado === 401 || respuesta.estado === 403
                ? 'El servicio de avisos rechaza la firma del servidor. Avisa al admin.'
                : 'No se pudo mandar el aviso de prueba (' + (respuesta.estado || respuesta.http || 'sin red') + '). Vuelve a tocar en un momento.',
                'warning', 8000);
            return { ok: false, tramo: 'servidor' };
        }

        if (await acuse) {
            toast('Recibido. Asi te llegaran los cruces, aunque cierres la pagina.', 'success', 5000);
            return { ok: true };
        }

        informar('prueba-no-llego', 'El servicio de push acepto (' + respuesta.estado + ') pero el dispositivo no confirmo en ' + (ESPERA_PRUEBA_MS / 1000) + 's');
        toast('El aviso salio del servidor pero no llego a este dispositivo. Revisa que el navegador pueda mostrar notificaciones en los ajustes del sistema.', 'warning', 9000);

        return { ok: false, tramo: 'dispositivo' };
    }

    function instruccionesDesbloqueo() {
        return esIOS
            ? 'Los avisos estan bloqueados. Ve a Ajustes → Notificaciones → Regnum Arena y activalos.'
            : 'Los avisos estan bloqueados para este sitio. Toca el candado junto a la direccion → Notificaciones → Permitir, y recarga.';
    }

    function instruccionesIOS() {
        return 'En iPhone los avisos solo llegan con el sitio en la pantalla de inicio: toca Compartir → "Añadir a pantalla de inicio" y abrelo desde alli.';
    }

    /* ── Desactivar ────────────────────────────────────────────────────── */

    async function desactivar() {
        pintar('cargando');

        try {
            var reg = await obtenerRegistro();
            var suscripcion = reg ? await reg.pushManager.getSubscription() : null;

            if (suscripcion) {
                try { await enviar(RUTAS.desuscribir, { endpoint: suscripcion.endpoint }); } catch (e) {}
                try { await suscripcion.unsubscribe(); } catch (e) {}
            }
        } catch (e) {}

        borrar(CONFIRMADA);
        borrar(CONFIRMADA_EN);

        var s = sonidos();
        if (s) { await s.setEnabled(false, { silent: true }); }

        toast('Avisos silenciados. Ya no te avisaremos con la pagina cerrada.', 'info', 3500);
        await repintar();
    }

    /* ── El toque ──────────────────────────────────────────────────────── */

    /* Lo que hace cualquier interruptor al pulsarse.
     *
     * Decide por el estado PINTADO, que es el que ve la persona, y lo hace de
     * forma sincrona: el permiso se pide antes de cualquier `await`, dentro
     * del gesto. Nunca conmuta a ciegas. */
    function alternar() {
        if (enVuelo) { return enVuelo; }

        if (estadoActual === 'activo') { return desactivar(); }

        if (estadoActual === 'no-soportado') {
            toast(esIOS ? instruccionesIOS() : 'Este navegador no puede recibir avisos con la pagina cerrada. Prueba con Chrome, Edge o Firefox.', 'info', 9000);
            informar('no-soportado', null);
            return Promise.resolve({ ok: false, causa: 'no-soportado' });
        }

        if (estadoActual === 'bloqueado') {
            toast(instruccionesDesbloqueo(), 'warning', 9000);
            return Promise.resolve({ ok: false, causa: 'permiso-denegado' });
        }

        // El audio se desbloquea tambien aqui, dentro del gesto.
        var s = sonidos();
        if (s && s.unlock) { try { s.unlock(); } catch (e) {} }

        var permisoPedido = Notification.permission === 'default'
            ? Notification.requestPermission()
            : null;

        return activar({ permisoPedido: permisoPedido, avisar: true });
    }

    /* ── Al cargar ─────────────────────────────────────────────────────── */

    /* Si ya estaba todo dado, se re-confirma con el servidor de vez en
     * cuando -por si limpio la suscripcion tras un 410- sin molestar a nadie.
     * Si faltaba solo la confirmacion (la suscripcion existe pero el
     * servidor no la tiene), se arregla aqui mismo, en silencio. */
    async function alArrancar() {
        pintar('comprobando');

        if (!soportado) { pintar('no-soportado'); return; }

        var s = sonidos();
        var puede = s && s.isEnabled() && Notification.permission === 'granted';

        if (puede) {
            var reg = await obtenerRegistro();
            var suscripcion = null;
            try { suscripcion = reg ? await reg.pushManager.getSubscription() : null; } catch (e) {}

            var confirmadaEn = parseInt(leer(CONFIRMADA_EN) || '0', 10);
            var hayQueConfirmar = suscripcion && (
                leer(CONFIRMADA) !== suscripcion.endpoint
                || Date.now() - confirmadaEn > RECONFIRMAR_MS
            );

            if (reg && suscripcion && hayQueConfirmar && claveDeLaSuscripcion(suscripcion) !== false) {
                try { await guardarEnServidor(suscripcion); } catch (e) { informar('reconfirmar', e); }
            }
        }

        await repintar();
    }

    window.ArenaAvisos = {
        alternar: alternar,
        activar: activar,
        desactivar: desactivar,
        repintar: repintar,
        estado: function () { return estadoActual; },
        soportado: soportado,
        diagnostico: async function () {
            var reg = await obtenerRegistro();
            var suscripcion = null;
            try { suscripcion = reg ? await reg.pushManager.getSubscription() : null; } catch (e) {}

            return {
                estado: await calcularEstado(),
                soportado: soportado,
                ios: esIOS,
                standalone: !!enStandalone,
                permiso: typeof Notification === 'function' ? Notification.permission : 'sin-api',
                alertasEncendidas: !!(sonidos() && sonidos().isEnabled()),
                workerActivo: !!(reg && reg.active),
                suscrito: !!suscripcion,
                claveCorrecta: suscripcion ? claveDeLaSuscripcion(suscripcion) : null,
                confirmadaEnServidor: !!(suscripcion && leer(CONFIRMADA) === suscripcion.endpoint),
                ultimoFallo: ultimoFallo,
            };
        },
    };

    // Compatibilidad con lo que ya se documento para la consola.
    window.ArenaPush = {
        diagnostico: window.ArenaAvisos.diagnostico,
        suscribir: function () { return activar({ avisar: true }).then(function (r) { return r.ok; }); },
        desuscribir: desactivar,
    };

    // Volver a la pestaña: por si se revoco el permiso desde los ajustes.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { repintar(); }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', alArrancar);
    } else {
        alArrancar();
    }
})();
</script>
@endif
@endauth
