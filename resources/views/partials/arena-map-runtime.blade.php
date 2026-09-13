{{-- Todo lo que necesita un mapa de zonas, para el sitio y para el panel.

     El mapa puede llegar con el panel repintado, asi que ni los estilos ni el
     cargador pueden depender de que el componente estuviera en la pagina al
     cargarla. --}}
<style>
        /* ── Mapa de zonas ─────────────────────────────────────────────────
           Vivian con el componente. Ahora estan aqui porque el mapa puede
           llegar con el panel repintado, y unos estilos que solo se emiten en
           la carga inicial no alcanzarian a ese. --*/
.arena-map-container {
        background-color: #050608;
        border-radius: 1.35rem;
        overflow: hidden;
        border: 1px solid rgba(216, 177, 92, 0.18);
    }
    .arena-map-container .leaflet-popup-content-wrapper {
        background-color: rgba(24, 17, 13, 0.94);
        border: 1px solid rgba(216, 177, 92, 0.3);
        color: #f3ebda;
        border-radius: 12px;
        box-shadow: 0 12px 30px rgba(0,0,0,0.6);
    }
    .arena-map-container .leaflet-popup-tip {
        background-color: rgba(24, 17, 13, 0.94);
        border: 1px solid rgba(216, 177, 92, 0.3);
    }
    .arena-map-container .leaflet-popup-content {
        margin: 10px 14px;
    }
    .arena-map-zone-title {
        font-family: 'Cinzel', serif;
        color: #d8b15c;
        margin: 0 0 4px 0;
        font-size: 14px;
        font-weight: 700;
    }
    .arena-map-zone-badge {
        display: inline-block;
        background-color: rgba(216, 177, 92, 0.12);
        color: #f4deb1;
        padding: 2px 10px;
        border-radius: 99px;
        font-size: 10px;
        font-family: 'Inter', sans-serif;
        font-weight: 600;
        letter-spacing: 0.06em;
    }
    .arena-map-zone-note {
        margin: 6px 0 0;
        font-size: 11px;
        color: #b9a98c;
        font-family: 'Inter', sans-serif;
    }
    /* El cartel del punto de encuentro. Naranja y no dorado a proposito: el
       oro ya lo usan el nombre de la zona y su borde, y con el mismo color los
       dos carteles se leian como uno solo. */
    .arena-map-meet {
        background: rgba(20, 10, 5, 0.9);
        border: 1px solid rgba(244, 162, 97, 0.75);
        border-radius: 4px;
        color: #ffc48c;
        font-family: 'Inter', sans-serif;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.14em;
        padding: 2px 7px;
        white-space: nowrap;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.8);
    }
    .arena-map-meet::before { display: none; }

    .arena-map-label {
        background: rgba(12, 8, 6, 0.85);
        border: 1px solid rgba(216, 177, 92, 0.5);
        border-radius: 4px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.9);
        color: #d8b15c;
        font-family: 'Cinzel', serif;
        font-weight: 700;
        text-align: center;
        transition: font-size 0.2s, padding 0.2s;
        letter-spacing: 1px;
        backdrop-filter: blur(2px);
    }
    .leaflet-tooltip-left.arena-map-label::before,
    .leaflet-tooltip-right.arena-map-label::before,
    .leaflet-tooltip-top.arena-map-label::before,
    .leaflet-tooltip-bottom.arena-map-label::before { display: none; }

    /* Dynamic text scale per zoom */
    [data-arena-map-zoom="-1"] .arena-map-label { font-size: 7px; padding: 2px 4px; }
    [data-arena-map-zoom="0"] .arena-map-label { font-size: 10px; padding: 3px 6px; }
    [data-arena-map-zoom="1"] .arena-map-label { font-size: 15px; padding: 4px 8px; }
    [data-arena-map-zoom="2"] .arena-map-label { font-size: 24px; padding: 6px 14px; }
    [data-arena-map-zoom="3"] .arena-map-label { font-size: 36px; padding: 10px 20px; }

    .arena-map-container .leaflet-interactive { transition: fill-opacity 0.2s, stroke-width 0.2s; }

        .arena-map-fallback {
            display: flex;
            height: 100%;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 20px;
            text-align: center;
            font-size: 13px;
            color: var(--arena-muted);
        }
        .arena-map-fallback b { font-size: 16px; color: var(--arena-gold-soft); }
        /* Leaflet pinta dentro del mismo hueco: cuando lo consigue, sobra. */
        .leaflet-container .arena-map-fallback { display: none; }
