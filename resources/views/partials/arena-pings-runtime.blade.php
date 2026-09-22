{{-- Avisos rapidos: mandarlos y pintarlos.

     Dos caminos para lo mismo. El boton manda el aviso y pinta la respuesta al
     momento -el que avisa no puede esperar tres segundos a ver si le hizo
     caso-, y el sondeo trae los del rival. Los dos acaban en pintar(), que es
     idempotente: repintar la misma lista dos veces no duplica nada.

     Vive en el layout y no junto al panel porque el panel se repinta solo con
     cada cambio de estado, y un script que solo existiera ahi se perderia en
     el primer repintado. --}}
<script>
(function () {
    /* Lo ultimo que se pinto, por enfrentamiento.

       El sondeo trae la lista entera cada vez. Sin esto, cada vuelta volveria a
       sacar el bocadillo del ultimo aviso aunque fuera de hace un minuto, y el
       rival veria "voy de camino" apareciendo en bucle cada tres segundos. */
    var ultimoVisto = {};
    var SEGUNDOS_BOCADILLO = 6000;

    /* Lo que tiene que pasar entre dos pitidos, aunque lleguen mas avisos.
       Ver el bloque del sonido en pintar(). */
    var DESCANSO_PITIDO = 7000;
    var ultimoPitido = 0;

    /* Los no leidos, por enfrentamiento y fuera del DOM.

       Guardarlos en el propio nodo los perdia en cada repintado del panel, y
       el panel se repinta solo cada pocos segundos: la cuenta no llegaba viva
       a ninguna parte. */
    var sinLeerPorCruce = {};

    function sinLeerDe(caja) {
        return sinLeerPorCruce[caja.dataset.pingsMatch || ''] || 0;
    }

    function nodoDeAvisos(root) {
        return (root || document).querySelector('[data-pings]');
    }

    function hora(iso) {
        if (!iso) { return ''; }

        try {
            return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return '';
        }
    }

    /* El bocadillo sobre la figura de quien avisa.

       Solo uno por figura: el ultimo sustituye al anterior. Lo que importa es
       lo ultimo que dijo; el historial completo esta en la lista de abajo. */
    function sacarBocadillo(ping) {
        var figura = document.querySelector('[data-fighter="' + ping.fid + '"]');
        if (!figura) { return; }

        var globo = figura.querySelector('[data-fighter-bubble]');
        if (!globo) { return; }

        var icono = globo.querySelector('[data-fighter-bubble-icon]');
        var texto = globo.querySelector('[data-fighter-bubble-text]');

        if (icono) { icono.textContent = ping.icono || ''; }
        if (texto) { texto.textContent = ping.texto || ''; }

        globo.className = 'arena-battle-bubble is-' + (ping.tono || 'aviso');
        globo.hidden = false;
        figura.classList.add('is-talking');

        // Reinicia la animacion: sin esto, un aviso nuevo sobre un bocadillo
        // que aun no se habia ido entraria sin moverse.
        globo.style.animation = 'none';
        void globo.offsetWidth;
        globo.style.animation = '';

        window.clearTimeout(globo._arenaTimer);
        globo._arenaTimer = window.setTimeout(function () {
            globo.classList.add('is-leaving');
            figura.classList.remove('is-talking');

            window.setTimeout(function () {
                globo.hidden = true;
                globo.classList.remove('is-leaving');
            }, 300);
        }, SEGUNDOS_BOCADILLO);
    }

    function pintarHistorial(caja, pings) {
        var log = caja.querySelector('[data-pings-log]');
        if (!log) { return; }

        if (!pings.length) {
            log.innerHTML = '<li class="arena-chat-empty">Todavia no ha dicho nada nadie. Avisa tu primero.</li>';
            return;
        }

        // Lo mas nuevo abajo, como cualquier chat.
        log.innerHTML = pings.map(function (ping) {
            return '<li class="arena-chat-msg ' + (ping.mio ? 'is-mine' : 'is-theirs') + '">' +
                '<span class="arena-chat-bubble">' +
                '<span class="arena-chat-who">' + escapar(ping.nombre || '') + '</span>' +
                '<span class="arena-chat-said"><span aria-hidden="true">' + escapar(ping.icono || '') + '</span> ' +
                escapar(ping.texto || '') + '</span>' +
                '<time>' + escapar(hora(ping.en)) + '</time>' +
                '</span></li>';
        }).join('');

        // Si estaba abajo del todo, se queda abajo. Si habia subido a releer
        // algo, no se le arrastra: eso es lo que distingue un chat usable de
        // uno que se mueve solo debajo del dedo.
        if (log._arenaPegado !== false) {
            log.scrollTop = log.scrollHeight;
        }
    }

    /* Los que han llegado mientras no se miraba.

       El chat ya no se pliega, asi que "sin leer" es lo que ha entrado con la
       pestaña en segundo plano o con el log subido releyendo algo. Se limpia en
       cuanto se vuelve a mirar. */
    function pintarContador(caja) {
        var chapa = caja.querySelector('[data-chat-badge]');
        if (!chapa) { return; }

        var sinLeer = sinLeerDe(caja);

        chapa.textContent = sinLeer > 9 ? '9+' : String(sinLeer);
        chapa.hidden = sinLeer === 0;
    }

    function escapar(valor) {
        var d = document.createElement('div');
        d.textContent = valor == null ? '' : String(valor);
        return d.innerHTML;
    }

    /* Pinta una lista de avisos.

       `nuevosDesde` marca a partir de que id hay que sacar bocadillo. Con el
       sondeo son los que no se habian visto; al cargar la pagina no es ninguno,
       porque un bocadillo de un aviso de hace diez minutos no es un aviso, es
       ruido. */
    function pintar(pings, opciones) {
        var caja = nodoDeAvisos();
        if (!caja) { return; }

        pings = Array.isArray(pings) ? pings : [];
        opciones = opciones || {};

        var idMatch = caja.dataset.pingsMatch || '';
        var visto = ultimoVisto[idMatch];
        var nuevos = visto === undefined ? [] : pings.filter(function (p) { return p.id > visto; });

        pintarHistorial(caja, pings);

        if (opciones.conBocadillos !== false) {
            nuevos.forEach(sacarBocadillo);
        }

        // Los del rival avisan: sonido, vibracion y chapa. Los mios no, que ya
        // se lo que he dicho.
        var suyos = nuevos.filter(function (p) { return !p.mio; });

        if (suyos.length) {
            var ultimo = suyos[suyos.length - 1];

            /* Cuenta como no leido lo que entra sin que se este mirando: con
               la pestaña de lado, o con el log subido releyendo algo mas
               arriba. Contarlo SOLO con la pestaña oculta dejaba la chapa
               muerta -se pintaba justo cuando nadie podia verla y se borraba
               al volver-. */
            var log = caja.querySelector('[data-pings-log]');
            var mirando = document.visibilityState !== 'hidden' && (!log || log._arenaPegado !== false);

            if (!mirando) {
                sinLeerPorCruce[caja.dataset.pingsMatch || ''] = sinLeerDe(caja) + suyos.length;
            }

            /* El pitido va espaciado, el aviso en pantalla no.

               Cada aviso llevaba su propia clave -'match-ping:12'- asi que
               ninguno se repetia con otro y TODOS sonaban: alternando frases se
               podia tener al rival pitando y vibrando cada pocos segundos
               durante todo el combate. El bocadillo y la burbuja siguen
               apareciendo siempre; lo que se espacia es el ruido. */
            if (window.ArenaSoundAlerts && typeof window.ArenaSoundAlerts.notify === 'function') {
                var ahora = Date.now();

                if (ahora - (ultimoPitido || 0) >= DESCANSO_PITIDO) {
                    ultimoPitido = ahora;

                    window.ArenaSoundAlerts.notify(
                        'match_ping',
                        ultimo.icono + ' ' + ultimo.nombre + ': ' + ultimo.texto,
                        { key: 'match-ping:' + ultimo.id, duration: 4000 }
                    );
                }
            }
        }

        pintarContador(caja);

        ultimoVisto[idMatch] = pings.length
            ? Math.max.apply(null, pings.map(function (p) { return p.id; }))
            : (visto || 0);
    }

    /* Al volver a la pestaña ya no hay nada sin leer: esta a la vista. */
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { return; }

        var caja = nodoDeAvisos();
        if (!caja) { return; }

        sinLeerPorCruce[caja.dataset.pingsMatch || ''] = 0;
        pintarContador(caja);
    });

    /* Si el jugador ha subido a releer algo, el repintado no puede arrastrarle
       de vuelta abajo. Se apunta si esta pegado al final. */
    document.addEventListener('scroll', function (event) {
        var log = event.target;
        if (!log || !log.matches || !log.matches('[data-pings-log]')) { return; }

        log._arenaPegado = (log.scrollHeight - log.scrollTop - log.clientHeight) < 24;

        // Volver al fondo es haber leido lo que habia: la chapa se apaga ahi.
        if (log._arenaPegado) {
            var caja = log.closest('[data-pings]');

            if (caja) {
                sinLeerPorCruce[caja.dataset.pingsMatch || ''] = 0;
                pintarContador(caja);
            }
        }
    }, true);

    function avisar(caja, mensaje, esError) {
        var estado = caja.querySelector('[data-pings-status]');
        if (!estado) { return; }

        estado.textContent = mensaje || '';
        estado.classList.toggle('is-error', !!esError);

        window.clearTimeout(estado._arenaTimer);
        if (mensaje) {
            estado._arenaTimer = window.setTimeout(function () {
                estado.textContent = '';
                estado.classList.remove('is-error');
            }, 4000);
        }
    }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /* El toque de "ha salido". Distinto del que suena cuando avisa el rival:
       durante un combate no se mira la pantalla, y los dos tienen que poder
       distinguirse de oido. */
    function sonarEnvio() {
        if (window.ArenaSoundAlerts && typeof window.ArenaSoundAlerts.play === 'function') {
            window.ArenaSoundAlerts.play('match_ping_sent');
        }
    }

    function enviar(caja, boton, code) {
        // Todos los botones a la vez mientras vuela: si se bloqueara solo el
        // pulsado, pulsar tres seguidos se saltaria el limite del servidor y el
        // jugador solo veria tres errores.
        var botones = caja.querySelectorAll('[data-ping-send]');
        botones.forEach(function (b) { b.disabled = true; });

        fetch(caja.dataset.pingsEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                match_id: caja.dataset.pingsMatch,
                player_id: caja.dataset.pingsPlayer,
                code: code,
            }),
        }).then(function (r) {
            return r.json().then(function (data) { return { ok: r.ok, data: data }; });
        }).then(function (res) {
            if (!res.ok || !res.data.ok) {
                avisar(caja, res.data.motivo || 'No se pudo mandar el aviso.', true);
                return;
            }

            avisar(caja, 'Enviado');
            sonarEnvio();
            pintar(res.data.pings, { conBocadillos: true });
        }).catch(function () {
            avisar(caja, 'Sin conexion. Intentalo otra vez.', true);
        }).finally(function () {
            // Un respiro corto: evita la doble pulsacion sin que parezca que se
            // ha quedado colgado.
            window.setTimeout(function () {
                botones.forEach(function (b) { b.disabled = false; });
            }, 700);
        });
    }

    // Delegado en el documento: el panel se repinta entero con cada cambio de
    // estado, asi que enganchar los botones uno a uno no sobreviviria.
    document.addEventListener('click', function (event) {
        var boton = event.target.closest('[data-ping-send]');
        if (!boton) { return; }

        var caja = boton.closest('[data-pings]');
        if (!caja || !caja.dataset.pingsPlayer) { return; }

        event.preventDefault();
        enviar(caja, boton, boton.dataset.pingSend);
    });

    window.ArenaPings = { pintar: pintar };

    /* Al cargar o al repintar el panel: se pinta el historial que ya venia en
       la pagina, sin bocadillos. */
    function arrancar(root) {
        var caja = nodoDeAvisos(root);
        if (!caja || caja.dataset.pingsReady === '1') { return; }

        caja.dataset.pingsReady = '1';

        var log = caja.querySelector('[data-pings-log]');
        if (log) { log.scrollTop = log.scrollHeight; }

        // El historial ya viene pintado desde el servidor. Lo que hace falta es
        // apuntar por donde iba, o el primer sondeo sacaria un bocadillo por
        // cada aviso viejo de golpe.
        var semilla = caja.querySelector('[data-pings-seed]');
        if (!semilla) { return; }

        try {
            var previos = JSON.parse(semilla.textContent || '[]');

            if (Array.isArray(previos) && previos.length) {
                ultimoVisto[caja.dataset.pingsMatch || ''] = Math.max.apply(
                    null,
                    previos.map(function (p) { return p.id; })
                );
            }
        } catch (e) { /* si no se entiende, el primer sondeo lo arregla */ }
    }

    if (window.ArenaBoot) {
        window.ArenaBoot.register(arrancar);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { arrancar(document); });
    } else {
        arrancar(document);
    }
})();
</script>
