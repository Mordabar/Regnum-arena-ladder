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
    const _hiddenInterval = 15000;
    const _slowAfterStablePolls = 5;
    const _idleAfterStablePolls = 10;
    const _stateStorageKey = 'arena:poll-state:' + window.location.pathname;
    const _reloadDelayMs = 650;
    // Con una direccion aqui, un cambio de estado trae solo el panel y lo cambia
    // en su sitio. Sin ella (o si la peticion falla) se recarga la pagina, que
    // es lo que se hacia siempre.
    const _refreshUrl = @json($refreshUrl);

    function initializeStatePolling() {
        let lastHash = null;
        let lastState = readStoredState();
        let isPolling = false;
        let stablePolls = 0;
        let currentInterval = _baseInterval;
        let timerId = null;
        let isReloading = false;
        let isRefreshing = false;

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
                clearTimeout(timerId);
                timerId = null;
            }
        };

        const scheduleNextPoll = (delay = currentInterval) => {
            clearScheduledPoll();
            const nextDelay = document.visibilityState === 'hidden' ? _hiddenInterval : delay;

            timerId = window.setTimeout(() => {
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
            if (hintNode && pulse.hint) {
                hintNode.textContent = pulse.hint;
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
            window.setTimeout(() => {
                window.location.reload();
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

        // El repintado del panel. Antes de tocar el DOM se sueltan los visores
        // 3D que se van: el navegador solo aguanta unos pocos contextos WebGL y
        // dejarlos vivos hacia desaparecer las figuras a los pocos cambios.
        const refreshConsole = async () => {
            const host = document.querySelector('.arena-console');
            if (!host) { return false; }

            // La consulta de la pagina viaja con la peticion: lleva el guerrero
            // elegido y la modalidad, y sin ella el panel repintado volveria al
            // primero de la lista.
            const params = new URLSearchParams(window.location.search);
            params.set('t', String(Date.now()));

            const r = await fetch(_refreshUrl + '?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });

            if (!r.ok) { return false; }

            const payload = await r.json();
            if (payload.reload || !payload.html) { return false; }

            const holder = document.createElement('div');
            holder.innerHTML = (payload.head ?? '') + payload.html + (payload.invites ?? '');

            const fresh = holder.querySelector('.arena-console');
            if (!fresh) { return false; }

            host.replaceWith(fresh);

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

            return true;
        };

        // Cambiar el panel debajo de alguien que esta escribiendo le borraria lo
        // escrito, y bajo una ventana abierta la haria desaparecer a media
        // lectura. En esos dos casos se deja para la siguiente vuelta: el hash
        // ya cambio, asi que el proximo sondeo lo vuelve a intentar.
        const isBusy = () => {
            if (window.arenaModal && typeof window.arenaModal.isOpen === 'function' && window.arenaModal.isOpen()) {
                return true;
            }

            const active = document.activeElement;
            if (!active || !active.closest || !active.closest('.arena-console')) { return false; }

            const tag = active.tagName;
            if (tag === 'TEXTAREA' || tag === 'SELECT') { return true; }
            if (tag === 'INPUT') { return active.type !== 'checkbox' && active.type !== 'radio' && active.type !== 'submit'; }

            return false;
        };

        // El hash solo se da por visto cuando la pantalla llego a cambiar. Si el
        // cambio se pospone, se queda pendiente y la vuelta siguiente lo intenta
        // de nuevo en vez de dejar al jugador con un estado viejo para siempre.
        const applyStateChange = async (hash) => {
            if (isRefreshing || isBusy()) { return; }

            // Sin direccion del panel no hay nada que cambiar en su sitio: se
            // recarga, que es lo que se hacia siempre.
            if (!_refreshUrl) {
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
