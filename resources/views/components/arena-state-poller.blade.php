{{--
    x-arena-state-poller
    Encapsulates the shared state polling used by arena/hub y matches/show_v3.

    Props:
      :active   (bool)   - Whether polling should be initialized. Renders nothing when false.
      endpoint  (string) - The URL to poll. Defaults to route('queue.state-poll').
      interval  (int)    - Base poll interval in milliseconds. Defaults to 3000.

    The poller slows down after repeated stable hashes and uses a slower cadence while the tab is hidden.
--}}
@props([
    'active' => false,
    'endpoint' => null,
    'interval' => 3000,
    'refreshUrl' => null,
])

@if($active)
<script>
(function () {
    const _endpoint = @json($endpoint ?? route('queue.state-poll'));
    const _baseInterval = {{ (int) $interval }};
    const _slowInterval = 5000;
    const _idleInterval = 8000;
    const _hiddenInterval = 12000;

    /* El reloj del sondeo va en un worker.

       Con la pestaña en reposo, Chrome espacia los temporizadores de la
       pagina hasta uno por minuto (y a veces mas): el cruce llegaba, pero la
       pagina se enteraba un minuto despues, cuando ya no servia. Los
       temporizadores de un worker no se frenan asi, y su mensaje despierta a
       la pagina en el acto para sonar. Sin worker, el de siempre. */
    const _reloj = (() => {
        try {
            const codigo = 'var t={};onmessage=function(e){var d=e.data;if(d.parar){clearTimeout(t[d.id]);delete t[d.id];return;}t[d.id]=setTimeout(function(){delete t[d.id];postMessage(d.id);},d.ms);};';
            const w = new Worker(URL.createObjectURL(new Blob([codigo], { type: 'text/javascript' })));
            const pendientes = new Map();
            let siguiente = 1;
            w.onmessage = (e) => { const fn = pendientes.get(e.data); pendientes.delete(e.data); if (fn) fn(); };
            return {
                poner: (fn, ms) => { const id = siguiente++; pendientes.set(id, fn); w.postMessage({ id, ms }); return id; },
                quitar: (id) => { pendientes.delete(id); w.postMessage({ id, parar: true }); },
            };
        } catch (_) {
            return { poner: (fn, ms) => window.setTimeout(fn, ms), quitar: (id) => window.clearTimeout(id) };
        }
    })();
    const _slowAfterStablePolls = 5;
    const _idleAfterStablePolls = 10;
    const _stateStorageKey = 'arena:poll-state:' + window.location.pathname;
    const _reloadDelayMs = 650;
    // Con una direccion aqui, un cambio de estado trae solo el panel y lo cambia
    // en su sitio. Sin ella (o si la peticion falla) se recarga la pagina, que
    // es lo que se hacia siempre.
    const _refreshUrl = @json($refreshUrl);
    // Cuanto se espera a que alguien deje de teclear antes de repintar el panel.
    const _typingGraceMs = 4000;

    function initializeStatePolling() {
        let lastHash = null;
        let lastState = readStoredState();
        let isPolling = false;
        let stablePolls = 0;
        let currentInterval = _baseInterval;
        let timerId = null;
        let isReloading = false;
        let isRefreshing = false;
        // Ultima tecla pulsada dentro del panel: solo eso cuenta como "esta
        // escribiendo". Tener el foco en un campo no basta (ver isBusy).
        let lastTypedAt = 0;
        document.addEventListener('input', (e) => {
            if (e.target && e.target.closest && e.target.closest('.arena-console, #contenido')) { lastTypedAt = Date.now(); }
        }, true);

        function readStoredState() {
            try {
                const raw = window.sessionStorage.getItem(_stateStorageKey);
                return raw ? JSON.parse(raw) : null;
            } catch (_) {
                return null;
            }
        }

        function storeState(state) {
            try {
                window.sessionStorage.setItem(_stateStorageKey, JSON.stringify(state ?? null));
            } catch (_) {
                // Ignore storage failures silently.
            }
        }

        const resolveInterval = () => {
            if (stablePolls >= _idleAfterStablePolls) {
                return _idleInterval;
            }

            if (stablePolls >= _slowAfterStablePolls) {
                return _slowInterval;
            }

            return _baseInterval;
        };

        const resetCadence = () => {
            stablePolls = 0;
            currentInterval = _baseInterval;
        };

        const clearScheduledPoll = () => {
            if (timerId !== null) {
                _reloj.quitar(timerId);
                timerId = null;
            }
        };

        const scheduleNextPoll = (delay = currentInterval) => {
            clearScheduledPoll();
            const nextDelay = document.visibilityState === 'hidden' ? _hiddenInterval : delay;

            timerId = _reloj.poner(() => {
                pollNow();
            }, nextDelay);
        };

        const toIdSet = (items) => new Set((items ?? []).map((item) => String(item.id)));

        // Mientras el jugador tiene algo en marcha —cola, party, invitacion o
        // match— el sondeo NO frena. El frenado progresivo existe para no
        // castigar al servidor con peticiones inutiles, pero aplicarlo aqui era
        // contraproducente: la espera en cola es justo el momento en que puede
        // aparecer un cruce en cualquier instante, y llegaba a tardar 8 segundos
        // en enterarse. Sin nada en curso sigue frenando como antes.
        const hasLiveActivity = (state) => {
            if (!state) {
                return false;
            }

            return (state.queues ?? []).length > 0
                || (state.pending_invites ?? []).length > 0
                || !!state.party
                || !!state.current_match;
        };

        // El contador de gente en cola llega fuera del hash, asi que se pinta en
        // vivo sin recargar la pagina.
        const paintQueuePulse = (pulse) => {
            if (!pulse) {
                return;
            }

            (pulse.realms ?? []).forEach((realm) => {
                const node = document.querySelector('[data-queue-pulse-realm="' + realm.key + '"]');
                if (node) {
                    node.textContent = realm.waiting;
                }
            });

            const totalNode = document.querySelector('[data-queue-pulse-total]');
            if (totalNode) {
                totalNode.textContent = pulse.total;
            }

            const hintNode = document.querySelector('[data-queue-pulse-hint]');
            if (hintNode) {
                // Tambien cuando viene vacia. Sin el `else`, en cuanto dejaba de
                // faltar gente la pista se quedaba pegada: "faltan 2 de un reino
                // rival" con los contadores de al lado marcando 3 y 3, que es
                // justo la contradiccion que se queria quitar. El pulso se pinta
                // fuera del hash, asi que nada mas iba a refrescar ese nodo.
                hintNode.textContent = pulse.hint || '';
                hintNode.hidden = !pulse.hint;
            }
        };

        const detectAlertEvents = (previousState, nextState) => {
            const events = [];
            const previousInvites = toIdSet(previousState?.pending_invites);
            const nextInvites = toIdSet(nextState?.pending_invites);
            const previousMatch = previousState?.current_match ?? null;
            const nextMatch = nextState?.current_match ?? null;
            const previousParty = previousState?.party ?? null;
            const nextParty = nextState?.party ?? null;

            if ([...nextInvites].some((inviteId) => !previousInvites.has(inviteId))) {
                events.push({
                    type: 'party_invite',
                    key: 'party-invite:' + [...nextInvites].filter((inviteId) => !previousInvites.has(inviteId)).join(','),
                    message: 'Nueva invitacion de party. Revisa la arena.',
                });
            }

            if (
                nextParty
                && nextParty.status === 'ready'
                && previousParty
                && previousParty.id === nextParty.id
                && previousParty.status !== 'ready'
            ) {
                events.push({
                    type: 'party_ready',
                    key: 'party-ready:' + nextParty.id,
                    message: 'Tu party ya esta lista para entrar a cola.',
                });
            }

            if (
                nextMatch
                && nextMatch.status === 'pending_acceptance'
                && (!previousMatch || previousMatch.id !== nextMatch.id || previousMatch.status !== 'pending_acceptance')
            ) {
                events.push({
                    type: 'match_found',
                    key: 'match-found:' + nextMatch.id,
                    message: 'Combate encontrado. Acepta ahora.',
                });
            }

            if (
                previousMatch
                && nextMatch
                && previousMatch.id === nextMatch.id
                && previousMatch.status === 'pending_acceptance'
                && nextMatch.status === 'in_progress'
            ) {
                events.push({
                    type: 'hunt_start',
                    key: 'hunt-start:' + nextMatch.id,
                    message: 'Todos aceptaron. La caceria ya comenzo.',
                });
            }

            if (
                previousMatch
                && nextMatch
                && previousMatch.id === nextMatch.id
                && previousMatch.report_status !== 'pending_confirmation'
                && nextMatch.report_status === 'pending_confirmation'
            ) {
                events.push({
                    type: 'report_submitted',
                    key: 'report-pending:' + nextMatch.id,
                    message: 'Hay un reporte pendiente de revision en tu match.',
                });
            }

            if (
                nextMatch
                && nextMatch.status === 'completed'
                && (!previousMatch || previousMatch.id !== nextMatch.id || previousMatch.status !== 'completed')
            ) {
                events.push({
                    type: 'report_confirmed',
                    key: 'report-confirmed:' + nextMatch.id,
                    message: 'El resultado del match fue confirmado.',
                });
            }

            return events;
        };

        const emitAlerts = (events) => {
            // Lo que el propio jugador acaba de hacer no se le avisa: lo ve en
            // el aviso de la accion. Solo se callan los genericos; un cruce o
            // una invitacion siguen sonando.
            if (Date.now() - (window.arenaAccionPropia || 0) < 8000) {
                events = events.filter((e) => e.type !== 'generic' && e.type !== 'report_confirmed');
            }
            if (!events.length || !window.ArenaSoundAlerts || typeof window.ArenaSoundAlerts.notify !== 'function') {
                return;
            }

            events.forEach((event, index) => {
                window.setTimeout(() => {
                    window.ArenaSoundAlerts.notify(event.type, event.message, {
                        key: event.key,
                    });
                }, index * 180);
            });
        };

        const queueReload = () => {
            if (isReloading) {
                return;
            }

            isReloading = true;
            clearScheduledPoll();

            // Deja dicho que esta recarga la provoco un cambio de estado, no el
            // jugador. Es lo que distingue "estabas aqui y se te cayo el
            // combate" de "has venido a consultarlo", que no se puede saber por
            // la hora: un enfrentamiento recien anulado tambien se consulta.
            try { sessionStorage.setItem('arena:live-reload', '1'); } catch (e) {}

            window.setTimeout(() => {
                (window.arenaRecargar || (() => window.location.reload()))();
            }, _reloadDelayMs);
        };

        // Quita del documento los modales que vienen otra vez en el trozo nuevo.
        // Sin esto quedarian dos con el mismo id y abrir uno sacaria el viejo,
        // con el estado de hace un minuto.
        const swapConsoleModals = (markup) => {
            const host = document.querySelector('[data-console-modals]');
            if (!host) { return; }

            const holder = document.createElement('div');
            holder.innerHTML = markup ?? '';

            holder.querySelectorAll('[id]').forEach((node) => {
                document.querySelectorAll('[id="' + node.id + '"]').forEach((old) => {
                    if (!host.contains(old)) { old.remove(); }
                });
            });

            host.innerHTML = '';
            while (holder.firstChild) {
                host.appendChild(holder.firstChild);
            }
        };

        // Lo escrito sobrevive al repintado: cada campo de texto o desplegable
        // se busca en el panel nuevo por su formulario y su nombre y, si sigue
        // ahí, recupera su valor y el foco. Si el campo ya no existe (el rival
        // reporto antes y el formulario propio sobra) no hay nada que guardar.
        const claveCampo = (el) => {
            if (!el.name) { return null; }
            const form = el.form ? (el.form.getAttribute('action') || '') : '';
            return form + '|' + el.name;
        };

        // El foco se recuerda por su sitio en el arbol y se devuelve al
        // elemento equivalente cuando el contenido se repinta.
        const rutaFocoDe = (host) => {
            const activo = document.activeElement;
            if (!activo || !host.contains(activo) || activo === host) { return null; }
            const ruta = [];
            for (let n = activo; n && n !== host; n = n.parentElement) {
                ruta.unshift(Array.prototype.indexOf.call(n.parentElement.children, n));
            }
            return ruta;
        };

        const devolverFoco = (host, ruta) => {
            if (!ruta) { return; }
            let n = host;
            for (const i of ruta) { n = n && n.children[i]; }
            if (n && typeof n.focus === 'function' && document.activeElement !== n) {
                try { n.focus({ preventScroll: true }); } catch (e) {}
            }
        };

        // Cuando la pantalla cambia sola, a un lector de pantalla no le llega
        // nada: el cambio es silencioso. Lo que lleva `data-anuncio` -el titulo
        // y la explicacion del lobby, el estado del combate- se dice en la zona
        // de aviso, y solo si cambio.
        const leerAnuncio = () => Array.from(document.querySelectorAll('[data-anuncio]'))
            .map((n) => n.textContent.replace(/\s+/g, ' ').trim())
            .filter(Boolean)
            .join(' — ');
        let ultimoAnuncio = null;
        const anunciarEstado = () => {
            const texto = leerAnuncio();
            if (ultimoAnuncio === null) { ultimoAnuncio = texto; return; }
            if (!texto || texto === ultimoAnuncio) { return; }
            ultimoAnuncio = texto;
            // Lo que el jugador acaba de hacer ya lo dice el aviso de la accion:
            // repetirlo en la zona de estado seria hablar dos veces.
            if (window.arenaAccionEnCurso) { return; }
            const zona = document.querySelector('[data-estado-anuncio]');
            if (zona) { zona.textContent = texto; }
        };
        window.setTimeout(anunciarEstado, 0);
        // Al terminar una accion propia el estado resultante se da por dicho: lo
        // que cambie despues -un cruce que llega a los pocos segundos- si se anuncia.
        window.arenaFijarAnuncio = () => { ultimoAnuncio = leerAnuncio(); };

        const recordarCampos = (root) => {
            const campos = [];
            const activo = document.activeElement;
            root.querySelectorAll('textarea, select, input').forEach((el) => {
                const tipo = (el.type || '').toLowerCase();
                if (el.tagName === 'INPUT' && ['hidden', 'file', 'checkbox', 'radio', 'submit', 'button'].includes(tipo)) { return; }
                const clave = claveCampo(el);
                const cambiado = el.tagName === 'SELECT'
                    ? Array.from(el.options).some((o) => o.selected !== o.defaultSelected)
                    : el.value !== el.defaultValue;
                if (!clave || !cambiado) { return; }
                campos.push({ clave, valor: el.value, foco: el === activo, inicio: el.selectionStart, fin: el.selectionEnd });
            });
            return campos;
        };

        const restaurarCampos = (root, campos) => {
            campos.forEach((c) => {
                const el = Array.from(root.querySelectorAll('textarea, select, input')).find((x) => claveCampo(x) === c.clave);
                if (!el) { return; }
                el.value = c.valor;
                if (c.foco) {
                    try { el.focus({ preventScroll: true }); } catch (_) { el.focus(); }
                    try { if (c.inicio !== null && c.inicio !== undefined) { el.setSelectionRange(c.inicio, c.fin); } } catch (_) {}
                }
            });
        };

        // El repintado del panel. Antes de tocar el DOM se sueltan los visores
        // 3D que se van: el navegador solo aguanta unos pocos contextos WebGL y
        // dejarlos vivos hacia desaparecer las figuras a los pocos cambios.
        // Cada cambio de modalidad lleva un numero: si mientras esperaba llego
        // otro, el viejo se descarta. Asi atras/adelante en cadena no deja el
        // panel de un destino y la direccion de otro.
        let navSeq = 0;
        let ultimaUrl = null;

        const refreshConsole = async (search = window.location.search, navegacion = false, miSeq = 0) => {
            if (!document.querySelector('.arena-console')) { return false; }
            const seqInicio = navSeq;

            // La consulta de la pagina viaja con la peticion: lleva el guerrero
            // elegido y la modalidad, y sin ella el panel repintado volveria al
            // primero de la lista.
            const params = new URLSearchParams(search);
            params.set('t', String(Date.now()));

            // Una peticion que no contesta no puede dejar el panel congelado y
            // el sondeo sin repintar: a los 8 segundos se corta.
            const corte = new AbortController();
            const temporizador = window.setTimeout(() => corte.abort(), 8000);
            let r;
            try {
                r = await fetch(_refreshUrl + '?' + params.toString(), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                    signal: corte.signal,
                });
            } finally {
                window.clearTimeout(temporizador);
            }

            if (!r.ok) { return false; }

            const payload = await r.json();
            if (payload.reload || !payload.html) { return false; }

            // Llego otro cambio mientras tanto: este ya no vale.
            if (navegacion && miSeq !== navSeq) { return 'obsoleto'; }
            if (!navegacion && navSeq !== seqInicio) { return true; }

            const host = document.querySelector('.arena-console');
            if (!host) { return false; }
            if (payload.url) {
                const normal = new URL(payload.url, window.location.origin);
                const lang = new URLSearchParams(search).get('lang');
                if (lang) { normal.searchParams.set('lang', lang); }
                ultimaUrl = normal.pathname + normal.search;
            }

            const holder = document.createElement('div');
            holder.innerHTML = (payload.head ?? '') + payload.html + (payload.invites ?? '');

            const fresh = holder.querySelector('.arena-console');
            if (!fresh) { return false; }

            const escrito = recordarCampos(host);
            const rutaFoco = rutaFocoDe(host);

            // Al cambiar de modalidad el guerrero es el mismo: el visor 3D se
            // queda donde esta, con su contexto WebGL, y solo se le cambia lo
            // que lleva encima. Asi la figura no parpadea ni se vuelve a cargar.
            if (navegacion) {
                fresh.classList.remove('arena-animate-in', 'arena-stagger-1');
                const escenaVieja = host.querySelector('[data-champion-id=hub-stage]');
                const escenaNueva = fresh.querySelector('[data-champion-id=hub-stage]');
                const mismo = ['championRealm', 'championSubclass', 'championRace', 'championGender'];
                if (escenaVieja && escenaNueva && escenaVieja.dataset.championMounted === '1'
                    && mismo.every((k) => escenaVieja.dataset[k] === escenaNueva.dataset[k])) {
                    const capaVieja = escenaVieja.querySelector('.arena-champion-overlay');
                    const capaNueva = escenaNueva.querySelector('.arena-champion-overlay');
                    if (capaVieja && capaNueva) {
                        capaVieja.replaceWith(capaNueva);
                        escenaVieja.style.height = escenaNueva.style.height;
                        escenaNueva.replaceWith(escenaVieja);
                    }
                }
            }

            host.replaceWith(fresh);
            restaurarCampos(fresh, escrito);
            devolverFoco(fresh, rutaFoco);

            // La cabecera dice en que punto esta el jugador. Sin cambiarla, el
            // panel ensena el lobby y el titulo sigue diciendo "buscando".
            const head = document.querySelector('[data-console-head]');
            const freshHead = holder.querySelector('[data-console-head]');
            if (head && freshHead) { head.replaceWith(freshHead); }

            // Las invitaciones a party flotan fuera del panel y son la razon
            // principal por la que el jugador esta mirando: llegan por aqui.
            const invites = document.querySelector('[data-console-invites]');
            const freshInvites = holder.querySelector('[data-console-invites]');
            if (invites && freshInvites) { invites.replaceWith(freshInvites); }

            if (typeof window.arenaDisposeOrphanChampions === 'function') {
                window.arenaDisposeOrphanChampions();
            }

            swapConsoleModals(payload.modals);

            if (payload.title) { document.title = payload.title; }

            document.dispatchEvent(new CustomEvent('arena:dom-updated', { detail: { root: document } }));
            if (window.ArenaBoot) { window.ArenaBoot.run(document); }
            anunciarEstado();

            return true;
        };

        // La pagina del combate no tiene panel que pedir aparte: se pide la
        // pagina misma y se cambia el contenido en su sitio. Mantiene el scroll,
        // lo que se estaba escribiendo y el mapa de la zona, y vuelve a pasar
        // por los arranques registrados (mapa, chat, figuras 3D) igual que el
        // panel del lobby. Devuelve false si no pudo y se recarga, como siempre.
        const refreshPage = async () => {
            const host = document.getElementById('contenido');
            if (!host) { return false; }

            const corte = new AbortController();
            const temporizador = window.setTimeout(() => corte.abort(), 10000);
            let r;
            try {
                r = await fetch(window.location.href, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                    cache: 'no-store',
                    credentials: 'same-origin',
                    signal: corte.signal,
                });
            } finally {
                window.clearTimeout(temporizador);
            }

            // Una redireccion a otra pagina (el combate ya no es visible para
            // este jugador, sesion caducada...) no se pinta aqui: se navega.
            if (!r.ok || new URL(r.url).pathname !== window.location.pathname) { return false; }

            const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
            const fresh = doc.getElementById('contenido');
            if (!fresh) { return false; }

            // Lo que "se cayo delante del jugador" lo cuenta el aviso de vuelta
            // al lobby; se deja la marca como antes de recargar.
            try { sessionStorage.setItem('arena:live-reload', '1'); } catch (e) {}

            // El mapa de la zona no cambia durante el combate y cuesta montarlo:
            // si es el mismo, se queda el que ya esta funcionando.
            const mapaViejo = host.querySelector('#modal-zone-map');
            const mapaNuevo = fresh.querySelector('#modal-zone-map');
            let mapaQueSeQueda = null;
            if (mapaViejo && mapaNuevo) {
                const firma = (n) => (n.querySelector('h3') ? n.querySelector('h3').textContent.trim() : '')
                    + JSON.stringify(Object.assign({}, (n.querySelector('[data-arena-map]') || { dataset: {} }).dataset));
                if (firma(mapaViejo) === firma(mapaNuevo)) { mapaQueSeQueda = mapaViejo; mapaNuevo.replaceWith(mapaViejo); }
            }

            // Sin animacion de entrada: no es una pagina nueva, es la misma.
            fresh.querySelectorAll('.arena-animate-in').forEach((n) => {
                n.classList.remove('arena-animate-in', 'arena-stagger-1', 'arena-stagger-2', 'arena-stagger-3', 'arena-stagger-4');
            });

            // Las figuras 3D de la alineacion son las mismas durante todo el
            // combate: se quedan los visores que ya estan montados en vez de
            // recargar los modelos y crear contextos WebGL nuevos.
            const mismas = ['championRealm', 'championSubclass', 'championRace', 'championGender'];
            fresh.querySelectorAll('[data-champion-viewer][data-champion-id]').forEach((nuevo) => {
                const viejo = Array.from(host.querySelectorAll('[data-champion-viewer][data-champion-id]'))
                    .find((v) => v.dataset.championId === nuevo.dataset.championId);
                if (viejo && viejo.dataset.championMounted === '1'
                    && mismas.every((k) => viejo.dataset[k] === nuevo.dataset[k])) {
                    nuevo.replaceWith(viejo);
                }
            });

            const rutaFoco = rutaFocoDe(host);

            const escrito = recordarCampos(host);
            host.innerHTML = fresh.innerHTML;
            restaurarCampos(host, escrito);

            devolverFoco(host, rutaFoco);

            // Las ventanas de la pagina viven fuera del contenido.
            const nuevas = Array.from(doc.body.children).filter((n) => n.id && n.id.indexOf('modal-') === 0);
            const ids = new Set(nuevas.map((n) => n.id));
            Array.from(document.body.children).forEach((n) => {
                if (n.id && n.id.indexOf('modal-') === 0 && !ids.has(n.id) && !n.hasAttribute('data-console-modals')) { n.remove(); }
            });
            const hueco = document.querySelector('[data-console-modals]');
            nuevas.forEach((n) => {
                const importado = document.importNode(n, true);
                const actual = document.getElementById(n.id);
                if (actual && actual.parentNode === document.body) { actual.replaceWith(importado); }
                else if (hueco) { hueco.parentNode.insertBefore(importado, hueco); }
            });

            if (typeof window.arenaDisposeOrphanChampions === 'function') {
                window.arenaDisposeOrphanChampions();
            }

            if (doc.title) { document.title = doc.title; }

            document.dispatchEvent(new CustomEvent('arena:dom-updated', { detail: { root: document } }));
            if (window.ArenaBoot) { window.ArenaBoot.run(document); }

            anunciarEstado();

            // Si ningun arranque la uso, la marca no puede quedarse: la proxima
            // carga a mano echaria al jugador al lobby sin motivo.
            try { sessionStorage.removeItem('arena:live-reload'); } catch (e) {}

            return true;
        };

        // Bajo una ventana abierta el repintado la haria desaparecer a media
        // lectura, y debajo de alguien que esta tecleando le moveria el campo
        // entre dos teclas. En esos casos se deja para la siguiente vuelta: el
        // hash ya cambio, asi que el proximo sondeo lo vuelve a intentar.
        //
        // "Tecleando" es haber escrito hace nada, NO tener el foco en un campo.
        // Antes bastaba el foco, y el foco se queda en el desplegable del
        // ganador o en el campo de la captura despues de usarlos: si el rival
        // reportaba mientras tanto, el panel no se repintaba nunca y no salian
        // los botones de confirmar o rechazar hasta recargar. Lo escrito no se
        // pierde: recordarCampos lo lleva al panel nuevo.
        const isBusy = () => {
            if (window.arenaModal && typeof window.arenaModal.isOpen === 'function' && window.arenaModal.isOpen()) {
                return true;
            }

            // Unas capturas elegidas en un campo de archivo no sobreviven a un
            // repintado: no se toca la pagina hasta que se envien o se quiten.
            const conArchivos = Array.from(document.querySelectorAll('input[type=file]'))
                .some((i) => i.files && i.files.length > 0);
            if (conArchivos) { return true; }

            return Date.now() - lastTypedAt < _typingGraceMs;
        };

        // El hash solo se da por visto cuando la pantalla llego a cambiar. Si el
        // cambio se pospone, se queda pendiente y la vuelta siguiente lo intenta
        // de nuevo en vez de dejar al jugador con un estado viejo para siempre.
        const applyStateChange = async (hash) => {
            if (isRefreshing || isBusy()) { return; }

            // Sin direccion del panel no hay nada que cambiar en su sitio: se
            // recarga, que es lo que se hacia siempre.
            if (!_refreshUrl) {
                isRefreshing = true;
                try {
                    if (await refreshPage()) {
                        lastHash = hash;
                        return;
                    }
                } catch (_) {
                    // Cualquier fallo cae en la recarga de siempre.
                } finally {
                    isRefreshing = false;
                }

                lastHash = hash;
                queueReload();
                return;
            }

            isRefreshing = true;
            try {
                if (await refreshConsole()) {
                    lastHash = hash;
                    return;
                }
            } catch (_) {
                // Cualquier fallo cae en la recarga de siempre.
            } finally {
                isRefreshing = false;
            }

            lastHash = hash;
            queueReload();
        };

        const pollNow = async () => {
            if (isPolling || isReloading) {
                return;
            }

            isPolling = true;

            try {
                const r = await fetch(_endpoint + '?t=' + Date.now(), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                });

                if (r.ok) {
                    const data = await r.json();
                    const nextState = data.state ?? null;

                    paintQueuePulse(data.queue_pulse);

                    // Los avisos llegan fuera del hash, igual que el pulso: se
                    // pintan en vivo y NO recargan el panel. Un "voy de camino"
                    // que recargase la pantalla entera a los dos bandos seria
                    // peor que no tenerlo.
                    if (window.ArenaPings && data.pings) {
                        window.ArenaPings.pintar(data.pings, { conBocadillos: true });
                    }

                    if (data.hash && data.hash !== 'unknown') {
                        if (lastHash === null) {
                            if (lastState) {
                                const initialEvents = detectAlertEvents(lastState, nextState);
                                emitAlerts(initialEvents);
                            }
                            lastHash = data.hash;
                            lastState = nextState;
                            storeState(nextState);
                            resetCadence();
                        } else if (lastHash !== data.hash) {
                            const events = detectAlertEvents(lastState, nextState);

                            // Todo cambio de estado suena. Los que no tienen
                            // aviso propio (cruce cancelado, disputa, party
                            // deshecha...) llevan el generico: el jugador no
                            // mira la pantalla y tiene que enterarse de que
                            // algo paso.
                            if (!events.length) {
                                events.push({
                                    type: 'generic',
                                    key: 'estado:' + data.hash,
                                    message: 'Hay novedades en tu arena.',
                                });
                            }

                            emitAlerts(events);
                            lastState = nextState;
                            storeState(nextState);
                            resetCadence();

                            // El hash se da por visto dentro, y solo si el panel
                            // llego a cambiar: si el repintado se pospuso (una
                            // ventana abierta, algo a medio escribir), la vuelta
                            // siguiente lo intenta otra vez en vez de quedarse
                            // con una pantalla vieja para siempre.
                            applyStateChange(data.hash);
                        } else {
                            lastState = nextState;
                            storeState(nextState);

                            if (hasLiveActivity(nextState)) {
                                resetCadence();
                            } else {
                                stablePolls += 1;
                                currentInterval = resolveInterval();
                            }
                        }
                    }
                }
            } catch (_) {
                // Ignore transient network errors silently.
            } finally {
                isPolling = false;
                scheduleNextPoll();
            }
        };

        // Repintar la pagina o el panel donde se esta, a peticion (despues de
        // enviar un formulario). Devuelve true si se pudo.
        window.arenaRefrescarPagina = async () => {
            for (let i = 0; i < 20 && isRefreshing; i++) {
                await new Promise((resolve) => window.setTimeout(resolve, 100));
            }
            if (isRefreshing) { return false; }

            isRefreshing = true;
            try {
                return !!(_refreshUrl ? await refreshConsole() : await refreshPage());
            } catch (_) {
                return false;
            } finally {
                isRefreshing = false;
            }
        };

        // Cambiar de modalidad o de tipo sin recargar: trae el panel de esa
        // direccion y lo cambia en su sitio, sin mover el scroll. Devuelve
        // false si no pudo, y quien llama sigue el enlace de toda la vida.
        window.arenaConsoleGo = async (url, opciones = {}) => {
            if (!_refreshUrl) { return false; }

            const miSeq = ++navSeq;
            ultimaUrl = null;
            try {
                const destino = new URL(url, window.location.origin);
                // El idioma viaja con el enlace: la direccion se puede compartir.
                const lang = new URLSearchParams(window.location.search).get('lang');
                if (lang && !destino.searchParams.has('lang')) { destino.searchParams.set('lang', lang); }

                const hecho = await refreshConsole(destino.search, true, miSeq);
                if (hecho === 'obsoleto') { return true; }
                if (hecho) {
                    const direccion = ultimaUrl || (destino.pathname + destino.search);
                    if (opciones.reemplazar) { window.history.replaceState({ arenaConsole: true }, '', direccion); }
                    else { window.history.pushState({ arenaConsole: true }, '', direccion); }
                    resetCadence();
                    const avisar = document.querySelector('[data-console-status]');
                    if (avisar) {
                        avisar.textContent = Array.from(document.querySelectorAll('a.arena-console-arena[aria-current="true"]'))
                            .map((a) => a.textContent.trim()).join(' · ');
                    }
                }
                return hecho;
            } catch (_) {
                // Sin red o sin respuesta: nada se mueve y el lobby sigue donde estaba.
                return 'red';
            }
        };

        // Atras y adelante vuelven a la modalidad anterior sin recargar. Con una
        // ventana abierta se hace lo de siempre: recargar.
        window.addEventListener('popstate', () => {
            if (!document.querySelector('.arena-console')) { return; }
            if (isBusy()) { (window.arenaRecargar || (() => window.location.reload()))(); return; }
            const miSeq = ++navSeq;
            refreshConsole(window.location.search, true, miSeq)
                .then((hecho) => { if (hecho === false) { (window.arenaRecargar || (() => window.location.reload()))(); } })
                .catch(() => window.location.reload());
        });

        pollNow();

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                scheduleNextPoll(_hiddenInterval);
                return;
            }

            resetCadence();
            pollNow();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeStatePolling);
    } else {
        initializeStatePolling();
    }
})();
</script>
@endif