</style>

<script>
/* El mapa se descarga cuando alguien lo pide, no al abrir la pagina.

       Antes Leaflet y la fabrica llegaban en la carga inicial, y eso rompia el
       lobby: el cruce aparece por el sondeo, sin recargar, asi que un mapa que
       solo existia si ya habia enfrentamiento al cargar la pagina nunca estaba
       cuando el jugador pulsaba la zona. Ahora se pide en ese momento y da
       igual como haya llegado el boton. */
    window.arenaLoadMap = (function () {
        var pendiente = null;

        // El editor del panel dibuja los mismos puntos para poder moverlos, y
        // tiene que salirle el mismo numero que al jugador: un calculo copiado
        // en dos sitios acaba dando dos puntos distintos.
        window.ArenaMapPoints = {
            puntoDeEncuentro: puntoDeEncuentro,
            distanciaAlBorde: distanciaAlBorde,
            /** El punto de una zona: el fijado a mano si lo tiene, o el calculado. */
            deZona: function (zone) {
                if (!zone || !zone.coords || zone.coords.length < 3) { return null; }

                if (Array.isArray(zone.meeting) && zone.meeting.length === 2) {
                    return {
                        punto: zone.meeting,
                        holgura: distanciaAlBorde(zone.meeting, zone.coords),
                        fijado: true,
                    };
                }

                var calculado = puntoDeEncuentro(zone.coords);
                calculado.fijado = false;

                return calculado;
            },
        };


        function traer(tag, attrs) {
            return new Promise(function (resolve, reject) {
                var el = document.createElement(tag);
                Object.keys(attrs).forEach(function (k) { el.setAttribute(k, attrs[k]); });
                el.onload = resolve;
                el.onerror = function () { reject(new Error('No se pudo cargar ' + (attrs.src || attrs.href))); };
                document.head.appendChild(el);
            });
        }

        return function () {
            if (window.ArenaMapFactory && window.L && window.ARENA_ZONES_CONFIG) {
                return Promise.resolve();
            }

            if (pendiente) { return pendiente; }

            pendiente = traer('link', {
                rel: 'stylesheet',
                href: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
                integrity: 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=',
                crossorigin: '',
            }).then(function () {
                return traer('script', {
                    src: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
                    integrity: 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=',
                    crossorigin: '',
                });
            }).then(function () {
                return traer('script', { src: @json(asset('js/arena-zones.js')) });
            }).then(function () {
                window.ArenaMapFactory = window.ArenaMapFactory || crearFabrica();
            }).catch(function (error) {
                // Otro intento mas adelante en vez de quedarse clavado.
                pendiente = null;
                throw error;
            });

            return pendiente;
        };

        /* Punto de encuentro de una zona.

           Las zonas son grandes y "nos vemos en la zona 8" deja a cuatro
           personas dando vueltas por media frontera. Esto calcula el punto mas
           interior del poligono -el que queda mas lejos de cualquier borde- y
           es donde se queda para pelear.

           No vale el centro de masas: en una zona en forma de arco cae fuera
           del propio terreno. El calculo es una busqueda por rejilla que se va
           afinando, siempre sobre los mismos vertices, asi que los cuatro
           jugadores del cruce ven exactamente el mismo punto. */
        function distanciaAlBorde(punto, coords) {
            var dentro = false;
            var minimo = Infinity;

            for (var i = 0, j = coords.length - 1; i < coords.length; j = i++) {
                var ay = coords[i][0], ax = coords[i][1];
                var by = coords[j][0], bx = coords[j][1];

                if ((ay > punto[0]) !== (by > punto[0]) &&
                    punto[1] < (bx - ax) * (punto[0] - ay) / (by - ay) + ax) {
                    dentro = !dentro;
                }

                // Distancia del punto al segmento AB.
                var dy = by - ay, dx = bx - ax;
                var largo = dy * dy + dx * dx;
                var t = largo === 0 ? 0 : ((punto[0] - ay) * dy + (punto[1] - ax) * dx) / largo;
                t = Math.max(0, Math.min(1, t));
                var py = ay + t * dy, px = ax + t * dx;
                minimo = Math.min(minimo, Math.sqrt(Math.pow(punto[0] - py, 2) + Math.pow(punto[1] - px, 2)));
            }

            return dentro ? minimo : -minimo;
        }

        function puntoDeEncuentro(coords) {
            var ys = coords.map(function (c) { return c[0]; });
            var xs = coords.map(function (c) { return c[1]; });
            var y0 = Math.min.apply(null, ys), y1 = Math.max.apply(null, ys);
            var x0 = Math.min.apply(null, xs), x1 = Math.max.apply(null, xs);

            var mejor = [(y0 + y1) / 2, (x0 + x1) / 2];
            var mejorDistancia = -Infinity;
            var paso = Math.max((y1 - y0), (x1 - x0)) / 16;

            for (var vuelta = 0; vuelta < 5; vuelta++) {
                var desdeY = vuelta === 0 ? y0 : mejor[0] - paso * 2;
                var hastaY = vuelta === 0 ? y1 : mejor[0] + paso * 2;
                var desdeX = vuelta === 0 ? x0 : mejor[1] - paso * 2;
                var hastaX = vuelta === 0 ? x1 : mejor[1] + paso * 2;

                for (var y = desdeY; y <= hastaY; y += paso) {
                    for (var x = desdeX; x <= hastaX; x += paso) {
                        var d = distanciaAlBorde([y, x], coords);
                        if (d > mejorDistancia) {
                            mejorDistancia = d;
                            mejor = [y, x];
                        }
                    }
                }

                paso /= 3;
            }

            return { punto: mejor, holgura: Math.max(mejorDistancia, 0) };
        }

        function crearFabrica() {
            return {
                create: function (containerId, options) {
                    options = options || {};
            const el = document.getElementById(containerId);
                    if (!el || !window.ARENA_ZONES_CONFIG) return null;

                    const w = 1086, h = 1086;
                    const map = L.map(containerId, {
                        crs: L.CRS.Simple,
                        minZoom: -1,
                        maxZoom: 3,
                        zoomControl: false,
                        dragging: options.interactive !== false,
                        scrollWheelZoom: options.interactive !== false,
                        doubleClickZoom: options.interactive !== false,
                        touchZoom: options.interactive !== false,
                    });

                    const bounds = [[0, 0], [h, w]];
                    L.imageOverlay('{{ asset("mapa/mapa-regnum-limpio.jpg") }}', bounds).addTo(map);

                    if (options.interactive !== false) {
                        L.control.zoom({ position: 'bottomright' }).addTo(map);
                    }

                    const highlightKey = options.highlightZone || null;
                    let highlightPolygon = null;

                    window.ARENA_ZONES_CONFIG.forEach(zone => {
                        if (!zone.coords || zone.coords.length < 3) return;

                        const isHighlighted = highlightKey && zone.key === highlightKey;
                        const isOther = highlightKey && zone.key !== highlightKey;

                        const polygon = L.polygon(zone.coords, {
                            color: isHighlighted ? '#f4deb1' : 'rgba(216, 177, 92, 0.6)',
                            weight: isHighlighted ? 3 : 2,
                            fillColor: isHighlighted ? '#D8B15C' : '#D8B15C',
                            fillOpacity: isHighlighted ? 0.30 : (isOther ? 0.03 : 0.08),
                            dashArray: isHighlighted ? null : '5 5',
                            className: isOther ? '' : '',
                        }).addTo(map);

                        if (!highlightKey || isHighlighted) {
                            polygon.on('mouseover', function () {
                                this.setStyle({ fillOpacity: isHighlighted ? 0.40 : 0.25, weight: 3, color: '#F9D87E' });
                            });
                            polygon.on('mouseout', function () {
                                this.setStyle({
                                    fillOpacity: isHighlighted ? 0.30 : 0.08,
                                    weight: isHighlighted ? 3 : 2,
                                    color: isHighlighted ? '#f4deb1' : 'rgba(216, 177, 92, 0.6)'
                                });
                            });
                        }

                        polygon.bindTooltip(zone.name.split(' - ')[0].toUpperCase(), {
                            permanent: true,
                            direction: 'center',
                            className: 'arena-map-label',
                            opacity: isOther ? 0.35 : 1,
                        });

                        if (!isOther) {
                            polygon.bindPopup(`
                                <div class="arena-map-zone-badge">Zona PvP</div>
                                <h4 class="arena-map-zone-title">${zone.name}</h4>
                                <p class="arena-map-zone-note">El aspa marca el punto de encuentro.</p>
                            `);
                        }

                        // El punto de encuentro. En la zona del cruce va con su
                        // circulo y su cartel; en las demas basta con la marca,
                        // o el mapa entero se llena de carteles.
                        // El calculo es geometrico: da el punto mas interior,
                        // pero no sabe si ahi hay agua o un risco. Cuando una
                        // zona necesite otro sitio, se mueve desde el editor
                        // del panel y queda como "meeting": [y, x].
                        var encuentro = window.ArenaMapPoints.deZona(zone);

                        if (isHighlighted || !highlightKey) {
                            if (isHighlighted) {
                                L.circle(encuentro.punto, {
                                    radius: Math.max(18, Math.min(encuentro.holgura * 0.55, 55)),
                                    color: '#f4a261',
                                    weight: 1,
                                    dashArray: '4 5',
                                    fillColor: '#f4a261',
                                    fillOpacity: 0.12,
                                    interactive: false,
                                }).addTo(map);
                            }

                            var marca = L.circleMarker(encuentro.punto, {
                                radius: isHighlighted ? 6 : 3,
                                color: '#1a1209',
                                weight: isHighlighted ? 2 : 1,
                                fillColor: isHighlighted ? '#f9d87e' : 'rgba(244, 162, 97, 0.75)',
                                fillOpacity: 1,
                                interactive: false,
                            }).addTo(map);

                            if (isHighlighted) {
                                marca.bindTooltip('PUNTO DE ENCUENTRO', {
                                    permanent: true,
                                    direction: 'bottom',
                                    offset: [0, 8],
                                    className: 'arena-map-meet',
                                });
                            }
                        }

                        if (isHighlighted) {
                            highlightPolygon = polygon;
                        }
                    });

                    // Zoom handling
                    el.setAttribute('data-arena-map-zoom', map.getZoom());
                    map.on('zoomend', function() {
                        el.setAttribute('data-arena-map-zoom', map.getZoom());
                    });

                    // Fit view
                    if (highlightPolygon) {
                        map.fitBounds(highlightPolygon.getBounds().pad(0.5));
                        highlightPolygon.openPopup();
                    } else {
                        map.fitBounds(bounds);
                    }

                    return map;
                }
            };
        }
    })();
    </script>

