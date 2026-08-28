@props([
    'zoneKey' => null,
    'height' => '400px',
    'interactive' => true,
    'id' => 'arenaZoneMap-' . uniqid(),
])

@once
@push('arena-map-styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<style>
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
</style>
@endpush

@push('arena-map-scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="{{ asset('js/arena-zones.js') }}"></script>
<script>
    window.ArenaMapFactory = window.ArenaMapFactory || {
        create(containerId, options = {}) {
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
</script>
@endpush
@endonce

<div class="arena-map-container" style="height: {{ $height }}">
    <div id="{{ $id }}" style="height: 100%; width: 100%; background-color: #050608;"></div>
</div>

<script>
    (function() {
        const mapId = '{{ $id }}';
        const mapOptions = {
            highlightZone: {!! $zoneKey ? "'" . e($zoneKey) . "'" : 'null' !!},
            interactive: {{ $interactive ? 'true' : 'false' }},
        };

        function initMap() {
            if (!window.ArenaMapFactory || !window.L) return false;
            const el = document.getElementById(mapId);
            if (!el || el.offsetParent === null) return false; // Not visible yet

            const instance = ArenaMapFactory.create(mapId, mapOptions);
            if (instance) {
                // Force recalculate after brief delay (for modals)
                setTimeout(() => instance.invalidateSize(), 200);
            }
            return !!instance;
        }

        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                if (initMap()) return;

                // If not initialized (probably inside a hidden modal), use MutationObserver
                const el = document.getElementById(mapId);
                if (!el) return;

                // Find the closest modal ancestor
                const modal = el.closest('[role="dialog"], [id^="modal-"]');
                if (!modal) {
                    // Retry once more after a longer delay
                    setTimeout(initMap, 500);
                    return;
                }

                // Watch for modal becoming visible
                const observer = new MutationObserver(function(mutations) {
                    for (const m of mutations) {
                        if (m.type === 'attributes' && m.attributeName === 'style') {
                            if (modal.style.display === 'flex' || modal.style.display === 'block') {
                                if (initMap()) {
                                    observer.disconnect();
                                }
                            }
                        }
                    }
                });
                observer.observe(modal, { attributes: true, attributeFilter: ['style'] });

                // Also handle click-triggered opens (backup)
                document.addEventListener('click', function handler(e) {
                    const opener = e.target.closest(`[data-modal-open="${modal.id}"]`);
                    if (opener) {
                        setTimeout(function() {
                            if (initMap()) {
                                document.removeEventListener('click', handler);
                            }
                        }, 150);
                    }
                });
            }, 100);
        });
    })();
</script>

