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

    function initializeStatePolling() {
        let lastHash = null;
        let lastState = readStoredState();
        let isPolling = false;
        let stablePolls = 0;
        let currentInterval = _baseInterval;
        let timerId = null;
        let isReloading = false;

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
                            lastHash = data.hash;
                            lastState = nextState;
                            storeState(nextState);
                            queueReload();
                            return;
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
