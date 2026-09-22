@auth
@if(app(\App\Services\WebPushService::class)->configurado())
{{-- Los avisos que llegan con la pestaña cerrada.

     El sondeo de la pagina no sirve para esto: el navegador congela las
     pestañas que no estan delante -primero espacia los temporizadores a uno
     por minuto, luego los para- y el jugador se entera de las cosas cuando
     vuelve a mirar, que es justo cuando ya no le sirven. Esto registra un
     service worker y suscribe el navegador al push, que lo entrega el sistema
     operativo sin la pagina abierta.

     Solo para quien ha entrado, y solo si el servidor tiene las claves: sin
     ellas no hay a donde suscribirse y el bloque ni se pinta. --}}
<script>
(function () {
    'use strict';

    var CLAVE_SERVIDOR = @json(app(\App\Services\WebPushService::class)->clavePublica());
    var RUTA_SUSCRIBIR = @json(route('avisos.suscribir'));
    var RUTA_DESUSCRIBIR = @json(route('avisos.desuscribir'));

    // Un service worker necesita un origen seguro. En localhost el navegador
    // hace la vista gorda; en produccion, sin HTTPS, esto sencillamente no
    // existe y no hay nada que hacer aqui.
    var disponible = 'serviceWorker' in navigator
        && 'PushManager' in window
        && typeof Notification === 'function';

    if (!disponible) { return; }

    var registro = null;

    /** La clave publica viaja en base64url y `subscribe` la quiere en bytes. */
    function aBytes(base64url) {
        var base64 = (base64url + '='.repeat((4 - base64url.length % 4) % 4))
            .replace(/-/g, '+').replace(/_/g, '/');
        var crudo = atob(base64);
        var bytes = new Uint8Array(crudo.length);

        for (var i = 0; i < crudo.length; i++) { bytes[i] = crudo.charCodeAt(i); }

        return bytes;
    }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function contarAlServidor(ruta, cuerpo) {
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

    async function registrar() {
        if (registro) { return registro; }

        // Alcance la raiz: el fichero esta en la raiz publica justamente para
        // eso. Un worker registrado bajo /js/ solo controlaria /js/.
        registro = await navigator.serviceWorker.register('/sw.js', { scope: '/' });

        return registro;
    }

    /**
     * Apunta este navegador. Devuelve true si a partir de ahora los avisos
     * llegan aunque el sitio este cerrado.
     */
    async function suscribir() {
        if (Notification.permission !== 'granted') { return false; }

        try {
            var reg = await registrar();
            await navigator.serviceWorker.ready;

            var suscripcion = await reg.pushManager.getSubscription();

            /* Si la que hay se hizo con otra clave de servidor, no vale: el
               servicio de push rechaza los envios firmados con una clave que
               no es la de la suscripcion, y eso se manifiesta como "no me
               llegan los avisos" sin ningun error a la vista. Se cambia por
               una nueva. */
            if (suscripcion && !mismaClave(suscripcion)) {
                try { await suscripcion.unsubscribe(); } catch (e) {}
                suscripcion = null;
            }

            if (!suscripcion) {
                suscripcion = await reg.pushManager.subscribe({
                    // Obligatorio: es el compromiso de que cada toque acaba en
                    // un aviso visible. Sin esto el navegador no suscribe.
                    userVisibleOnly: true,
                    applicationServerKey: aBytes(CLAVE_SERVIDOR),
                });
            }

            var r = await contarAlServidor(RUTA_SUSCRIBIR, suscripcion.toJSON());

            return r.ok;
        } catch (e) {
            return false;
        }
    }

    function mismaClave(suscripcion) {
        try {
            var actual = suscripcion.options && suscripcion.options.applicationServerKey;
            if (!actual) { return false; }

            var esperada = aBytes(CLAVE_SERVIDOR);
            var vista = new Uint8Array(actual);

            if (vista.length !== esperada.length) { return false; }

            for (var i = 0; i < vista.length; i++) {
                if (vista[i] !== esperada[i]) { return false; }
            }

            return true;
        } catch (e) {
            return false;
        }
    }

    /** Baja este navegador. Al silenciar las alertas. */
    async function desuscribir() {
        try {
            var reg = await navigator.serviceWorker.getRegistration('/');
            if (!reg) { return; }

            var suscripcion = await reg.pushManager.getSubscription();
            if (!suscripcion) { return; }

            await contarAlServidor(RUTA_DESUSCRIBIR, { endpoint: suscripcion.endpoint });
            await suscripcion.unsubscribe();
        } catch (e) { /* si no se puede, el servidor lo tirara al fallar */ }
    }

    // El interruptor de alertas manda: encendido suscribe, silenciado baja.
    // Son la misma decision -"avisame"- y tener dos habria sido un ajuste mas
    // que nadie encuentra.
    document.addEventListener('arena:alertas', function (event) {
        if (event.detail && event.detail.enabled) { suscribir(); } else { desuscribir(); }
    });

    // Y al cargar, si ya estaban encendidas y con permiso: renueva la
    // suscripcion por si el servicio de push la roto o la limpio el navegador.
    function alArrancar() {
        if (!window.ArenaSoundAlerts || !window.ArenaSoundAlerts.isEnabled()) { return; }
        if (Notification.permission !== 'granted') { return; }

        suscribir();
    }

    window.ArenaPush = { suscribir: suscribir, desuscribir: desuscribir };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', alArrancar);
    } else {
        alArrancar();
    }
})();
</script>
@endif
@endauth