<script>
        /* Arranque de los mapas.
           Un mapa dentro de una ventana cerrada no se puede medir, asi que se
           monta cuando de verdad se ve: al cargar, al abrirlo, y cada vez que
           el panel se repinta y trae uno nuevo. */
        (function () {
            function montar(host) {
                if (!host || host.dataset.arenaMapReady === '1') { return false; }
                if (host.offsetParent === null) { return false; }

                host.dataset.arenaMapReady = '1';

                window.arenaLoadMap().then(function () {
                    var instancia = window.ArenaMapFactory.create(host.id, {
                        highlightZone: host.dataset.arenaMapZone || null,
                        interactive: host.dataset.arenaMapInteractive !== '0',
                    });

                    // Dentro de una ventana recien abierta el tamano aun no es
                    // el definitivo: se recalcula al asentarse.
                    if (instancia) { window.setTimeout(function () { instancia.invalidateSize(); }, 200); }
                }).catch(function (error) {
                    // Se deja listo para reintentar: sin red el mapa no llega,
                    // pero el boton no puede quedarse roto para siempre.
                    host.dataset.arenaMapReady = '';

                    var aviso = host.querySelector('[data-arena-map-fallback-text]');
                    if (aviso) { aviso.textContent = 'No se pudo cargar el mapa. La zona asignada es:'; }

                    console.error(error);
                });

                return true;
            }

            function montarTodos(root) {
                (root || document).querySelectorAll('[data-arena-map]').forEach(montar);
            }

            // El panel de administracion usa otro layout y no tiene registro de
            // arranques: alli basta con montar al cargar.
            if (window.ArenaBoot) {
                window.ArenaBoot.register(montarTodos);
            } else if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () { montarTodos(document); });
            } else {
                montarTodos(document);
            }

            // Abrir la ventana es lo que le da tamano al mapa.
            document.addEventListener('click', function (event) {
                var opener = event.target.closest('[data-modal-open]');
                if (!opener) { return; }

                var ventana = document.getElementById(opener.dataset.modalOpen);
                if (!ventana) { return; }

                window.setTimeout(function () { montarTodos(ventana); }, 150);
            });
        })();
    </script>
