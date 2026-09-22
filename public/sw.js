/*
 * El service worker de Regnum Arena Ladder.
 *
 * Existe por una sola razon: el navegador congela las pestañas que no estan
 * delante. Primero espacia los temporizadores a uno por minuto y a los pocos
 * minutos los para del todo, asi que el sondeo de la pagina no corre y el
 * jugador no se entera de nada hasta que vuelve a mirar. Esto vive fuera de la
 * pagina, lo despierta el sistema operativo y funciona con la pestaña cerrada.
 *
 * NO cachea nada. Un service worker que sirve ficheros desde su cache es la
 * forma mas rapida de dejar a la gente con una version vieja del sitio despues
 * de un despliegue por FTP. Aqui solo se reciben avisos.
 */

const VERSION = 'arena-avisos-3';

self.addEventListener('install', (event) => {
    // Sin esto, el worker nuevo se queda esperando a que se cierren todas las
    // pestañas del sitio. Un arreglo en este fichero tardaria dias en llegar.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

/*
 * El toque llega VACIO.
 *
 * Meter el texto dentro del push obliga a cifrarlo (aes128gcm y un acuerdo de
 * claves), que en un hosting compartido significa otra dependencia mas. Asi
 * que el servidor solo da el toque y aqui se pregunta que ha pasado.
 *
 * Y sale mejor: lo que se enseña es el estado de AHORA. Si el cruce caduco
 * mientras el aviso viajaba, el servidor ya no lo devuelve y no aparece una
 * notificacion diciendo "acepta ahora" sobre algo que ya no existe.
 */
self.addEventListener('push', (event) => {
    event.waitUntil(anunciar());
});

async function anunciar() {
    let avisos = [];
    let sinSesion = false;

    try {
        const r = await fetch('/avisos/pendientes', {
            credentials: 'include',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' },
        });

        if (r.ok) {
            const datos = await r.json();
            avisos = Array.isArray(datos.avisos) ? datos.avisos : [];
        } else if (r.status === 401 || r.status === 419) {
            sinSesion = true;
        }
    } catch (e) {
        // Sin red o sesion caducada. Se sigue: mejor un aviso generico que
        // ninguno, porque el navegador EXIGE enseñar algo despues de un push
        // y si no lo hacemos nos lo enseña el por su cuenta, con un texto que
        // no controlamos.
    }

    /*
     * Solo el MAS RECIENTE.
     *
     * Cada toque lo provoca una sola cosa -un cruce, un aviso del rival, un
     * reporte-, y esa cosa es la ultima que ha pasado. Antes se enseñaba la
     * lista entera, asi que cada "voy de camino" del rival volvia a sacar
     * tambien el "¡A pelear!" del combate: dos globos por un mensaje.
     */
    avisos.sort((a, b) => String(b.en || '').localeCompare(String(a.en || '')));

    const aviso = avisos[0] || (sinSesion
        ? {
            tag: 'arena',
            titulo: 'Regnum Arena Ladder',
            cuerpo: 'Tienes novedades en la arena. Tu sesion caduco: entra para verlas.',
            url: '/',
        }
        : {
            tag: 'arena',
            titulo: 'Regnum Arena Ladder',
            cuerpo: 'Hay novedades en tu arena.',
            url: '/lobby',
        });

    /*
     * Un cruce que ya no esta vigente no puede seguir en pantalla diciendo
     * "acepta ahora": su aviso era fijo, asi que no se iba solo. Se cierran
     * los de cruces que el servidor ya no devuelve.
     */
    try {
        const vigentes = new Set(avisos.map((a) => a.tag));
        const puestas = await self.registration.getNotifications();
        puestas
            .filter((n) => /^cruce:/.test(n.tag || '') && !vigentes.has(n.tag) && n.tag !== aviso.tag)
            .forEach((n) => n.close());
    } catch (e) { /* no es critico */ }

    await self.registration.showNotification(aviso.titulo || 'Regnum Arena Ladder', {
        body: aviso.cuerpo || '',
        // La etiqueta agrupa: dos toques del mismo cruce se sustituyen en vez
        // de apilar dos globos iguales. Y es la misma que pone la pagina, asi
        // que si llegan los dos, sale uno.
        tag: aviso.tag || 'arena',
        renotify: true,
        icon: '/images/icono-192.png',
        // Sin `badge`: Android lo pinta como silueta y un icono opaco sale
        // como un cuadrado blanco. Sin el, pone la campana del navegador.
        data: { url: aviso.url || '/lobby' },
        // El cruce se queda hasta que se toca: hay dos minutos para aceptar y
        // no puede irse solo a los cinco segundos. El resto se va como
        // cualquier notificacion.
        requireInteraction: !!aviso.fijo,
        vibrate: [90, 40, 90],
    });

    /*
     * El acuse de la prueba.
     *
     * Al activar los avisos, el servidor manda un push de verdad a este
     * dispositivo. Si llega hasta aqui, el camino entero funciona -servidor,
     * servicio de push, dispositivo, worker- y se lo decimos a la pagina, que
     * esta esperando para poner el boton en verde. Si no llega, la pagina lo
     * sabe por el silencio y dice en que tramo se quedo.
     */
    if (aviso.prueba) {
        const ventanas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        ventanas.forEach((v) => v.postMessage({ tipo: 'arena:prueba-recibida' }));
    }
}

/*
 * Tocar el aviso lleva a la arena.
 *
 * Si ya hay una pestaña del sitio abierta se le da el foco y se la manda al
 * sitio, en vez de abrir una segunda: dos pestañas del lobby sondeando a la
 * vez son dos sesiones peleandose por el mismo estado.
 */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const destino = (event.notification.data && event.notification.data.url) || '/lobby';

    event.waitUntil((async () => {
        const abiertas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        for (const cliente of abiertas) {
            if (new URL(cliente.url).origin === self.location.origin) {
                await cliente.focus();

                if ('navigate' in cliente) {
                    try { await cliente.navigate(destino); } catch (e) { /* da igual: ya tiene el foco */ }
                }

                return;
            }
        }

        await self.clients.openWindow(destino);
    })());
});

/*
 * El servicio de push puede rotar la direccion de una suscripcion por su
 * cuenta. Cuando pasa, hay que volver a apuntarse o ese navegador deja de
 * recibir avisos en silencio, que es la peor forma de romperse.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil((async () => {
        const vieja = event.oldSubscription;
        let nueva = event.newSubscription;

        if (!nueva) {
            const opciones = vieja ? vieja.options : null;
            if (!opciones || !opciones.applicationServerKey) { return; }

            nueva = await self.registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: opciones.applicationServerKey,
            });
        }

        /*
         * Puerta aparte de la de la pagina.
         *
         * Aqui no hay documento, asi que no hay token CSRF que mandar. En vez
         * de abrir la puerta normal sin token, esta pide la direccion VIEJA:
         * solo la sabe el navegador que ya estaba suscrito, asi que sirve de
         * credencial y lo unico que permite es mover una suscripcion que ya
         * existia a su direccion nueva.
         */
        await fetch('/avisos/resuscribir', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                viejo: vieja ? vieja.endpoint : null,
                nuevo: nueva.endpoint,
                keys: nueva.toJSON().keys || null,
            }),
        });
    })());
});
