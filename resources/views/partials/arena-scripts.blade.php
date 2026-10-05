{{-- Los scripts globales del sitio: menu movil, ventanas, avisos, sonido y
     los mensajes de la sesion. Vivian dentro del layout (que pasaba de 4.900
     lineas); aqui se leen y se tocan sin tener que recorrerlo entero. --}}
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

            /* Sin notificaciones del sistema: ni globo de permiso ni tarjeta
               flotante del navegador. El aviso es el sonido, el titulo de la
               pestaña y el aviso interno de la app. Solo con el push
               encendido a proposito (VAPID_ENABLED) vuelven a existir. */
            const hayNotificaciones = () => document.documentElement.hasAttribute('data-arena-push')
                && typeof window.Notification === 'function';

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

            /* El titulo de cada aviso, el mismo que pone el service worker.
               Y la misma etiqueta: si llegan los dos -el de la pagina y el
               push- el sistema enseña uno solo, porque la etiqueta dice que
               son el mismo hecho. */
            const TITULOS_AVISO = {
                match_found: 'Rival encontrado',
                hunt_start: '¡A pelear!',
                report_submitted: 'Resultado por confirmar',
                report_confirmed: 'Resultado confirmado',
                party_invite: 'Invitacion de equipo',
                party_ready: 'Tu equipo esta listo',
                match_ping: 'Aviso del rival',
            };

            const ETIQUETAS_AVISO = {
                'match-found': 'cruce',
                'hunt-start': 'combate',
                'report-pending': 'reporte',
                'report-confirmed': 'resultado',
                'party-invite': 'party',
            };

            const etiquetaDe = (type, clave) => {
                const [prefijo, id] = String(clave || '').split(':');
                const base = ETIQUETAS_AVISO[prefijo];

                if (base === 'party') { return 'party'; }
                if (base && id) { return base + ':' + id; }

                return 'arena:' + type;
            };

            /* La notificacion del sistema, desde la pagina.
               Va por el service worker cuando lo hay: en Android,
               `new Notification()` directamente LANZA -Chrome solo admite ahi
               las del worker-, asi que en movil este aviso no habia salido
               nunca. El constructor queda de respaldo para escritorio sin
               worker. */
            const notificarSistema = async (type, mensaje, etiqueta) => {
                if (!hayNotificaciones() || Notification.permission !== 'granted') { return false; }

                /* Siempre, aunque el push este activo. Callarlo "porque ya
                   llega por push" dejaba sin NADA a quien tenia el push roto
                   sin saberlo (Google caduca direcciones por su cuenta): ni
                   push ni el aviso de la pagina, que es el que siempre habia
                   funcionado con la pestaña de lado. Si llegan los dos, la
                   etiqueta es la misma y el sistema enseña uno. */
                const titulo = TITULOS_AVISO[type] || 'Regnum Arena Ladder';
                const opciones = {
                    body: mensaje,
                    tag: etiqueta || ('arena:' + type),
                    // Si el push ya lo enseño, este lo sustituye sin volver a
                    // sonar: es el mismo aviso, no uno nuevo.
                    renotify: false,
                    // Un cruce caduca en minutos: se queda puesto hasta que se vea.
                    requireInteraction: type === 'match_found',
                    icon: '{{ asset('images/icono-192.png') }}',
                    data: { url: '{{ route('lobby') }}' },
                };

                try {
                    if ('serviceWorker' in navigator) {
                        const reg = await navigator.serviceWorker.getRegistration('/');

                        if (reg) {
                            await reg.showNotification(titulo, opciones);
                            return true;
                        }
                    }
                } catch (_) {
                    // Sigue al constructor.
                }

                try {
                    const aviso = new Notification(titulo, opciones);

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
                // Con el controlador de avisos presente, pinta EL. Dos pintores
                // con dos ideas del estado era el "verde y luego rojo".
                if (window.ArenaAvisos) {
                    window.ArenaAvisos.repintar();
                    return;
                }

                // Con push, el controlador aun no ha cargado: no se pinta nada.
                // Pintar aqui con el ajuste de sonido volvia a poner el verde
                // un instante antes de que el controlador lo corrigiera.
                if (document.documentElement.hasAttribute('data-arena-push')) {
                    return;
                }

                document.dispatchEvent(new CustomEvent('arena:sonido-estado', { detail: { enabled, unlocked } }));

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
                        // El marcado nace neutro (ambar): ni verde ni rojo hasta
                        // saber el estado real. Nacer en verde era el primer
                        // tramo del "se pone verde y luego rojo".
                        indicator.classList.remove('bg-amber-300');
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

                    // Con el controlador de avisos, el permiso se pide en SU
                    // boton y solo ahí. Pedirlo al primer clic en cualquier
                    // parte daba permiso sin suscripcion -el boton seguia en
                    // rojo sin motivo aparente- y un globo que sale de la nada
                    // se deniega, y denegado ya no se puede volver a pedir.
                    if (window.ArenaAvisos) { return; }

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
                    // Y si el navegador lo deja (ya se toco el sitio en esta
                    // sesion), suena en cuanto se desbloquea en vez de perderse.
                    unlock().then((ok) => { if (ok) { playPattern(type); } });

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

                // Sin un toque previo del jugador el navegador bloquea la
                // vibracion y lo apunta como error en la consola.
                var tocada = !navigator.userActivation || navigator.userActivation.hasBeenActive;
                if (navigator.vibrate && pattern.vibrate && tocada) {
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
                        // `silent` distingue "lo acaba de pulsar una persona"
                        // de "la pagina lo restaura al cargar". En el primer
                        // caso, si no se puede, hay que decirlo.
                        detail: { enabled: true, silent: !!options.silent },
                    }));

                    if (!options.silent) {
                        // Un toque de prueba al encender: es la unica forma de
                        // saber si de verdad va a sonar. En un iPhone con el
                        // interruptor lateral en silencio no suena nada, y eso
                        // no lo puede saltar ninguna pagina web.
                        playPattern('generic');
                        arenaToast(
                            unlocked
                                ? 'Sonido activado. Si no lo oyes, revisa que el móvil no esté en silencio.'
                                : 'Sonido activado. Toca cualquier parte de la página para que pueda sonar.',
                            unlocked ? 'success' : 'warning',
                            5000
                        );
                    }
                } else {
                    document.dispatchEvent(new CustomEvent('arena:alertas', {
                        detail: { enabled: false },
                    }));

                    if (!options.silent) {
                        arenaToast('Sonido apagado.', 'info', 3500);
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
                    notificarSistema(type, message, options.tag || etiquetaDe(type, eventKey));
                    parpadearTitulo(message);
                    // Y el aviso interno, largo: sigue ahí cuando se vuelve.
                    arenaToast(message, options.toastType || 'info', 20000);
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

                /* Con avisos del navegador, manda su controlador.
                   Conmutar aqui a ciegas era la trampa: la etiqueta decia
                   "Activar avisos" y el clic APAGABA las alertas, porque el
                   ajuste ya estaba encendido de fabrica. El controlador decide
                   por lo que la persona ve pintado. */
                if (window.ArenaAvisos) {
                    window.ArenaAvisos.alternar();
                    return;
                }

                // Sin push configurado queda el interruptor de sonido de
                // siempre: encender o silenciar.
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

            // Silenciar en una pestaña silencia todas: sin esto, la otra seguia
            // sonando con la idea vieja hasta recargar.
            window.addEventListener('storage', (event) => {
                if (event.key !== enabledKey) return;
                enabled = event.newValue !== '0';
                updateButtons();
            });

            installUnlockListeners();
            updateButtons();
        })();

        // El desplegable de la barra se cierra al tocar fuera o con Escape.
        document.addEventListener('click', (e) => {
            document.querySelectorAll('[data-nav-drop][open]').forEach((d) => { if (!d.contains(e.target)) d.removeAttribute('open'); });
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') document.querySelectorAll('[data-nav-drop][open]').forEach((d) => d.removeAttribute('open'));
        });

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
