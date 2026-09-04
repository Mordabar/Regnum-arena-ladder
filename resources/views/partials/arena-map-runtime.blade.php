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
                            `);
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
